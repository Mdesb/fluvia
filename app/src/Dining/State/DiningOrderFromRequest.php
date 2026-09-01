<?php

declare(strict_types=1);

namespace App\Dining\State;

use App\Dining\Entity\DiningOrder;
use App\Dining\Security\DiningScopeGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Resout l addition designee par l URL, **et la confronte au perimetre dans le meme geste**.
 *
 * Separer les deux — un `find()` ici, un controle de perimetre la-bas — est l angle mort qu a revele
 * l IDOR d appairage du 22/08 et qui a valu le garde-fou C19. Les reunir dans un seul appel fait qu on
 * ne peut pas oublier le second : il n existe pas de chemin qui rende l addition sans l avoir verifiee.
 */
final class DiningOrderFromRequest
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DiningScopeGuard $scopeGuard,
    ) {
    }

    /** @param array<string, mixed> $uriVariables */
    public function resolve(array $uriVariables): DiningOrder
    {
        $id = $uriVariables['id'] ?? null;
        if ($id instanceof Uuid) {
            $id = $id->toRfc4122();
        }
        if (!is_string($id) || !Uuid::isValid($id)) {
            throw new NotFoundHttpException('dining.error.order_not_found');
        }

        $addition = $this->em->getRepository(DiningOrder::class)->find(Uuid::fromString($id));
        $this->scopeGuard->verify($addition?->getEstablishment());

        \assert($addition instanceof DiningOrder);

        return $addition;
    }
}
