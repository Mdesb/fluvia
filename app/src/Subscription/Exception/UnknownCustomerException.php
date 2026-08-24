<?php

declare(strict_types=1);

namespace App\Subscription\Exception;

/**
 * L'abonnement ne se rattache à aucune fiche client exploitable (ED-3, RG-ED-02).

 * Exception dédiée, et levée plutôt qu'avalée : sans client, on ne connaît pas le périmètre dans
 * lequel l'abonnement est vendu, et un événement sans tenant est refusé par le contrat (D3). Il vaut
 * mieux échouer à l'activation, bruyamment, que publier un fait rattaché au mauvais établissement.
 */
final class UnknownCustomerException extends \RuntimeException
{
}
