<?php

declare(strict_types=1);

namespace App\Subscription\Exception;

/**
 * Période de facturation incohérente — fin antérieure au début, ou période vide.
 *
 * On échoue plutôt que de renvoyer zéro : un montant nul se glisse dans une facture sans que personne
 * ne s'en aperçoive, là où une exception arrête la génération et se corrige le jour même.
 */
final class InvalidPeriodException extends \InvalidArgumentException
{
}
