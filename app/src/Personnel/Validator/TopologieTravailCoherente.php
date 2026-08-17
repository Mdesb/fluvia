<?php

declare(strict_types=1);

namespace App\Personnel\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Cohérence d'un CreneauTravail (RG-PERSO-03) : refuse un créneau sans établissement, `fin <= debut`,
 * ou un effectif requis <= 0.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class TopologieTravailCoherente extends Constraint
{
    public string $messageEtablissement = 'Topologie incohérente : créneau de travail sans établissement (RG-PERSO-03).';
    public string $messageDates = 'Topologie incohérente : la fin du créneau doit être postérieure au début (RG-PERSO-03).';
    public string $messageEffectif = 'Topologie incohérente : effectif requis doit être ≥ 1 (RG-PERSO-03).';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
