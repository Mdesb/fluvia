<?php

declare(strict_types=1);

namespace App\Stock\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * RG-STOCK-02 — un code-barres est unique **au sein d'un établissement**.
 *
 * Remplace le `#[UniqueEntity(fields: ['etablissement', 'codeEAN'])]` d'origine, qui est devenu
 * inopérant le jour où D41 a fermé `etablissement` à l'écriture : la validation s'exécute **avant**
 * l'estampillage, elle voyait donc un établissement `null`, cherchait un doublon parmi les articles
 * sans établissement — il n'y en a aucun — et laissait passer. Le doublon était ensuite estampillé et
 * inséré. **La règle n'échouait pas bruyamment : elle disparaissait.**
 *
 * C'est le piège general de D41, et il ne se voit que sur les entites dont une contrainte d unicite
 * porte sur l etablissement. Il n y en a qu une dans tout le depot aujourd hui — celle-ci — mais la
 * prochaine sera ecrite par quelqu un qui ne saura pas.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class UniqueEanPerEstablishment extends Constraint
{
    public string $message = 'Ce code-barres est déjà utilisé sur cet établissement (RG-STOCK-02).';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
