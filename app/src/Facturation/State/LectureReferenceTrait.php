<?php

declare(strict_types=1);

namespace App\Facturation\State;

use Symfony\Component\Uid\Uuid;

/**
 * Extraction d'un UUID depuis une référence libre (IRI `/api/xxx/{uuid}` ou UUID brut) reçue dans un
 * corps JSON — même utilitaire répété dans chaque processor M2 (`CreerVenteProcessor::uuid()`),
 * mutualisé ici pour éviter sept copies identiques dans ce module.
 */
trait LectureReferenceTrait
{
    private function uuidDepuis(mixed $reference): ?Uuid
    {
        if (!\is_string($reference) || $reference === '') {
            return null;
        }
        $segment = str_contains($reference, '/') ? basename($reference) : $reference;

        return Uuid::isValid($segment) ? Uuid::fromString($segment) : null;
    }
}
