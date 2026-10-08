<?php

declare(strict_types=1);

namespace App\I18n;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Met en forme une `CodedHttpException` levée n'importe où (processeur ou contrôleur).
 *
 * Priorité 32 : avant l'écouteur d'API Platform (`kernel.exception`, priorité -96, lu dans
 * `vendor/api-platform/symfony/Bundle/Resources/config/symfony/symfony.php`, v4.3.17), qui
 * remplacerait sinon le corps par son document d'erreur et perdrait `code` et `params`. Même schéma
 * que `EscaladeRequiseExceptionListener`.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 32)]
final class CodedHttpExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof CodedHttpException) {
            return;
        }

        $event->setResponse($exception->toResponse());
        $event->stopPropagation();
    }
}
