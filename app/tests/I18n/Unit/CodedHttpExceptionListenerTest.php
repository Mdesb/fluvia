<?php

declare(strict_types=1);

namespace App\Tests\I18n\Unit;

use App\I18n\CodedHttpException;
use App\I18n\CodedHttpExceptionListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class CodedHttpExceptionListenerTest extends TestCase
{
    public function testCodedExceptionBecomesCodeParamsAndFrenchMessage(): void
    {
        $event = $this->event(new CodedHttpException(409, 'sale.already_closed', 'La vente {numero} est déjà close.', ['numero' => 'V-12']));

        (new CodedHttpExceptionListener())($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(409, $response->getStatusCode());
        self::assertTrue($event->isPropagationStopped(), 'l’écouteur d’API Platform ne doit pas réécrire le corps');
        self::assertSame([
            'status' => 409,
            'detail' => 'La vente {numero} est déjà close.',
            'message' => 'La vente {numero} est déjà close.',
            'code' => 'sale.already_closed',
            'params' => ['numero' => 'V-12'],
        ], json_decode((string) $response->getContent(), true));
    }

    public function testEmptyParamsStayAnObject(): void
    {
        $response = (new CodedHttpException(401, 'auth.mfa_invalid_code', 'Code invalide.'))->toResponse();

        self::assertStringContainsString('"params":{}', (string) $response->getContent());
    }

    public function testOtherExceptionsAreLeftToTheUsualHandling(): void
    {
        $event = $this->event(new ConflictHttpException('déjà là'));

        (new CodedHttpExceptionListener())($event);

        self::assertNull($event->getResponse());
        self::assertFalse($event->isPropagationStopped());
    }

    private function event(\Throwable $exception): ExceptionEvent
    {
        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, $exception);
    }
}
