<?php

declare(strict_types=1);

namespace App\Fonctionnalite\State;

use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Résolution de l'établissement ciblé par `{id}` dans les opérations `/etablissements/{id}/...` de ce
 * module (`read: false` : pas de résolution automatique par API Platform). Même patron que
 * `App\Sport\State\DetecterPresenceIsoleeProcessor::uuid()`.
 */
trait ResolutionEtablissementCheminTrait
{
    /** @param array<string, mixed> $uriVariables */
    private function resoudreEtablissement(EntityManagerInterface $em, array $uriVariables): Etablissement
    {
        $id = $this->uuidDepuis($uriVariables['id'] ?? null);
        $etablissement = $id !== null ? $em->getRepository(Etablissement::class)->find($id) : null;
        if (!$etablissement instanceof Etablissement) {
            throw new NotFoundHttpException('Établissement introuvable.');
        }

        return $etablissement;
    }

    private function uuidDepuis(mixed $reference): ?Uuid
    {
        if ($reference instanceof Uuid) {
            return $reference;
        }
        if (!\is_string($reference) || $reference === '') {
            return null;
        }
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;

        return Uuid::isValid($segment) ? Uuid::fromString($segment) : null;
    }
}
