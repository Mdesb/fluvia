<?php

declare(strict_types=1);

namespace App\Reporting\Controller;

use App\Organisation\Entity\Etablissement;
use App\Reporting\Service\SalesLinesReport;
use App\Securite\Service\ContexteEtablissement;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Les statistiques de vente d'UN établissement : l'établissement actif (en-tête `X-Etablissement`),
 * sur lequel le droit `reporting.lire` est calculé. Un utilisateur sans rôle sur cet établissement
 * n'a pas le droit, donc pas la réponse : la période et le regroupement sont les seuls paramètres,
 * l'établissement ne se choisit pas dans la requête.
 */
#[AsController]
final class SalesStatisticsController
{
    /** Au-delà, la lecture d'une seule requête devient lourde : un an et un jour suffit à un bilan annuel. */
    private const JOURS_MAX = 366;

    public function __construct(
        private readonly Security $security,
        private readonly ContexteEtablissement $contexte,
        private readonly SalesLinesReport $rapport,
    ) {
    }

    #[Route('/reporting/billets-delivres', name: 'reporting_billets_delivres', methods: ['GET'])]
    public function ticketsIssued(Request $request): JsonResponse
    {
        $etablissement = $this->etablissement();
        [$du, $au] = $this->periode($request);
        $par = (string) $request->query->get('par', 'jour');
        if (!\in_array($par, SalesLinesReport::GROUPINGS, true)) {
            throw new BadRequestHttpException(sprintf('« par » vaut l\'un de : %s.', implode(', ', SalesLinesReport::GROUPINGS)));
        }

        return new JsonResponse([
            'etablissement' => $etablissement->getNom(),
            'du' => $du->format('Y-m-d'),
            'au' => $au->format('Y-m-d'),
            'par' => $par,
        ] + $this->rapport->ticketsIssued($etablissement, $du, $au, $par));
    }

    #[Route('/reporting/ventes-par-article', name: 'reporting_ventes_par_article', methods: ['GET'])]
    public function salesByItem(Request $request): JsonResponse
    {
        $etablissement = $this->etablissement();
        [$du, $au] = $this->periode($request);

        return new JsonResponse([
            'etablissement' => $etablissement->getNom(),
            'du' => $du->format('Y-m-d'),
            'au' => $au->format('Y-m-d'),
        ] + $this->rapport->salesByItem($etablissement, $du, $au));
    }

    private function etablissement(): Etablissement
    {
        if (!$this->security->isGranted('PERM', 'reporting.lire')) {
            throw new AccessDeniedHttpException('reporting.lire requis sur l\'établissement actif.');
        }
        $etablissement = $this->contexte->etablissementActif();
        if (!$etablissement instanceof Etablissement) {
            throw new BadRequestHttpException('Établissement actif manquant (en-tête X-Etablissement).');
        }

        return $etablissement;
    }

    /** @return array{\DateTimeImmutable, \DateTimeImmutable} */
    private function periode(Request $request): array
    {
        $lire = static function (mixed $valeur, string $nom): \DateTimeImmutable {
            $d = \is_string($valeur) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $valeur) : false;
            if ($d === false || $d->format('Y-m-d') !== $valeur) {
                throw new BadRequestHttpException(sprintf('« %s » attend une date AAAA-MM-JJ.', $nom));
            }

            return $d;
        };
        $du = $lire($request->query->get('du'), 'du');
        $au = $lire($request->query->get('au'), 'au');
        if ($au < $du) {
            throw new BadRequestHttpException('« au » précède « du ».');
        }
        if ($du->diff($au)->days >= self::JOURS_MAX) {
            throw new BadRequestHttpException(sprintf('Période limitée à %d jours.', self::JOURS_MAX));
        }

        return [$du, $au];
    }
}
