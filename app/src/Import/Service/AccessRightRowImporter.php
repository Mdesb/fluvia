<?php

declare(strict_types=1);

namespace App\Import\Service;

use App\Acces\Entity\Appairage;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Support;
use App\Acces\Enum\ModeAppairage;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Enum\TypeSupport;
use App\Acces\Service\ProductAccessZoneResolver;
use App\Import\Dto\ParsedImportRow;
use App\Import\Entity\ImportBatch;
use App\Import\Entity\ImportedEntityRef;
use App\Import\Enum\ImportType;
use App\Import\Port\ImportFileParserInterface;
use App\Import\Port\RowImporterInterface;
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Component\Uid\Uuid;

/**
 * Reprise d'un TITRE D'ACCÈS en cours : abonnement (`subscribers`) ou carte à crédit d'entrées
 * (`card_credits`) — #348, SPEC-REPRISE-INITIALE.md §4.
 *
 * Une ligne du fichier devient ce qu'une vente aurait produit : une carte (`Support`), un droit
 * d'accès (`DroitAcces`, avec sa fenêtre et son crédit) et l'appairage qui les lie. Le porteur
 * présente sa carte au tourniquet le lendemain de la reprise, et elle s'ouvre.
 *
 * ── CE QUI EST REFUSÉ, ET POURQUOI ─────────────────────────────────────────────────────────────
 *
 *  - **Un produit qui n'ouvre aucune porte de l'établissement.** Un droit sans zone déclarée n'ouvre
 *    rien (D87) : la reprise « réussirait », et la carte serait refusée au tourniquet — devant la
 *    personne. Le produit doit donc exister dans CET établissement ET y déclarer au moins une zone.
 *  - **Un numéro de carte déjà connu de Fluvia**, quel que soit l'établissement : l'identifiant
 *    d'un support est unique dans tout le dépôt. Réutiliser une carte existante rattacherait la
 *    reprise à un objet qu'elle n'a pas créé — et que l'annulation ne saurait pas défaire. Le message
 *    ne dit pas OÙ la carte est connue : un import n'est pas une sonde sur les voisins.
 *  - **Une référence d'origine déjà reprise** dans cet établissement pour ce type : rejouer un fichier
 *    ne double jamais une dette.
 *
 * ── CE QUE LA REPRISE NE FAIT PAS ──────────────────────────────────────────────────────────────
 *
 * Elle ne rattache pas la carte à une fiche client : un `DroitAcces` ne porte aucun porteur (CQ-0
 * n'est pas fait). La référence d'origine reste sur le droit, pour un rattachement ultérieur.
 *
 * ── CLOISONNEMENT ──────────────────────────────────────────────────────────────────────────────
 *
 * L'établissement est celui du LOT, estampillé au serveur (D41) : aucune colonne du fichier ne
 * désigne où écrire. Le produit est cherché parmi ceux de cet établissement seulement.
 */
