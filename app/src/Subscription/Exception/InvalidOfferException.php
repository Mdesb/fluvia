<?php

declare(strict_types=1);

namespace App\Subscription\Exception;

/**
 * Composition d'offre impossible : capacité inconnue du catalogue, ou non vendable (RG-ED-03).
 *
 * Exception dédiée plutôt que générique : le tunnel de souscription doit pouvoir distinguer « ce que
 * tu demandes n'existe pas » d'une erreur technique, pour répondre au prospect au lieu de tomber en 500.
 */
final class InvalidOfferException extends \InvalidArgumentException
{
}
