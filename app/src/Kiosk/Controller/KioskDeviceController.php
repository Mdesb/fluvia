<?php

declare(strict_types=1);

namespace App\Kiosk\Controller;

use App\Kiosk\Exception\KioskOutOfServiceException;
use App\Kiosk\Security\KioskPrincipal;
use App\Kiosk\Service\KioskCheckout;
use App\Kiosk\Service\KioskDeviceAccess;
use App\Vente\Service\LecteurCorps;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Les routes de l'APPAREIL de borne (`/api/kiosk/…`), servies par le pare-feu `kiosk` : un jeton
 * d'appareil, jamais un compte du personnel. Elles ne connaissent que LEUR borne (`KioskPrincipal`), et
 * n'offrent que le parcours de l'usager : rien n'y lit ni n'écrit le back-office.
 *
 * Les erreurs sortent toujours en JSON `{ message, code }` : l'écran de la borne affiche le message
 * tel quel, en français, à un usager qui n'a personne à qui demander ce qu'est un « 409 ».
 */
#[AsController]
#[Route('/api/kiosk', format: 'json')]
final class KioskDeviceController
{
    public function __construct(
        private readonly Security $security,
        private readonly KioskCheckout $checkout,
        private readonly KioskDeviceAccess $access,
        private readonly LecteurCorps $body,
    ) {
    }

    #[Route('/pair', methods: ['POST'])]
    public function pair(Request $request): JsonResponse
    {
        return $this->respond(fn (): array => [Response::HTTP_CREATED, $this->access->pair(
            \is_string($this->body->corps()['code'] ?? null) ? $this->body->corps()['code'] : '',
            $request->getClientIp(),
        )]);
    }

    #[Route('/config', methods: ['GET'])]
    public function config(): JsonResponse
    {
        return $this->respond(fn (): array => [Response::HTTP_OK, $this->checkout->config($this->kiosk()->kiosk)]);
    }

    #[Route('/orders', methods: ['POST'])]
    public function open(): JsonResponse
    {
        return $this->respond(fn (): array => [Response::HTTP_CREATED, $this->checkout->open($this->kiosk()->kiosk, $this->body->corps())]);
    }

    #[Route('/orders/{sale}/pay', methods: ['POST'])]
    public function pay(string $sale): JsonResponse
    {
        return $this->respond(fn (): array => $this->checkout->pay($this->kiosk()->kiosk, $sale, $this->body->corps()));
    }

    #[Route('/orders/{sale}/payments/{attempt}', methods: ['GET'])]
    public function payment(string $sale, string $attempt): JsonResponse
    {
        return $this->respond(fn (): array => [Response::HTTP_OK, $this->checkout->payment($this->kiosk()->kiosk, $sale, $attempt)]);
    }

    #[Route('/orders/{sale}/payments/{attempt}/bridge-outcome', methods: ['POST'])]
    public function bridgeOutcome(string $sale, string $attempt): JsonResponse
    {
        return $this->respond(fn (): array => [Response::HTTP_OK, $this->checkout->relayBridgeOutcome($this->kiosk()->kiosk, $sale, $attempt, $this->body->corps())]);
    }

    #[Route('/orders/{sale}/payments/{attempt}/cancel', methods: ['POST'])]
    public function cancelPayment(string $sale, string $attempt): JsonResponse
    {
        return $this->respond(fn (): array => [Response::HTTP_OK, $this->checkout->cancelPayment($this->kiosk()->kiosk, $sale, $attempt)]);
    }

    #[Route('/orders/{sale}/complete', methods: ['POST'])]
    public function complete(string $sale): JsonResponse
    {
        return $this->respond(fn (): array => [Response::HTTP_OK, $this->checkout->complete($this->kiosk()->kiosk, $sale)]);
    }

    #[Route('/orders/{sale}/abandon', methods: ['POST'])]
    public function abandon(string $sale): JsonResponse
    {
        return $this->respond(fn (): array => [Response::HTTP_OK, $this->checkout->abandon($this->kiosk()->kiosk, $sale)]);
    }

    #[Route('/unlock', methods: ['POST'])]
    public function unlock(): JsonResponse
    {
        return $this->respond(function (): array {
            $code = $this->body->corps()['code'] ?? '';
            $this->access->unlock($this->kiosk()->kiosk, \is_string($code) ? $code : '');

            return [Response::HTTP_OK, ['unlocked' => true]];
        });
    }

    private function kiosk(): KioskPrincipal
    {
        $user = $this->security->getUser();

        return $user instanceof KioskPrincipal ? $user : throw new AccessDeniedHttpException('Réservé aux bornes appairées.');
    }

    /** @param callable(): array{0: int, 1: array<string, mixed>} $action */
    private function respond(callable $action): JsonResponse
    {
        try {
            [$status, $payload] = $action();

            return new JsonResponse($payload, $status);
        } catch (HttpExceptionInterface $e) {
            return new JsonResponse([
                'message' => $e->getMessage(),
                'code' => $e instanceof KioskOutOfServiceException ? KioskOutOfServiceException::CODE : null,
            ], $e->getStatusCode(), $e->getHeaders());
        }
    }
}