abstract class AccessRightRowImporter implements RowImporterInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $em,
        protected readonly ProductAccessZoneResolver $zones,
        protected readonly ImportFileParserInterface $parser,
    ) {
    }

    /** Type de droit produit par ce type d'import. */
    abstract protected function rightType(): TypeDroitAcces;

    /**
     * Contrôles propres au type, sur une ligne déjà reconnue (référence, carte, produit).
     *
     * @return string|null le message d'erreur, ou null si la ligne est bonne
     */
    abstract protected function validateSpecific(ParsedImportRow $row, Produit $produit, \DateTimeImmutable $today): ?string;

    /** Pose la fenêtre et le crédit du droit à partir de la ligne. */
    abstract protected function hydrateRight(DroitAcces $droit, ParsedImportRow $row, \DateTimeImmutable $today): void;

    /**
     * Contrôles portant sur le fichier entier (total annoncé…).
     *
     * @param list<ParsedImportRow> $rows
     *
     * @return array<int, string>
     */
    protected function validateFile(array $rows, ?ImportBatch $batch): array
    {
        return [];
    }

    public function validate(array $rows, Etablissement $establishment, ?ImportBatch $batch = null): array
    {
        $today = new \DateTimeImmutable('today');
        $refs = $this->occurrences($rows, 'externalref');
        $cartes = $this->occurrences($rows, 'supportidentifiant');

        $errors = $this->validateFile($rows, $batch);
        foreach ($rows as $row) {
            $ligne = $row->lineNumber;
            $ref = $this->col($row, 'externalref');
            $carte = $this->col($row, 'supportidentifiant');

            $message = match (true) {
                $ref === '' => 'externalRef est obligatoire : la référence du titre dans l\'ancien logiciel.',
                ($refs[$ref] ?? 0) > 1 => sprintf('externalRef « %s » est dupliqué dans ce fichier.', $ref),
                $this->alreadyImported($establishment, $ref) => sprintf('externalRef « %s » a déjà été repris dans cet établissement : rejouer un fichier ne double pas un titre.', $ref),
                $carte === '' => 'supportIdentifiant est obligatoire : le numéro de la carte que la personne présente.',
                ($cartes[$carte] ?? 0) > 1 => sprintf('supportIdentifiant « %s » est dupliqué dans ce fichier.', $carte),
                $this->supportExists($carte) => sprintf('Le numéro de carte « %s » existe déjà dans Fluvia : une carte reprise doit être nouvelle.', $carte),
                $this->supportType($row) === false => 'typeSupport doit valoir « RFID » ou « QR » (ou rester vide : RFID).',
                default => null,
            };
            if ($message === null) {
                $produit = $this->product($row, $establishment);
                $message = match (true) {
                    $produit === null => sprintf('Produit « %s » inconnu dans cet établissement (colonne produit : le code du produit Fluvia).', $this->col($row, 'produit')),
                    $this->zones->spacesFor($produit->getId(), $establishment) === [] => sprintf('Le produit « %s » n\'ouvre aucune porte de cet établissement : déclarez ses zones d\'accès avant la reprise, sinon la carte serait refusée au tourniquet.', $produit->getCode()),
                    default => $this->validateSpecific($row, $produit, $today),
                };
            }
            if ($message !== null) {
                $errors[$ligne] = $message;
            }
        }

        return $errors;
    }

    public function apply(array $rows, ImportBatch $batch): int
    {
        $establishment = $batch->getEstablishment();
        \assert($establishment instanceof Etablissement);
        $today = new \DateTimeImmutable('today');
        $maintenant = new \DateTimeImmutable();

        $created = 0;
        foreach ($rows as $row) {
            $produit = $this->product($row, $establishment);
            $ref = $this->col($row, 'externalref');
            if ($produit === null || $ref === '') {
                continue; // Impossible sur un lot validé : garde défensive.
            }

            $support = (new Support())
                ->setIdentifiant($this->col($row, 'supportidentifiant'))
                ->setType($this->supportType($row) ?: TypeSupport::Rfid)
                ->setEtablissement($establishment);
            $this->em->persist($support);

            $droit = (new DroitAcces())
                ->setSourceType($this->rightType())
                ->setProduitRef($produit->getId())
                ->setStatutProjection(StatutProjectionDroit::Valide)
                ->setEtablissement($establishment)
                ->setExternalRef($ref)
                ->setImportBatchRef($batch->getId())
                ->setSynchroniseLe($maintenant);
            $this->hydrateRight($droit, $row, $today);
            $this->zones->applyTo($droit, $produit->getId(), $establishment);
            $this->em->persist($droit);

            $this->em->persist((new Appairage())
                ->setSupport($support)
                ->setDroit($droit)
                ->setMode(ModeAppairage::Caisse)
                ->setActif(true)
                ->setEtablissement($establishment));

            $this->em->persist(new ImportedEntityRef($establishment, $this->type(), $ref, $droit->getId(), $batch->getId()));
            ++$created;
        }

        return $created;
    }

    public function countCreated(Uuid $batchId): int
    {
        return \count($this->rightsOf($batchId));
    }

    /**
     * Un titre repris a-t-il servi ? Toute entité qui le référence — sauf l'appairage que la reprise
     * a elle-même posé — compte comme un emploi (un passage, d'abord). Le scan porte sur le MAPPING,
     * pas sur une liste écrite à la main : un module qui ajoute demain une relation vers `DroitAcces`
     * est couvert sans que personne y pense. Les types qui portent un crédit ajoutent leur contrôle.
     */
    public function isReferenced(Uuid $batchId): bool
    {
        foreach ($this->rightsOf($batchId) as $droit) {
            foreach ($this->em->getMetadataFactory()->getAllMetadata() as $metadata) {
                if (!$metadata instanceof ClassMetadata || $metadata->isMappedSuperclass || $metadata->getName() === Appairage::class) {
                    continue;
                }
                foreach ($metadata->getAssociationMappings() as $champ => $mapping) {
                    if (($mapping['targetEntity'] ?? null) !== DroitAcces::class || ($mapping['isOwningSide'] ?? false) !== true) {
                        continue;
                    }
                    if (!\in_array($mapping['type'] ?? null, [ClassMetadata::MANY_TO_ONE, ClassMetadata::ONE_TO_ONE], true)) {
                        continue;
                    }
                    $compte = (int) $this->em->createQueryBuilder()
                        ->select('COUNT(r.id)')
                        ->from($metadata->getName(), 'r')
                        ->andWhere(sprintf('IDENTITY(r.%s) = :droit', $champ))
                        ->setParameter('droit', $droit->getId(), 'uuid')
                        ->getQuery()
                        ->getSingleScalarResult();
                    if ($compte > 0) {
                        return true;
                    }
                }
            }
        }

        return $this->usedSinceImport($batchId);
    }

    /** Contrôle d'emploi propre au type (crédit entamé…). */
    protected function usedSinceImport(Uuid $batchId): bool
    {
        return false;
    }

    /**
     * Défait exactement ce que le lot a créé : appairages, droits, correspondances, et les cartes —
     * toutes créées par le lot, puisqu'une carte déjà connue est refusée à la validation.
     */
    public function revert(Uuid $batchId): void
    {
        foreach ($this->rightsOf($batchId) as $droit) {
            foreach ($this->em->getRepository(Appairage::class)->findBy(['droit' => $droit]) as $appairage) {
                $support = $appairage->getSupport();
                $this->em->remove($appairage);
                if ($support instanceof Support) {
                    $this->em->remove($support);
                }
            }
            $ref = $this->em->getRepository(ImportedEntityRef::class)->findOneBy(['type' => $this->type(), 'targetId' => $droit->getId()]);
            if ($ref !== null) {
                $this->em->remove($ref);
            }
            $this->em->remove($droit);
        }
        // Les appairages et supports d'abord : un droit encore désigné violerait la clé étrangère.
        $this->em->flush();
    }

    /** @return list<DroitAcces> */
    protected function rightsOf(Uuid $batchId): array
    {
        /** @var list<DroitAcces> $droits */
        $droits = $this->em->getRepository(DroitAcces::class)->findBy(['importBatchRef' => $batchId]);

        return $droits;
    }

    protected function col(ParsedImportRow $row, string $colonne): string
    {
        return trim($row->columns[$colonne] ?? '');
    }

    /** Date `AAAA-MM-JJ` ou `JJ/MM/AAAA` ; null si vide, false si illisible. */
    protected function date(ParsedImportRow $row, string $colonne): \DateTimeImmutable|false|null
    {
        $brut = $this->col($row, $colonne);
        if ($brut === '') {
            return null;
        }
        foreach (['!Y-m-d', '!d/m/Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $brut);
            if ($date instanceof \DateTimeImmutable && $date->format(ltrim($format, '!')) === $brut) {
                return $date;
            }
        }

        return false;
    }

    private function product(ParsedImportRow $row, Etablissement $establishment): ?Produit
    {
        $code = $this->col($row, 'produit');
        if ($code === '') {
            return null;
        }
        $produit = $this->em->getRepository(Produit::class)->findOneBy(['code' => $code]);
        if (!$produit instanceof Produit) {
            return null;
        }
        foreach ($produit->getEtablissements() as $site) {
            if ($site->getId()->equals($establishment->getId())) {
                return $produit;
            }
        }

        return null; // Produit d'un autre établissement : inconnu ici, sans dire qu'il existe ailleurs.
    }

    private function supportType(ParsedImportRow $row): TypeSupport|false|null
    {
        return match (strtoupper($this->col($row, 'typesupport'))) {
            '' => null,
            'RFID' => TypeSupport::Rfid,
            'QR' => TypeSupport::Qr,
            default => false,
        };
    }

    private function supportExists(string $identifiant): bool
    {
        return $this->em->getRepository(Support::class)->count(['identifiant' => $identifiant]) > 0;
    }

    private function alreadyImported(Etablissement $establishment, string $ref): bool
    {
        return $this->em->getRepository(ImportedEntityRef::class)->count([
            'establishment' => $establishment->getId(),
            'type' => $this->type(),
            'externalRef' => $ref,
        ]) > 0;
    }

    /**
     * @param list<ParsedImportRow> $rows
     *
     * @return array<string, int>
     */
    private function occurrences(array $rows, string $colonne): array
    {
        $vus = [];
        foreach ($rows as $row) {
            $valeur = $this->col($row, $colonne);
            if ($valeur !== '') {
                $vus[$valeur] = ($vus[$valeur] ?? 0) + 1;
            }
        }

        return $vus;
    }
}
