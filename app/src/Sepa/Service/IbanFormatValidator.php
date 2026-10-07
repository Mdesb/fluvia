<?php

declare(strict_types=1);

namespace App\Sepa\Service;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Validator\Constraints\Iban;
use Symfony\Component\Validator\Validation;

/**
 * Valide le FORMAT d'un IBAN (structure par pays + clé de contrôle mod-97) avant qu'il ne soit
 * tokenisé, chiffré et stocké. Sans ce contrôle, un IBAN mal saisi était accepté et n'échouait qu'au
 * pain.008 / au rejet bancaire — longtemps après la vente, loin de la saisie, donc invisible du
 * vendeur.
 *
 * ⚠ ON NE RÉÉCRIT PAS L'ALGORITHME. Les longueurs par pays et la clé mod-97 vivent dans la contrainte
 *    `Iban` de Symfony ; la dupliquer serait un second référentiel à maintenir, faux le jour où un
 *    pays change de format. On délègue.
 */
final class IbanFormatValidator
{
    public function valider(string $iban): void
    {
        $violations = Validation::createValidator()->validate(trim($iban), new Iban());
        if (\count($violations) > 0) {
            throw new UnprocessableEntityHttpException(
                'IBAN invalide : vérifiez la saisie (format du pays et clé de contrôle).',
            );
        }
    }
}
