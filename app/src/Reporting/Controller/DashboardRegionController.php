<?php

declare(strict_types=1);

namespace App\Reporting\Controller;

use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Region;
use App\Reporting\Entity\Mesure;
use App\Reporting\Enum\NiveauEntite;
use App\Reporting\Projection\ProjectionComptaInterface;
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
 * `GET /reporting/dashboards/region/{id}` (M7-02, CA-3, CA-9, §2.4/§2.7/§3 plan-reporting.md) :
 * lecture EXCLUSIVE des `Mesure` pré-agrégées (coût borné indépendamment du nombre de sites),
 * classement des sites, écarts vs objectif/n-1, vues régime isolées non fusionnées (RG-REPORT-09).
 * Badge de fraîcheur explicite (cas limite §7 spec : région non garantie temps réel strict).
 */
#[AsController]
final class DashboardRegionController
{
    private const CODES_AFFICHES = ['CA', 'FREQUENTATION_CUMULEE', 'FMI_MAX_SOMME_SITES', 'FMI_MAX_SITE_CRITIQUE', 'TAUX_REMPLISSAGE', 'NO_SHOW', 'IMPAYES', 'FOND_CAISSE'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly PerimetreReportingResolver $resolver,
        private readonly MesureLookupService $lookup,
        private readonly CalculComparaisonService $comparaison,
        private readonly ProjectionComptaInterface $projectionCompta,
    ) {
    }

    #[Route('/reporting/dashboards/region/{id}', name: 'reporting_dashboard_region', methods: ['GET'])]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        if (!$this->security->isGranted('PERM', 'reporting.lire')) {
            throw new AccessDeniedHttpException('reporting.lire requis.');
        }
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Région introuvable.');
        }
        $regionId = Uuid::fromString($id);

        $utilisateur = $this->security->getUser();
        \assert($utilisateur instanceof Utilisateur);
        $perimetre = $this->resolver->perimetreEffectif($utilisateur, 'lire');
        if (!$perimetre->estAutoriseRegion($regionId)) {
            throw new AccessDeniedHttpException('Région non entièrement couverte par le périmètre (RG-M7-01, CA-1).');
        }

        $region = $this->em->getRepository(Region::class)->find($regionId);
        if (!$region instanceof Region) {
            throw new NotFoundHttpException('Région introuvable.');
        }

        $periode = $this->periodeDepuisRequete($request);

        $indicateurs = [];
        $genereLeMin = null;
        foreach (self::CODES_AFFICHES as $code) {
            $mesure = $this->lookup->trouver($code, NiveauEntite::Region, $regionId, $periode);
            if ($mesure === null) {
                continue;
            }
            $entree = $this->mesureVersPayload($mesure);
            if ($code === 'FMI_MAX_SITE_CRITIQUE') {
                $critique = $this->lookup->etablissementCritique('FMI_MAX', NiveauEntite::Region, $regionId, $periode);
                $entree['etablissementCritique'] = $critique?->getEtablissement() !== null ? [
                    'id' => (string) $critique->getEtablissement()->getId(),
                    'nom' => $critique->getEtablissement()->getNom(),
                ] : null;
            }
            $indicateurs[$code] = $entree;
            $genereLeMin = $genereLeMin === null ? $mesure->getGenereLe() : min($genereLeMin, $mesure->getGenereLe());
        }

        $sites = [];
        foreach ($region->getEtablissements() as $etablissement) {
            \assert($etablissement instanceof Etablissement);
            $ca = $this->lookup->trouver('CA', NiveauEntite::Etablissement, $etablissement->getId(), $periode);
            $entrees = $this->lookup->trouver('FREQUENTATION_CUMULEE', NiveauEntite::Etablissement, $etablissement->getId(), $periode);
            $sites[] = [
                'etablissementId' => (string) $etablissement->getId(),
                'nom' => $etablissement->getNom(),
                'ca' => $ca?->getValeur(),
                'entrees' => $entrees?->getValeur(),
                'statutCompletude' => $ca?->getStatutCompletude()->value,
            ];
        }
        usort($sites, static fn (array $a, array $b): int => (float) ($b['ca'] ?? 0) <=> (float) ($a['ca'] ?? 0));

        $ecartCaVsN1 = null;
        $ecartCaVsObjectif = null;
        $caRegion = $this->lookup->trouver('CA', NiveauEntite::Region, $regionId, $periode);
        if ($caRegion instanceof Mesure) {
            $ecartCaVsN1 = $this->comparaison->ecartVsN1($caRegion);
            $ecartCaVsObjectif = $this->comparaison->ecartVsObjectif($caRegion->getIndicateur() ?? throw new \LogicException(), NiveauEntite::Region, $regionId, $caRegion);
        }

        $vuesRegimeIsolees = [];
        if ($caRegion instanceof Mesure && $caRegion->isComparabiliteRegime()) {
            foreach ($region->getEtablissements() as $etablissement) {
                \assert($etablissement instanceof Etablissement);
                $vuesRegimeIsolees[] = [
                    'etablissementId' => (string) $etablissement->getId(),
                    'nom' => $etablissement->getNom(),
                ] + $this->projectionCompta->syntheseRegimeIsolee($etablissement->getId());
            }
        }

        return new JsonResponse([
            'regionId' => (string) $regionId,
            'regionNom' => $region->getNom(),
            'fraicheur' => $genereLeMin !== null ? [
                'genereLe' => $genereLeMin->format(DATE_ATOM),
                'ilYAMinutes' => (int) round((time() - $genereLeMin->getTimestamp()) / 60),
            ] : null,
            'indicateurs' => $indicateurs,
            'sites' => $sites,
            'ecartCaVsObjectif' => $ecartCaVsObjectif,
            'ecartCaVsN1' => $ecartCaVsN1,
            'comparabiliteRegime' => $caRegion?->isComparabiliteRegime() ?? false,
            'vuesRegimeIsolees' => $vuesRegimeIsolees,
        ]);
    }

    /** @return array<string, mixed> */
    private function mesureVersPayload(Mesure $mesure): array
    {
        return [
            'valeur' => $mesure->getValeur(),
            'statutCompletude' => $mesure->getStatutCompletude()->value,
            'sitesManquants' => $mesure->getSitesManquants(),
            'comparabiliteRegime' => $mesure->isComparabiliteRegime(),
            'regimeExploitant' => $mesure->getRegimeExploitant()?->value,
            'genereLe' => $mesure->getGenereLe()->format(DATE_ATOM),
            'libelle' => $mesure->getIndicateur()?->getLibelle(),
        ];
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
