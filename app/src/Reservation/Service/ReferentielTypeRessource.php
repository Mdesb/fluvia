<?php

declare(strict_types=1);

namespace App\Reservation\Service;

/**
 * Référentiel extensible des `codeType` de Ressource (RG-M5-03/05, décision structurante n°1 du plan).
 * Pas d'`enum` figé (constitution §4.4) : liste de codes connus « à titre indicatif » pour
 * l'autocomplétion, mais **tout** code non vide, court, en snake_case est toléré — chaque verticale
 * peut introduire son propre type sans modification de ce module.
 */
final class ReferentielTypeRessource
{
    /** @var list<string> */
    public const CODES_CONNUS = [
        'terrain', 'glace', 'ligne_eau', 'bassin', 'salle', 'court', 'table', 'personnel', 'guide',
        'encadrant', 'coach', 'materiel',
    ];

    public function estValide(?string $codeType): bool
    {
        if ($codeType === null || trim($codeType) === '') {
            return false;
        }

        return (bool) preg_match('/^[a-z][a-z0-9_]{1,39}$/', $codeType);
    }

    /** @return list<string> */
    public function codesConnus(): array
    {
        return self::CODES_CONNUS;
    }
}
