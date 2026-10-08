<?php

declare(strict_types=1);

namespace App\Reservation\Facturation;

use App\Reservation\Enum\StatutFacturationNoShow;

/** Résultat de l'application d'une stratégie de facturation no-show (T19 du plan). */
final class ResultatFacturationNoShow
{
    public function __construct(
        public readonly bool $succes,
        public readonly ?string $motif = null,
        /** Un conflit (409), pas une demande invalide : facturation traitée entre-temps, clé de débit déjà employée, ou 409 de la validation. */
        public readonly bool $conflict = false,
    ) {
    }

    /** Relue sous son verrou, la facturation n'était plus « à facturer ». Rien n'a été écrit. */
    public static function alreadySettled(?StatutFacturationNoShow $statut): self
    {
        return new self(false, sprintf(
            'Cette facturation n\'est plus « à facturer » (statut : %s) : un autre geste l\'a traitée. Rien n\'a été encaissé.',
            $statut?->value ?? 'introuvable',
        ), true);
    }
}
