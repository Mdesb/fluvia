<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Boutique\Entity\LignePanierEnLigne;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Security\PanierProprietaireGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * POST /boutique/paniers/{id}/lignes/{ligneId}/retirer (§4.3 spec) : retire une ligne à tout instant
 * avant paiement, libère immédiatement le compteur temporaire associé (créneau timed-entry inclus).
 * Résolution manuelle du panier (`read: false`, comble des manques boutique) : avec **deux**
 * variables d'URI (`{id}`/`{ligneId}`), le provider Doctrine par défaut ne résolvait pas
 * fiablement `PanierEnLigne` (seul `id` correspond à une propriété de l'entité).
 *
 * @implements ProcessorInterface<mixed, PanierEnLigne>
 */
final class RetirerLignePanierProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PanierProprietaireGuard $guard,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PanierEnLigne
    {
        $panier = $this->resoudrePanier($uriVariables['id'] ?? null);
        $this->guard->verifier($panier);

        $id = PanierProprietaireGuard::estUuid($uriVariables['ligneId'] ?? null);
        $ligne = $id !== null ? $this->em->getRepository(LignePanierEnLigne::class)->find($id) : null;
        if (!$ligne instanceof LignePanierEnLigne || $ligne->getPanier()?->getId()->toRfc4122() !== $panier->getId()->toRfc4122()) {
            throw new NotFoundHttpException('Ligne de panier introuvable.');
        }

        $panier->removeLigne($ligne);
        $this->em->remove($ligne);
        $this->em->flush();

        return $panier;
    }

    private function resoudrePanier(mixed $reference): PanierEnLigne
    {
        $id = PanierProprietaireGuard::estUuid($reference);
        $panier = $id !== null ? $this->em->getRepository(PanierEnLigne::class)->find($id) : null;
        if (!$panier instanceof PanierEnLigne) {
            throw new NotFoundHttpException('Panier introuvable.');
        }

        return $panier;
    }
}
