<?php

declare(strict_types=1);

namespace App\Reporting\Controller;

use App\Organisation\Entity\Etablissement;
use App\Reporting\Projection\ProjectionAccesInterface;
use App\Reporting\Projection\ProjectionComptaInterface;
use App\Reporting\Projection\ProjectionVenteInterface;
use App\Reporting\Security\PerimetreReportingResolver;
use App\Reporting\ValueObject\Periode;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /reporting/dashboards/etablissement/{id}` (M7-01, CA-2, §2.4/§3 plan-reporting.md) : lecture
 * DIRECTE des modules producteurs (jamais via `Mesure`) — CA du jour, entrées du jour, jauges FMI
 * (état alerte au franchissement de seuil), fond de caisse. Aucune mise en cache : chaque appel est
 * une lecture fraîche (poll court côté front pour le rafraîchissement sans rechargement manuel).
 */
#[AsController]
final class DashboardEtablissementController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly PerimetreReportingResolver $resolver,
        private readonly ProjectionVenteInterface $projectionVente,
        private readonly ProjectionAccesInterface $projectionAcces,
        private readonly ProjectionComptaInterface $projectionCompta,
    ) {
    }

    #[Route('/reporting/dashboards/etablissement/{id}', name: 'reporting_dashboard_etablissement', methods: ['GET'])]
    public function __invoke(string $id): JsonResponse
    {
        if (!$this->security->isGranted('PERM', 'reporting.lire')) {
            throw new AccessDeniedHttpException('reporting.lire requis.');
        }

        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Établissement introuvable.');
        }
        $etablissementId = Uuid::fromString($id);

        $utilisateur = $this->security->getUser();
        \assert($utilisateur instanceof Utilisateur);
        $perimetre = $this->resolver->perimetreEffectif($utilisateur, 'lire');
        if (!$perimetre->estAutoriseEtablissement($etablissementId)) {
            throw new AccessDeniedHttpException('Établissement hors périmètre (RG-M7-01, CA-1).');
        }

        $etablissement = $this->em->getRepository(Etablissement::class)->find($etablissementId);
        if (!$etablissement instanceof Etablissement) {
            throw new NotFoundHttpException('Établissement introuvable.');
        }

        $periode = Periode::jour();

        $caJour = $this->projectionVente->caEncaisse($etablissementId, $periode);
        $entreesJour = $this->projectionAcces->frequentationCumulee($etablissementId, $periode);
        $fondDeCaisse = $this->projectionCompta->fondDeCaisseTheorique($etablissementId);

        $jaugesFmi = array_map(
            static fn (array $jauge): array => $jauge + [
                'etat' => $jauge['valeurCourante'] >= $jauge['seuil'] && $jauge['seuil'] > 0 ? 'alerte' : 'normal',
            ],
            $this->projectionAcces->jaugesFmi($etablissementId),
        );

        return new JsonResponse([
            'etablissementId' => (string) $etablissementId,
            'etablissementNom' => $etablissement->getNom(),
            'caJour' => $caJour,
            'entreesJour' => $entreesJour,
            'jaugesFmi' => $jaugesFmi,
            'fondDeCaisse' => $fondDeCaisse,
        ]);
    }
}
