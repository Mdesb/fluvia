<?php

declare(strict_types=1);

namespace App\Subscription\Exception;

/**
 * Le lien de confirmation d'essai ne désigne aucune demande (ED-5, essai libre-service).
 *
 * **Un jeton inconnu et un jeton déjà consommé ne sont pas la même chose**, et c'est pour ça que
 * cette exception existe à part. Le second est le cas nominal — les clients de messagerie
 * pré-visitent les liens, et les gens cliquent deux fois — et il est traité en amont, sans erreur.
 * Ne reste ici que le jeton qui n'a jamais existé : une adresse tapée à la main, un lien tronqué par
 * un client de messagerie, ou quelqu'un qui essaie des valeurs.
 *
 * **Le message rendu au visiteur ne dit jamais laquelle des deux.** Distinguer « ce jeton n'existe
 * pas » de « ce jeton a déjà servi » transformerait cette route en oracle : on saurait, en essayant,
 * quelles demandes d'essai existent.
 */
final class UnknownConfirmationTokenException extends \RuntimeException
{
}
