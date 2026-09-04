<?php

declare(strict_types=1);

namespace App\Subscription\Exception;

/**
 * Le lien de confirmation d'essai existe, mais sa fenêtre est passée (ED-5, essai libre-service).
 *
 * Distinguée du jeton inconnu parce que **le prospect doit pouvoir recommencer**, et qu'on le sait :
 * la demande existe, l'adresse est connue, seul le délai a couru. Lui répondre « lien invalide »
 * l'enverrait chercher une faute qu'il n'a pas commise ; lui répondre « ce lien a expiré, redemandez
 * votre essai » lui donne le geste suivant.
 *
 * Ce n'est pas contradictoire avec le silence de {@see UnknownConfirmationTokenException} : on ne
 * révèle rien de plus, puisque celui qui tient un jeton expiré tient déjà un jeton qui a existé.
 */
final class ExpiredConfirmationLinkException extends \RuntimeException
{
}
