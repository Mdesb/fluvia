<?php

declare(strict_types=1);

namespace App\Import\Handler;

use App\Acces\Entity\Appairage;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Support;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Enum\TypeSupport;
use App\Import\Entity\ImportBatch;
use App\Import\Enum\ImportType;
use App\Import\Port\ImportTypeHandler;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Reprise du type `card_credits` — **le seul type de cette spécification qui porte de l'argent**.
 *
 * ── CE QU'ON MANIPULE ICI ───────────────────────────────────────────────────────────────────────
 *
 * Un crédit restant est une **dette envers le client** : il a payé dix entrées, en a consommé
 * quatre, on lui en doit six. Une erreur ne se voit pas à la reprise — elle se voit au guichet,
 * six semaines plus tard, devant la personne à qui il manque des entrées. C'est pour ça que ce type
 * a été livré seul, après le socle, et pas en même temps.
 *
 * ── LE RAPPROCHEMENT N'EST PAS UNE OPTION ───────────────────────────────────────────────────────
 *
 * Rien dans le fichier ne permet de vérifier qu'il est complet : un export tronqué ressemble à un
 * export court. Il faut donc que le client **annonce** un total, et qu'un écart — même d'une unité —
 * refuse le lot. **On ne devine pas une dette.**
 *
 * ── CE QU'ON NE PEUT PAS ENCORE FAIRE, ET IL FAUT LE DIRE ───────────────────────────────────────
 *
 * ⚠ **La carte reprise n'est rattachée à personne.** Aujourd'hui `DroitAcces` ne porte aucun
 * porteur — ni `Support` ni `Appairage` non plus : une carte est un objet **au porteur**, qu'on
 * présente. C'est le modèle actuel du produit, pas un raccourci de la reprise, et `CQ-0` (rattacher
 * un droit à un porteur) n'est pas fait.
 *
 * Conséquence à connaître : après reprise, « à qui appartient cette carte » se lit sur le support
 * physique que la personne présente, comme avant la reprise. La référence d'origine est conservée
 * sur le droit, donc le rattachement pourra être fait le jour où `CQ-0` existe, sans réimporter.
 */
