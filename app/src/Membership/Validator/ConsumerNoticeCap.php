<?php

declare(strict_types=1);

namespace App\Membership\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Le préavis de résiliation d'un abonnement CONSOMMATEUR (payeur personne physique) ne peut dépasser
 * le plafond protecteur du régime (`SubscriberRegime`) — D113 / #96. Un professionnel ou une
 * collectivité (personne morale) n'est pas plafonné : son marché fixe le préavis.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ConsumerNoticeCap extends Constraint
{
    public string $message = 'Un préavis de résiliation de {{ preavis }} jours dépasse le plafond de {{ max }} '
        . 'jours opposable à un abonné consommateur (personne physique). Réduisez-le, ou rattachez '
        . 'l\'abonnement à un payeur personne morale s\'il s\'agit d\'un marché.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
