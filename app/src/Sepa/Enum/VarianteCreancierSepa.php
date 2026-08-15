<?php

declare(strict_types=1);

namespace App\Sepa\Enum;

/**
 * Variante bi-régime du bloc créancier pain.008 (plan §1) : seule différence structurante entre les
 * deux fichiers de référence anonymisés. `Regie` = `Cdtr` est la collectivité + `UltmtCdtr` (la régie)
 * + `AmdmntInd` présent. `Prive` = `Cdtr` est l'entité elle-même, pas de `UltmtCdtr`, pas d'`AmdmntInd`.
 */
enum VarianteCreancierSepa: string
{
    case Regie = 'regie';
    case Prive = 'prive';
}
