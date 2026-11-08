<?php

declare(strict_types=1);

namespace App\Acces\Hardware;

/** Ce que le poste d'encodage a lu sur le support posé : l'identifiant brut et la forme du lecteur. */
final readonly class CredentialRead
{
    public function __construct(
        public string $rawIdentifier,
        public CredentialFormat $format,
    ) {
    }
}