final class CardCreditImportHandler implements ImportTypeHandler
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function supports(): ImportType
    {
        return ImportType::CardCredits;
    }

    /** @return list<string> */
    public function requiredColumns(): array
    {
        return ['supportIdentifiant', 'creditRestant'];
    }

    /**
     * Le total repris doit égaler le total annoncé par le client. Exactement.
     *
     * @param list<array{line: int, data: array<string, string>}> $rows
     *
     * @return array<int, string>
     */
    public function validateFile(array $rows, ImportBatch $batch): array
    {
        $annonce = $batch->getAnnouncedTotal();
        if ($annonce === null) {
            return [0 => 'Champ « announcedTotal » obligatoire pour une reprise de crédits : le total d\'entrées restantes annoncé par le client. On ne devine pas une dette.'];
        }

        $somme = 0;
        foreach ($rows as $ligne) {
            $valeur = $ligne['data']['creditRestant'] ?? '';
            if (ctype_digit($valeur)) {
                $somme += (int) $valeur;
            }
        }

        if ($somme !== $annonce) {
            return [0 => sprintf(
                'Le fichier totalise %d entrée(s) restante(s), le client en annonce %d — écart de %d. '
                . 'Un écart, même d\'une unité, refuse le lot : soit l\'export est incomplet, soit le '
                . 'total annoncé est faux, et les deux se corrigent avant d\'écrire, pas après.',
                $somme,
                $annonce,
                abs($somme - $annonce),
            )];
        }

        return [];
    }

    /**
     * @param array<string, string> $row
     *
     * @return list<string>
     */
    public function validateRow(array $row): array
    {
        $fautes = [];

        if (($row['supportIdentifiant'] ?? '') === '') {
            $fautes[] = 'L\'identifiant du support (le numéro de carte) est obligatoire : sans lui, le crédit n\'est présentable nulle part.';
        }

        $credit = $row['creditRestant'] ?? '';
        if (!ctype_digit($credit)) {
            $fautes[] = sprintf('Crédit restant illisible : « %s » (attendu un nombre entier d\'entrées).', $credit);
        } elseif ((int) $credit <= 0) {
            // Une carte à zéro n'est pas une dette : la reprendre encombrerait le fichier client
            // d'objets sans effet, et masquerait les vraies.
            $fautes[] = 'Crédit restant nul : une carte épuisée n\'a pas à être reprise.';
        }

        $fin = $row['validUntil'] ?? '';
        if ($fin !== '' && $this->date($fin) === null) {
            $fautes[] = sprintf('Date de validité illisible : « %s » (attendu AAAA-MM-JJ ou JJ/MM/AAAA).', $fin);
        }

        return $fautes;
    }

    /** @param array<string, string> $row */
    public function create(array $row, Etablissement $establishment, ImportBatch $batch): object
    {
        // Le support peut déjà exister — son identifiant est unique dans tout le dépôt. On le
        // réutilise plutôt que d'échouer : deux cartes ne portent pas le même numéro, donc un
        // identifiant connu désigne la même carte physique.
        $support = $this->em->getRepository(Support::class)->findOneBy(['identifiant' => $row['supportIdentifiant']])
            ?? (new Support())
                ->setIdentifiant($row['supportIdentifiant'])
                ->setType(TypeSupport::Wallet)
                ->setEtablissement($establishment);
        $this->em->persist($support);

        $droit = new DroitAcces();
        $droit
            ->setSourceType(TypeDroitAcces::CarteQuota)
            ->setCreditRestant((int) $row['creditRestant'])
            ->setStatutProjection(StatutProjectionDroit::Valide)
            ->setEtablissement($establishment)
            ->setExternalRef($row['externalRef'])
            ->setImportBatchRef($batch->getId());

        $fin = $row['validUntil'] ?? '';
        if ($fin !== '') {
            $droit->setFenetreFin($this->date($fin));
        }
        $this->em->persist($droit);

        $this->em->persist(
            (new Appairage())
                ->setSupport($support)
                ->setDroit($droit)
                ->setEtablissement($establishment)
        );

        return $droit;
    }

    /**
     * Tout ce que ce lot a créé, **dans l'ordre où ça se supprime**.
     *
     * ⚠ Les appairages d'abord, les droits ensuite. Supprimer un droit dont l'appairage pointe
     * encore dessus violerait la clé étrangère, et le message rendu serait une erreur d'intégrité —
     * illisible pour qui voulait seulement défaire un import.
     *
     * Le support n'est **pas** supprimé : une carte physique existe indépendamment de sa reprise, et
     * elle a pu servir à autre chose. On défait ce qu'on a écrit, pas ce qu'on a rencontré.
     *
     * @return list<object>
     */
    public function createdBy(ImportBatch $batch): array
    {
        /** @var list<DroitAcces> $droits */
        $droits = $this->em->getRepository(DroitAcces::class)->findBy(['importBatchRef' => $batch->getId()]);
        if ($droits === []) {
            return [];
        }

        /** @var list<Appairage> $appairages */
        $appairages = $this->em->getRepository(Appairage::class)->findBy(['droit' => $droits]);

        return array_merge($appairages, $droits);
    }

    /**
     * Ce crédit a-t-il servi depuis sa reprise ?
     *
     * Même méthode que pour les clients : on interroge **le mapping**, pas une liste écrite à la
     * main — une liste ne protège que ce qu'on a pensé à y écrire, et l'annulation supprimerait en
     * silence un crédit déjà entamé.
     *
     * ⚠ **Une seule exclusion, et elle est écrite parce qu'elle doit se justifier** : `Appairage`.
     * C'est cette reprise elle-même qui le crée, en liant la carte à son support. Le compter comme
     * un emploi rendrait tout lot de crédits inannulable dès la seconde qui suit son application —
     * l'annulation deviendrait une promesse qu'on ne tient jamais.
     */
    public function hasBeenUsed(object $created): bool
    {
        if (!$created instanceof DroitAcces) {
            return false;
        }

        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $metadata) {
            if (!$metadata instanceof ClassMetadata || $metadata->isMappedSuperclass) {
                continue;
            }
            if ($metadata->getName() === Appairage::class) {
                continue;
            }

            foreach ($metadata->getAssociationMappings() as $champ => $mapping) {
                if (($mapping['targetEntity'] ?? null) !== DroitAcces::class) {
                    continue;
                }
                if (($mapping['isOwningSide'] ?? false) !== true) {
                    continue;
                }

                $compte = (int) $this->em->createQueryBuilder()
                    ->select('COUNT(r.id)')
                    ->from($metadata->getName(), 'r')
                    ->andWhere(sprintf('IDENTITY(r.%s) = :droit', $champ))
                    ->setParameter('droit', $created->getId(), 'uuid')
                    ->getQuery()
                    ->getSingleScalarResult();

                if ($compte > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private function date(string $valeur): ?\DateTimeImmutable
    {
        foreach (['Y-m-d', 'd/m/Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $valeur);
            if ($date instanceof \DateTimeImmutable && $date->format($format) === $valeur) {
                return $date->setTime(23, 59, 59);
            }
        }

        return null;
    }
}
