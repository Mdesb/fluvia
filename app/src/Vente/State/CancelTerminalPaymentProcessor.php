<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Vente\Entity\Vente;
use App\Vente\Service\LecteurCorps;
use App\Vente\Service\PaymentAttemptConflict;
use App\Vente\Service\PaymentAttemptStore;
use App\Vente\Service\SettlementCoordinator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `POST /ventes/{id}/annuler-reglement` : « Annuler » pendant l'attente du terminal (spec TPE lot 1,
 * CA-3). La tentative passe `cancelled` et la vente est libérée ; si le terminal a déjà rendu son
 * issue, elle tient (409). Voir {@see SettlementCoordinator::cancel()}.
 *
 * La vente est lue (`read: true`) dans le périmètre de l'appelant : hors périmètre, 404 (CA-8).
 *
 * @implements ProcessorInterface<Vente, JsonResponse>
 */
final class CancelTerminalPaymentProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly SettlementCoordinator $coordinateur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        // Un POST lu (`read: true`) ne lève pas 404 seul : hors périmètre, le fournisseur rend null.
        if (!$data instanceof Vente) {
            throw new NotFoundHttpException('Vente introuvable.');
        }

        try {
            $resultat = $this->coordinateur->cancel($data, $this->lecteur->corps());
        } catch (PaymentAttemptConflict $conflit) {
            return $conflit->toResponse($data);
        }

        return new JsonResponse([
            'vente' => (string) $data->getId(),
            'tentative' => PaymentAttemptStore::summary($resultat['attempt']),
            'dejaEnregistre' => $resultat['dejaEnregistre'],
            'resteAPayer' => $data->getResteAPayer(),
        ]);
    }
}
