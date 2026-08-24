<?php

declare(strict_types=1);

namespace App\Social\Exception;

/**
 * Le coffre à jetons n'a pas pu lire une valeur : version de clé inconnue, ou déchiffrement refusé.
 *
 * Une exception dédiée plutôt qu'une `RuntimeException` nue, parce que c'est la seule qu'un appelant
 * ait une raison d'attraper — une publication qui échoue sur un jeton illisible doit être marquée
 * `Failed` avec un motif net, pas confondue avec un refus du réseau.
 *
 * Son message ne contient jamais la valeur en cause : un jeton, même illisible, ne sort pas du coffre.
 */
final class SocialTokenCipherException extends \RuntimeException
{
}
