<?php

declare(strict_types=1);

namespace App\Finance\Treasury\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Finance\Treasury\Service\CashflowForecastCalculator;
use App\Finance\Treasury\Service\PerimetreEtablissementsResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * GET `/finance/treasury/cashflow-forecast?horizonDays=` (§0.8 du plan, RG-TRE-08, non persisté) —
 * 7/30/90 proposés côté UI, tout entier positif ≤ 365 accepté (défaut 30 si absent/invalide).
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class CashflowForecastProvider implements ProviderInterface
{
    private const HORIZON_MAX_JOURS = 365;
    private const HORIZON_DEFAUT_JOURS = 30;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly PerimetreEtablissementsResolver $perimetreResolver,
        private readonly CashflowForecastCalculator $calculator,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $request = $this->requestStack->getCurrentRequest();
        $horizonBrut = $request?->query->get('horizonDays');
        $horizonDays = self::HORIZON_DEFAUT_JOURS;
        if (\is_string($horizonBrut) && ctype_digit($horizonBrut)) {
            $candidat = (int) $horizonBrut;
            if ($candidat > 0 && $candidat <= self::HORIZON_MAX_JOURS) {
                $horizonDays = $candidat;
            }
        }

        $resultat = $this->calculator->projeter(
            $this->perimetreResolver->etablissementsAutorises(),
            new \DateTimeImmutable('today'),
            $horizonDays,
        );

        return new JsonResponse([
            'asOfDate' => $resultat['asOfDate'],
            'horizonDays' => $resultat['horizonDays'],
            'projectedBalance' => number_format($resultat['projectedBalanceCents'] / 100, 2, '.', ''),
        ]);
    }
}
