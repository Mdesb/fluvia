<?php

declare(strict_types=1);

namespace App\Autorisation\EventListener;

use App\Autorisation\Exception\EscaladeRequiseException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Traduit `EscaladeRequiseException` en réponse HTTP observable (§6.3 plan, RG-AUTZ-06 littéral) :
 * 403 avec un corps `{ "decision": "escalade_requise", "demandeEscalade": "<jeton>", "operation",
 * "plafond", "montant" }`. Priorité positive (avant `api_platform.listener.exception`, tag à
 * priorité -96) + `stopPropagation()` pour empêcher le listener générique de la plateforme de
 * ré-écraser cette réponse métier.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 32)]
final class EscaladeRequiseExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        if (!$throwable instanceof EscaladeRequiseException) {
            return;
        }

        $decision = $throwable->decision;
        $demande = $decision->demandeEscalade;

        $event->setResponse(new JsonResponse([
            'decision' => 'escalade_requise',
            'demandeEscalade' => $demande !== null ? (string) $demande->getJeton() : null,
            'operation' => $demande?->getOperation()?->getCode(),
            'plafond' => $decision->limiteAppliquee?->getPlafondMontant(),
            'montant' => $demande?->getMontant(),
        ], JsonResponse::HTTP_FORBIDDEN));
        $event->stopPropagation();
    }
}
