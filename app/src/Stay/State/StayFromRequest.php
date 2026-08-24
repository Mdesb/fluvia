<?php

declare(strict_types=1);

namespace App\Stay\State;

use App\Stay\Entity\Stay;
use App\Stay\Security\StayScopeGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Résout le séjour désigné par l'URL, **et le confronte au périmètre dans le même geste**.
 *
 * Séparer les deux — un `find()` ici, un contrôle de périmètre là-bas — est précisément l'angle mort
 * qu'a révélé l'IDOR d'appairage, et qui a valu le garde-fou C19. Les réunir dans un seul appel fait
 * qu'on ne peut pas oublier le second : il n'y a pas de chemin qui rende le séjour sans l'avoir vérifié.
 */
final class StayFromRequest
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StayScopeGuard $garde,
    ) {
    }

    /** @param array<string, mixed> $uriVariables */
    public function resolve(array $uriVariables): Stay
    {
        $id = $uriVariables['id'] ?? null;
        if ($id instanceof Uuid) {
            $id = $id->toRfc4122();
        }
        if (!is_string($id) || !Uuid::isValid($id)) {
            throw new NotFoundHttpException('stay.error.stay_not_found');
        }

        $sejour = $this->em->getRepository(Stay::class)->find(Uuid::fromString($id));
        $this->garde->verify($sejour?->getEstablishment());

        \assert($sejour instanceof Stay);

        return $sejour;
    }
}
