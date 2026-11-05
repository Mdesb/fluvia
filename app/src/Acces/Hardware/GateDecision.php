<?php

declare(strict_types=1);

namespace App\Acces\Hardware;

/**
 * Ce que la porte doit faire après une présentation (#337) : ouvrir pour `headcount` personnes, ou
 * refuser avec un code. Rendue aussi bien par la décision serveur (`HardwareEventIngestor`) que par
 * la décision locale d'une borne déconnectée (`LocalListDecider`) : un pilote n'a qu'une forme à
 * traduire en commande matérielle.
 */
final readonly class GateDecision
{
    private function __construct(
        public bool $granted,
        /** Nombre de personnes à laisser passer (rotations d'un tripode). 0 sur un refus. */
        public int $headcount,
        /** Valeur de `CodeMotifRefus` sur un refus, `null` sur une ouverture simple. */
        public ?string $reasonCode,
        public string $message,
        /** Identifiant du passage enregistré, quand la décision vient du serveur. */
        public ?string $passageId = null,
    ) {
    }

    public static function grant(int $headcount, string $message = 'Accès autorisé.', ?string $passageId = null): self
    {
        if ($headcount < 1) {
            throw new \InvalidArgumentException('Une ouverture laisse passer au moins une personne.');
        }

        return new self(true, $headcount, null, $message, $passageId);
    }

    public static function deny(string $reasonCode, string $message, ?string $passageId = null): self
    {
        return new self(false, 0, $reasonCode, $message, $passageId);
    }
}
