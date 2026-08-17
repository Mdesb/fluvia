<?php

declare(strict_types=1);

namespace App\Stock\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * RG-STOCK-02/CA-1 : vérifie la clé de contrôle EAN-13/EAN-8 (GS1). EAN-13 : positions 1..12
 * (gauche→droite, 1-indexées) pondérées 1/3/1/3… ; EAN-8 : positions 1..7 pondérées 3/1/3/1… ;
 * clé = (10 − Σ(chiffre × poids) mod 10) mod 10. Vérifié sur les exemples GS1 de référence
 * (5901234123457, 40170725).
 */
final class CodeEanValideValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof CodeEanValide) {
            throw new UnexpectedTypeException($constraint, CodeEanValide::class);
        }

        if ($value === null || $value === '') {
            return;
        }

        if (!\is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        if (!self::estValide($value)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', $value)
                ->addViolation();
        }
    }

    public static function estValide(string $code): bool
    {
        $longueur = \strlen($code);
        if (($longueur !== 13 && $longueur !== 8) || !ctype_digit($code)) {
            return false;
        }

        $chiffres = array_map('intval', str_split($code));
        $cle = array_pop($chiffres); // dernier chiffre = clé de contrôle

        // EAN-13 : position 1 (i=0) poids 1 ; EAN-8 : position 1 (i=0) poids 3.
        $poidsPosition1 = $longueur === 13 ? 1 : 3;
        $poidsAutre = $longueur === 13 ? 3 : 1;

        $somme = 0;
        foreach ($chiffres as $i => $chiffre) {
            $somme += $chiffre * ($i % 2 === 0 ? $poidsPosition1 : $poidsAutre);
        }

        $cleAttendue = (10 - ($somme % 10)) % 10;

        return $cleAttendue === $cle;
    }
}
