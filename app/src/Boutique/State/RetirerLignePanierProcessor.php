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
use Symfony\Component\Uid\Uuid;

/**
 * POST /boutique/paniers/{id}/lignes/{ligneId}/retirer (§4.3 spec) : retire une ligne à tout instant
 * avant paiement, libère immédiatement le compteur temporaire associé (créneau timed-entry inclus).
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
        \assert($data instanceof PanierEnLigne);
        $this->guard->verifier($data);

        $ligneId = $uriVariables['ligneId'] ?? null;
        $id = \is_string($ligneId) && Uuid::isValid($ligneId) ? Uuid::fromString($ligneId) : null;
        $ligne = $id !== null ? $this->em->getRepository(LignePanierEnLigne::class)->find($id) : null;
        if (!$ligne instanceof LignePanierEnLigne || $ligne->getPanier()?->getId()->toRfc4122() !== $data->getId()->toRfc4122()) {
            throw new NotFoundHttpException('Ligne de panier introuvable.');
        }

        $data->removeLigne($ligne);
        $this->em->remove($ligne);
        $this->em->flush();

        return $data;
    }
}
