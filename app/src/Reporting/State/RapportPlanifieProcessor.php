<?php

declare(strict_types=1);

namespace App\Reporting\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Reporting\Entity\DestinataireRapport;
use App\Reporting\Entity\RapportPlanifie;
use App\Reporting\Entity\TableauDeBord;
use App\Reporting\Enum\EtatRapportPlanifie;
use App\Reporting\Enum\FormatExport;
use App\Reporting\Enum\NiveauEntite;
use App\Reporting\Enum\PeriodiciteRapport;
use App\Reporting\Security\PerimetreReporting;
use App\Reporting\Security\PerimetreReportingResolver;
use App\Reporting\Service\LecteurCorps;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Création/modification d'un `RapportPlanifie` (POST/PATCH, §2.3 plan-reporting.md, RG-M7-06/07).
 * Corps JSON manuel (`read: false, input: false`, même patron que `CreerVenteProcessor`) : le
 * contrôle « chaque destinataire ⊆ périmètre `reporting.planifier` du créateur » (422 sinon) dépend
 * d'un calcul ensembliste incompatible avec une simple dénormalisation de collection imbriquée.
 *
 * @implements ProcessorInterface<mixed, RapportPlanifie>
 */
final class RapportPlanifieProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly Security $security,
        private readonly PerimetreReportingResolver $resolver,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RapportPlanifie
    {
        $corps = $this->lecteur->corps();
        $utilisateur = $this->security->getUser();
        \assert($utilisateur instanceof Utilisateur);

        $id = $uriVariables['id'] ?? null;
        if ($id !== null) {
            $rapport = $this->em->getRepository(RapportPlanifie::class)->find($id);
            if (!$rapport instanceof RapportPlanifie) {
                throw new NotFoundHttpException('Rapport planifié introuvable.');
            }
            // `read: false` (§ci-dessus) contourne `PerimetreReportingExtension` (hook Get/GetCollection
            // uniquement) : la modification reste bornée à son créateur, sauf `reporting.configurer`
            // (surensemble borné par M8, même règle que la lecture, §2.2/§4 plan-reporting.md).
            $estCreateur = $rapport->getCreateur() !== null && $rapport->getCreateur()->getId()->equals($utilisateur->getId());
            if (!$estCreateur && !$this->security->isGranted('PERM', 'reporting.configurer')) {
                throw new AccessDeniedHttpException('Modification réservée au créateur du rapport ou à reporting.configurer.');
            }
        } else {
            $rapport = new RapportPlanifie();
            $rapport->setCreateur($utilisateur);
        }

        if (isset($corps['nom'])) {
            $rapport->setNom((string) $corps['nom']);
        }

        if (isset($corps['tableauDeBord'])) {
            $tdbId = $this->uuidDe($corps['tableauDeBord']);
            $tdb = $tdbId !== null ? $this->em->getRepository(TableauDeBord::class)->find($tdbId) : null;
            if (!$tdb instanceof TableauDeBord) {
                throw new UnprocessableEntityHttpException('Tableau de bord introuvable (RG-M7-06).');
            }
            $rapport->setTableauDeBord($tdb);
        } elseif ($id === null) {
            throw new UnprocessableEntityHttpException('tableauDeBord requis (RG-M7-06).');
        }

        // ⚠ `tryFrom`, PAS `from` : `from()` lève une `ValueError`, qui n'est pas une
        // HttpException et remonte donc en 500. Une faute de frappe du client vaut un 422 qui
        // nomme les valeurs acceptées, pas une erreur serveur muette.
        if (isset($corps['format'])) {
            $format = FormatExport::tryFrom((string) $corps['format']);
            if ($format === null) {
                throw new UnprocessableEntityHttpException('format invalide (pdf|xlsx|csv).');
            }
            $rapport->setFormat($format);
        }
        if (isset($corps['periodicite'])) {
            $periodicite = PeriodiciteRapport::tryFrom((string) $corps['periodicite']);
            if ($periodicite === null) {
                throw new UnprocessableEntityHttpException(
                    'periodicite invalide (quotidienne|hebdomadaire|mensuelle).',
                );
            }
            $rapport->setPeriodicite($periodicite);
        }
        // La colonne fait 5 caractères : une chaîne plus longue échoue à l'insertion, ce qui
        // est encore un 500. Et une heure impossible qui tient en 5 caractères se rangerait sans
        // que rien ne le signale — personne ne lit encore ce champ, donc rien ne le démasquerait.
        if (isset($corps['heureEnvoi'])) {
            $heure = (string) $corps['heureEnvoi'];
            if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $heure) !== 1) {
                throw new UnprocessableEntityHttpException('heureEnvoi attendue au format HH:MM.');
            }
            $rapport->setHeureEnvoi($heure);
        }
        if (isset($corps['etat'])) {
            $etat = EtatRapportPlanifie::tryFrom((string) $corps['etat']);
            if ($etat === null) {
                throw new UnprocessableEntityHttpException('etat invalide (actif|suspendu).');
            }
            $rapport->setEtat($etat);
        }

        if (isset($corps['destinataires']) || $id === null) {
            $destinatairesInput = $corps['destinataires'] ?? null;
            if (!\is_array($destinatairesInput) || $destinatairesInput === []) {
                throw new UnprocessableEntityHttpException('destinataires requis (≥1), RG-M7-07.');
            }

            foreach ($rapport->getDestinataires()->toArray() as $ancien) {
                \assert($ancien instanceof DestinataireRapport);
                $rapport->removeDestinataire($ancien);
                $this->em->remove($ancien);
            }

            $perimetreCreateur = $this->resolver->perimetreEffectif($utilisateur, 'planifier');

            foreach ($destinatairesInput as $item) {
                if (!\is_array($item) || !isset($item['email'], $item['niveau'])) {
                    throw new UnprocessableEntityHttpException('Chaque destinataire requiert email + niveau.');
                }
                $niveau = NiveauEntite::tryFrom((string) $item['niveau']);
                if ($niveau === null) {
                    throw new UnprocessableEntityHttpException('niveau destinataire invalide (etablissement|region|groupe).');
                }

                $destinataire = new DestinataireRapport();
                $destinataire->setEmail((string) $item['email']);

                match ($niveau) {
                    NiveauEntite::Etablissement => $this->rattacherEtablissement($destinataire, $item, $perimetreCreateur),
                    NiveauEntite::Region => $this->rattacherRegion($destinataire, $item, $perimetreCreateur),
                    NiveauEntite::Groupe => $this->rattacherGroupe($destinataire, $item, $perimetreCreateur),
                };

                $rapport->addDestinataire($destinataire);
            }
        }

        $this->em->persist($rapport);
        $this->em->flush();

        return $rapport;
    }

    /** @param array<string, mixed> $item */
    private function rattacherEtablissement(DestinataireRapport $destinataire, array $item, PerimetreReporting $perimetreCreateur): void
    {
        $etabId = $this->uuidDe($item['etablissement'] ?? null);
        if ($etabId === null) {
            throw new UnprocessableEntityHttpException('etablissement requis pour un destinataire de niveau établissement.');
        }
        if (!$perimetreCreateur->estAutoriseEtablissement($etabId)) {
            throw new UnprocessableEntityHttpException('Destinataire hors périmètre du créateur (RG-M7-07).');
        }
        $etablissement = $this->em->getRepository(Etablissement::class)->find($etabId);
        if (!$etablissement instanceof Etablissement) {
            throw new UnprocessableEntityHttpException('Établissement introuvable.');
        }
        $destinataire->definirRattachementEtablissement($etablissement);
    }

    /** @param array<string, mixed> $item */
    private function rattacherRegion(DestinataireRapport $destinataire, array $item, PerimetreReporting $perimetreCreateur): void
    {
        $regionId = $this->uuidDe($item['region'] ?? null);
        if ($regionId === null) {
            throw new UnprocessableEntityHttpException('region requise pour un destinataire de niveau région.');
        }
        if (!$perimetreCreateur->estAutoriseRegion($regionId)) {
            throw new UnprocessableEntityHttpException('Destinataire hors périmètre du créateur (RG-M7-07).');
        }
        $region = $this->em->getRepository(Region::class)->find($regionId);
        if (!$region instanceof Region) {
            throw new UnprocessableEntityHttpException('Région introuvable.');
        }
        $destinataire->definirRattachementRegion($region);
    }

    /** @param array<string, mixed> $item */
    private function rattacherGroupe(DestinataireRapport $destinataire, array $item, PerimetreReporting $perimetreCreateur): void
    {
        $groupeId = $this->uuidDe($item['groupe'] ?? null);
        if ($groupeId === null) {
            throw new UnprocessableEntityHttpException('groupe requis pour un destinataire de niveau groupe.');
        }
        if (!$perimetreCreateur->estAutoriseGroupe($groupeId)) {
            throw new UnprocessableEntityHttpException('Destinataire hors périmètre du créateur (RG-M7-07).');
        }
        $groupe = $this->em->getRepository(Groupe::class)->find($groupeId);
        if (!$groupe instanceof Groupe) {
            throw new UnprocessableEntityHttpException('Groupe introuvable.');
        }
        $destinataire->definirRattachementGroupe($groupe);
    }

    private function uuidDe(mixed $reference): ?Uuid
    {
        if (!\is_string($reference) || $reference === '') {
            return null;
        }
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;

        return Uuid::isValid($segment) ? Uuid::fromString($segment) : null;
    }
}
