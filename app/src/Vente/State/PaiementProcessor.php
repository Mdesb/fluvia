<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Vente\Entity\Vente;
use App\Vente\Service\LecteurCorps;
use App\Vente\Service\PaymentAttemptConflict;
use App\Vente\Service\SettlementCoordinator;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Encaisse un règlement (POST /ventes/{id}/paiements, CA-8/9/10). Paiement scindé jusqu'à reste dû = 0,
 * rendu espèces uniquement, TPE automatique (un refus/timeout n'ajoute rien). Renvoie l'état du reste
 * à payer et, le cas échéant, le statut TPE.
 *
 * Un rejeu (même `cleIdempotence` ou même `id`) rend le règlement déjà enregistré — ou le refus du
 * terminal — avec `dejaEnregistre: true` et un 200 : rien n'a été créé, rien n'a été encaissé de nouveau.
 *
 * Une autre tentative tient la vente : 409, `code` = `payment_in_progress` (l'effet est en cours) ou
 * `payment_outcome_unknown` (le terminal a pu débiter ; rien ne passe avant une déclaration). Un
 * code et non un message : l'écran doit les distinguer, et le message d'une exception ne traverse
 * pas toujours la production.
 *
 * @implements ProcessorInterface<Vente, JsonResponse>
 */
final class PaiementProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly SettlementCoordinator $coordinateur,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        \assert($data instanceof Vente);

        try {
            $resultat = $this->coordinateur->settle($data, $this->lecteur->corps());
        } catch (PaymentAttemptConflict $conflit) {
            return new JsonResponse([
                'code' => $conflit->reason,
                'message' => $conflit->getMessage(),
                'vente' => (string) $data->getId(),
                'reglementEnregistre' => false,
            ], JsonResponse::HTTP_CONFLICT);
        }

        $paiement = $resultat['paiement'];
        $statutTpe = $resultat['statutTPE'];
        $dejaEnregistre = $resultat['dejaEnregistre'];

        return new JsonResponse([
            'vente' => (string) $data->getId(),
            'reglementEnregistre' => $paiement !== null,
            'dejaEnregistre' => $dejaEnregistre,
            'paiement' => $paiement?->getId() !== null ? (string) $paiement->getId() : null,
            'moyen' => $paiement?->getMoyenCode(),
            'montant' => $paiement?->getMontant(),
            'rendu' => $paiement?->getRendu(),
            'statutTPE' => $statutTpe?->value,
            'resteAPayer' => $data->getResteAPayer(),
        ], $paiement !== null && !$dejaEnregistre ? JsonResponse::HTTP_CREATED : JsonResponse::HTTP_OK);
    }
}
