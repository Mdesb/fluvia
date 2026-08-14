<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Vente\Entity\Vente;
use App\Vente\Service\LecteurCorps;
use App\Vente\Service\PaiementHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Encaisse un règlement (POST /ventes/{id}/paiements, CA-8/9/10). Paiement scindé jusqu'à reste dû = 0,
 * rendu espèces uniquement, TPE automatique (un refus/timeout n'ajoute rien). Renvoie l'état du reste
 * à payer et, le cas échéant, le statut TPE.
 *
 * @implements ProcessorInterface<Vente, JsonResponse>
 */
final class PaiementProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly PaiementHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        \assert($data instanceof Vente);

        $resultat = $this->handler->encaisser($data, $this->lecteur->corps());
        $this->em->flush();

        $paiement = $resultat['paiement'];
        $statutTpe = $resultat['statutTPE'];

        return new JsonResponse([
            'vente' => (string) $data->getId(),
            'reglementEnregistre' => $paiement !== null,
            'paiement' => $paiement?->getId() !== null ? (string) $paiement->getId() : null,
            'moyen' => $paiement?->getMoyenCode(),
            'montant' => $paiement?->getMontant(),
            'rendu' => $paiement?->getRendu(),
            'statutTPE' => $statutTpe?->value,
            'resteAPayer' => $data->getResteAPayer(),
        ], $paiement !== null ? JsonResponse::HTTP_CREATED : JsonResponse::HTTP_OK);
    }
}
