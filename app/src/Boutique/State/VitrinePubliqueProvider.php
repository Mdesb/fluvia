<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Boutique\Entity\Vitrine;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Boutique\Security\VitrineAccessibleGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /boutique/vitrines/{id} (RG-M3-01, revue de sécurité — faille majeure) : une vitrine dont
 * l'établissement est inactif, ou dont le canal `en_ligne` est coupé, n'est jamais exposée publiquement
 * — même garde que `CatalogueVitrineProvider`/`OuvrirPanierProcessor` (`VitrineAccessibleGuard`).
 *
 * @implements ProviderInterface<Vitrine>
 */
final class VitrinePubliqueProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VitrineAccessibleGuard $guard,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Vitrine
    {
        $id = PanierProprietaireGuard::estUuid($uriVariables['id'] ?? null);
        $vitrine = $id !== null ? $this->em->getRepository(Vitrine::class)->find($id) : null;
        if (!$vitrine instanceof Vitrine) {
            throw new NotFoundHttpException('Vitrine introuvable.');
        }
        $this->guard->verifier($vitrine);

        return $vitrine;
    }
}
