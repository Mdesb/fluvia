<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Entity\Utilisateur;
use App\Vente\Entity\Vente;
use App\Vente\Service\ContrePassationHandler;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Rembourse une vente validée (POST /ventes/{id}/rembourser, CA-13). Droit vente.rembourser requis
 * (403 sinon). Aucun remboursement automatique : passe par cette demande explicite, tracée par
 * contre-passation (Avoir). Corps : { "motif": "…", "montant"?: "…" (partiel, défaut = total) }.
 *
 * @implements ProcessorInterface<Vente, JsonResponse>
 */
final class RembourserVenteProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly ContrePassationHandler $handler,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        \assert($data instanceof Vente);
        $auteur = $this->security->getUser();
        \assert($auteur instanceof Utilisateur);

        $corps = $this->lecteur->corps();
        $motif = \is_string($corps['motif'] ?? null) ? (string) $corps['motif'] : '';
        $montant = isset($corps['montant']) ? (string) $corps['montant'] : null;

        $avoir = $this->handler->rembourser($data, $montant, $motif, $auteur);
        $this->em->persist($avoir);
        $this->em->flush();

        return new JsonResponse([
            'avoir' => (string) $avoir->getId(),
            'numero' => $avoir->getNumero(),
            'venteOrigine' => (string) $data->getId(),
            'statutVente' => $data->getStatut()->value,
            'montant' => $avoir->getMontant(),
            'nature' => $avoir->getNature(),
        ], JsonResponse::HTTP_CREATED);
    }
}
