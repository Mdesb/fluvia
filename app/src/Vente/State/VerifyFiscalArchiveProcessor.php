<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Vente\Nf525\Entity\FiscalArchive;
use App\Vente\Nf525\FiscalArchiveChecker;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `POST /fiscal_archives/{id}/verify` — rapport de vérification de l'archive (#342). 200 si intacte,
 * 409 sinon (même convention que la vérification de chaîne).
 *
 * L'archive est résolue par API Platform (`read: true`), donc à travers `PerimetreVenteExtension` :
 * celle d'un autre établissement est introuvable.
 *
 * @implements ProcessorInterface<FiscalArchive, JsonResponse>
 */
final class VerifyFiscalArchiveProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly FiscalArchiveChecker $verifier,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        if (!$data instanceof FiscalArchive) {
            throw new NotFoundHttpException('Archive introuvable.');
        }

        $rapport = $this->verifier->verify($data);

        return new JsonResponse(
            ['archive' => (string) $data->getId(), 'fiscalYear' => $data->getFiscalYear()] + $rapport,
            $rapport['intact'] ? JsonResponse::HTTP_OK : JsonResponse::HTTP_CONFLICT,
        );
    }
}
