<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Entity\Utilisateur;
use App\Vente\Entity\Vente;
use App\Vente\Service\LecteurCorps;
use App\Vente\Service\PaymentAttemptConflict;
use App\Vente\Service\PaymentAttemptStore;
use App\Vente\Service\SettlementCoordinator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `POST /ventes/{id}/declarer-reglement` : le caissier déclare ce qu'affiche un terminal resté muet
 * (G-6, Q-A1, D122). Seule sortie d'une vente tenue par une tentative `unresolved`.
 *
 * Le geste est signé : seul un `Utilisateur` déclare (un jeton de partenaire ou de terminal n'a
 * personne pour en répondre), et la tentative garde qui, quand, et la référence du ticket CB.
 *
 * @implements ProcessorInterface<Vente, JsonResponse>
 */
final class DeclareSettlementProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly SettlementCoordinator $coordinateur,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        // Un POST lu (`read: true`) ne lève pas 404 seul : hors périmètre, le fournisseur rend null.
        if (!$data instanceof Vente) {
            throw new NotFoundHttpException('Vente introuvable.');
        }
        $caissier = $this->security->getUser();
        if (!$caissier instanceof Utilisateur) {
            throw new AccessDeniedHttpException('Une déclaration d\'encaissement est signée par un utilisateur de la caisse.');
        }

        try {
            $resultat = $this->coordinateur->declare($data, $this->lecteur->corps(), $caissier);
        } catch (PaymentAttemptConflict $conflit) {
            return $conflit->toResponse($data);
        }
        $paiement = $resultat['paiement'];

        return new JsonResponse([
            'vente' => (string) $data->getId(),
            'tentative' => PaymentAttemptStore::summary($resultat['attempt'])['id'],
            'issue' => $resultat['status']->value,
            'reglementEnregistre' => $paiement !== null,
            'dejaEnregistre' => $resultat['dejaEnregistre'],
            'paiement' => $paiement !== null ? (string) $paiement->getId() : null,
            'moyen' => $paiement?->getMoyenCode(),
            'montant' => $paiement?->getMontant(),
            'rendu' => $paiement?->getRendu(),
            'statutTPE' => $paiement?->getStatutTPE()?->value,
            'resteAPayer' => $data->getResteAPayer(),
        ], $paiement !== null && !$resultat['dejaEnregistre'] ? JsonResponse::HTTP_CREATED : JsonResponse::HTTP_OK);
    }
}
