<?php

declare(strict_types=1);

namespace App\Reporting\Controller;

use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Reporting\Entity\Mesure;
use App\Reporting\Enum\NiveauEntite;
use App\Reporting\Security\PerimetreReportingResolver;
use App\Reporting\Service\CalculComparaisonService;
use App\Reporting\Service\MesureLookupService;
use App\Reporting\ValueObject\Periode;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /reporting/dashboards/groupe/{id}` (M7-03, CA-4, §2.4/§3 plan-reporting.md) : consolidé
 * groupe, mêmes axes que M7-01/M7-02 (RG-M7-05) — drill-down région → site (`sites` livré par la
 * même route région ci-après) SANS changer d'axes. Tendances/saisonnalité/benchmarks : hors
 * périmètre de ce lot (non chiffrés par le cahier, ⚠ HYPOTHÈSE documentée rapport final) — seul le
 * consolidé instantané est exposé ici, base suffisante pour un futur enrichissement de séries.
 */
#[AsController]
final class DashboardGroupeController
{
    private const CODES_AFFICHES = ['CA', 'FREQUENTATION_CUMULEE', 'FMI_MAX_SOMME_SITES', 'FMI_MAX_SITE_CRITIQUE', 'TAUX_REMPLISSAGE', 'NO_SHOW', 'IMPAYES', 'FOND_CAISSE'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly PerimetreReportingResolver $resolver,
        private readonly MesureLookupService $lookup,
        private readonly CalculComparaisonService $comparaison,
    ) {
    }

    #[Route('/reporting/dashboards/groupe/{id}', name: 'reporting_dashboard_groupe', methods: ['GET'])]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        if (!$this->security->isGranted('PERM', 'reporting.lire')) {
            throw new AccessDeniedHttpException('reporting.lire requis.');
        }
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Groupe introuvable.');
        }
        $groupeId = Uuid::fromString($id);

        $utilisateur = $this->security->getUser();
        \assert($utilisateur instanceof Utilisateur);
        $perimetre = $this->resolver->perimetreEffectif($utilisateur, 'lire');
        if (!$perimetre->estAutoriseGroupe($groupeId)) {
            throw new AccessDeniedHttpException('Groupe non entièrement couvert par le périmètre (RG-M7-01, CA-1).');
        }

        $groupe = $this->em->getRepository(Groupe::class)->find($groupeId);
        if (!$groupe instanceof Groupe) {
            throw new NotFoundHttpException('Groupe introuvable.');
        }

        $periode = $this->periodeDepuisRequete($request);

        $indicateurs = [];
        $genereLeMin = null;
        foreach (self::CODES_AFFICHES as $code) {
            $mesure = $this->lookup->trouver($code, NiveauEntite::Groupe, $groupeId, $periode);
            if ($mesure === null) {
                continue;
            }
            $indicateurs[$code] = [
                'valeur' => $mesure->getValeur(),
                'statutCompletude' => $mesure->getStatutCompletude()->value,
                'sitesManquants' => $mesure->getSitesManquants(),
                'comparabiliteRegime' => $mesure->isComparabiliteRegime(),
                'regimeExploitant' => $mesure->getRegimeExploitant()?->value,
                'genereLe' => $mesure->getGenereLe()->format(DATE_ATOM),
                'libelle' => $mesure->getIndicateur()?->getLibelle(),
            ];
            $genereLeMin = $genereLeMin === null ? $mesure->getGenereLe() : min($genereLeMin, $mesure->getGenereLe());
        }

        $regions = [];
        foreach ($groupe->getRegions() as $region) {
            \assert($region instanceof Region);
            $ca = $this->lookup->trouver('CA', NiveauEntite::Region, $region->getId(), $periode);
            $regions[] = [
                'regionId' => (string) $region->getId(),
                'nom' => $region->getNom(),
                'ca' => $ca?->getValeur(),
                'statutCompletude' => $ca?->getStatutCompletude()->value,
            ];
        }
        usort($regions, static fn (array $a, array $b): int => (float) ($b['ca'] ?? 0) <=> (float) ($a['ca'] ?? 0));

        $ecartCaVsN1 = null;
        $caGroupe = $this->lookup->trouver('CA', NiveauEntite::Groupe, $groupeId, $periode);
        if ($caGroupe instanceof Mesure) {
            $ecartCaVsN1 = $this->comparaison->ecartVsN1($caGroupe);
        }

        return new JsonResponse([
            'groupeId' => (string) $groupeId,
            'groupeNom' => $groupe->getNom(),
            'fraicheur' => $genereLeMin !== null ? [
                'genereLe' => $genereLeMin->format(DATE_ATOM),
                'ilYAMinutes' => (int) round((time() - $genereLeMin->getTimestamp()) / 60),
            ] : null,
            'indicateurs' => $indicateurs,
            'regions' => $regions,
            'ecartCaVsN1' => $ecartCaVsN1,
            'comparabiliteRegime' => $caGroupe?->isComparabiliteRegime() ?? false,
        ]);
    }

    private function periodeDepuisRequete(Request $request): Periode
    {
        $debut = $request->query->get('periodeDebut');
        $fin = $request->query->get('periodeFin');
        if (\is_string($debut) && \is_string($fin)) {
            return Periode::depuisDates(new \DateTimeImmutable($debut), new \DateTimeImmutable($fin), \App\Reporting\Enum\GranulariteMesure::Jour);
        }

        return Periode::jour();
    }
}
