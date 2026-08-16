<?php

declare(strict_types=1);

namespace App\Reporting\Controller;

use App\Reporting\Entity\Mesure;
use App\Reporting\Enum\GranulariteMesure;
use App\Reporting\Enum\ModeCalculIndicateur;
use App\Reporting\Enum\NiveauEntite;
use App\Reporting\Security\PerimetreReportingResolver;
use App\Reporting\Service\MesureLookupService;
use App\Reporting\ValueObject\Periode;
use App\Securite\Entity\Utilisateur;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /reporting/explorateur` (M7-04, CA-5, §2.7/§3 plan-reporting.md) : combinaison libre
 * indicateur × site/région/groupe × période, filtre `regimeExploitant` optionnel (cas limite §7
 * spec). Lit `MesureLookupService` — LE MÊME point d'entrée que les dashboards région/groupe pour
 * un triplet indicateur × niveau × entité, garantissant la cohérence stricte imposée par CA-5.
 *
 * ⚠ Simplification MVP documentée (rapport final) : les axes activité/produit/catégorie/canal ne
 * sont pas encore pré-agrégés par `reporting:agreger` (`Mesure.activite/produit/categorie/canal`
 * toujours nuls dans ce lot) — seule la combinaison site/région/groupe × période est exploitable
 * ici ; le schéma porte déjà les colonnes pour une extension ultérieure sans migration.
 */
#[AsController]
final class ExplorateurController
{
    public function __construct(
        private readonly Security $security,
        private readonly PerimetreReportingResolver $resolver,
        private readonly MesureLookupService $lookup,
    ) {
    }

    #[Route('/reporting/explorateur', name: 'reporting_explorateur', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->security->isGranted('PERM', 'reporting.lire')) {
            throw new AccessDeniedHttpException('reporting.lire requis.');
        }

        $indicateurCode = $request->query->get('indicateur');
        $niveauParam = $request->query->get('niveau');
        $entiteIdParam = $request->query->get('entiteId');
        if (!\is_string($indicateurCode) || !\is_string($niveauParam) || !\is_string($entiteIdParam) || !Uuid::isValid($entiteIdParam)) {
            throw new UnprocessableEntityHttpException('Paramètres requis : indicateur, niveau, entiteId.');
        }
        $niveau = NiveauEntite::tryFrom($niveauParam);
        if ($niveau === null) {
            throw new UnprocessableEntityHttpException('niveau invalide (etablissement|region|groupe).');
        }
        $entiteId = Uuid::fromString($entiteIdParam);

        $utilisateur = $this->security->getUser();
        \assert($utilisateur instanceof Utilisateur);
        $perimetre = $this->resolver->perimetreEffectif($utilisateur, 'lire');
        $autorise = match ($niveau) {
            NiveauEntite::Etablissement => $perimetre->estAutoriseEtablissement($entiteId),
            NiveauEntite::Region => $perimetre->estAutoriseRegion($entiteId),
            NiveauEntite::Groupe => $perimetre->estAutoriseGroupe($entiteId),
        };
        if (!$autorise) {
            throw new AccessDeniedHttpException('Entité hors périmètre (RG-M7-01).');
        }

        $periode = $this->periodeDepuisRequete($request);
        $regime = $request->query->get('regimeExploitant');
        $regime = \is_string($regime) ? $regime : null;

        if ($niveau === NiveauEntite::Etablissement || $regime === null) {
            $mesure = $this->lookup->trouver($indicateurCode, $niveau, $entiteId, $periode);
            if ($mesure === null) {
                throw new NotFoundHttpException('Aucune mesure pour cette combinaison — relancer reporting:agreger.');
            }

            return new JsonResponse($this->payload($mesure));
        }

        // Filtre par régime (cas limite §7 spec) : recomposition à la volée à partir des `Mesure`
        // établissement sous-jacentes du même niveau/indicateur/période (source unique, RG-M7-02).
        $indicateur = $this->lookup->indicateurParCode($indicateurCode);
        if ($indicateur === null) {
            throw new NotFoundHttpException('Indicateur introuvable.');
        }
        $sources = $this->lookup->sourcesEtablissement($indicateurCode, $niveau, $entiteId, $periode, $regime);
        if ($sources === []) {
            throw new NotFoundHttpException('Aucune mesure pour ce régime — relancer reporting:agreger.');
        }
        $valeurs = array_map(static fn (Mesure $m): float => (float) $m->getValeur(), $sources);
        $valeur = match ($indicateur->getModeCalcul()) {
            ModeCalculIndicateur::Somme => array_sum($valeurs),
            ModeCalculIndicateur::Max => max($valeurs),
            ModeCalculIndicateur::Moyenne, ModeCalculIndicateur::Ratio => array_sum($valeurs) / \count($valeurs),
        };

        return new JsonResponse([
            'indicateur' => $indicateurCode,
            'niveau' => $niveau->value,
            'entiteId' => (string) $entiteId,
            'valeur' => number_format($valeur, 2, '.', ''),
            'regimeExploitant' => $regime,
            'nombreSites' => \count($sources),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(Mesure $mesure): array
    {
        return [
            'indicateur' => $mesure->getIndicateur()?->getCode(),
            'niveau' => $mesure->getNiveau()->value,
            'periodeDebut' => $mesure->getPeriodeDebut()->format('Y-m-d'),
            'periodeFin' => $mesure->getPeriodeFin()->format('Y-m-d'),
            'valeur' => $mesure->getValeur(),
            'statutCompletude' => $mesure->getStatutCompletude()->value,
            'sitesManquants' => $mesure->getSitesManquants(),
            'comparabiliteRegime' => $mesure->isComparabiliteRegime(),
            'regimeExploitant' => $mesure->getRegimeExploitant()?->value,
            'genereLe' => $mesure->getGenereLe()->format(DATE_ATOM),
        ];
    }

    private function periodeDepuisRequete(Request $request): Periode
    {
        $debut = $request->query->get('periodeDebut');
        $fin = $request->query->get('periodeFin');
        if (\is_string($debut) && \is_string($fin)) {
            return Periode::depuisDates(new \DateTimeImmutable($debut), new \DateTimeImmutable($fin), GranulariteMesure::Jour);
        }

        return Periode::jour();
    }
}
