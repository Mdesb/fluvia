<?php

declare(strict_types=1);

namespace App\Finance\Treasury\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Finance\Treasury\Service\PaymentScheduleCalculator;
use App\Finance\Treasury\Service\PerimetreEtablissementsResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * GET `/finance/treasury/payment-schedule?from=&to=` (§0.8 du plan, RG-TRE-06/07, CA-5, non persisté) —
 * endpoint HTTP fin : parse `from`/`to` (défaut : aujourd'hui .. +30 jours), délègue à
 * `PaymentScheduleCalculator` (réutilisé tel quel par `CashflowForecastCalculator`).
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class PaymentScheduleProvider implements ProviderInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly PerimetreEtablissementsResolver $perimetreResolver,
        private readonly PaymentScheduleCalculator $calculator,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $request = $this->requestStack->getCurrentRequest();
        $from = $this->dateParam($request?->query->get('from'), new \DateTimeImmutable('today'));
        $to = $this->dateParam($request?->query->get('to'), $from->modify('+30 days'));

        $resultat = $this->calculator->echeancier($this->perimetreResolver->etablissementsAutorises(), $from, $to);

        return new JsonResponse([
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'exits' => $resultat['exits'],
            'entries' => $resultat['entries'],
        ]);
    }

    private function dateParam(mixed $valeur, \DateTimeImmutable $defaut): \DateTimeImmutable
    {
        return \is_string($valeur) && $valeur !== '' ? new \DateTimeImmutable($valeur) : $defaut;
    }
}
