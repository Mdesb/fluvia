<?php

declare(strict_types=1);

namespace App\Social\Exception;

/**
 * Un réseau a refusé une publication, ou n'a pas répondu (SOC-2).
 *
 * **`retryable` est le seul champ qui compte vraiment**, et c'est lui qui rend l'asynchrone tenable.
 * Réessayer cinq fois un jeton révoqué ne le rendra pas valide : cela retarde de vingt minutes
 * l'affichage d'une erreur que l'utilisateur pouvait corriger tout de suite, et cela consomme le quota
 * du réseau pour rien. À l'inverse, marquer en échec définitif un dépassement de quota ferait perdre
 * une publication qui serait passée dix minutes plus tard.
 *
 * `errorCode` reprend le motif du réseau tel quel. On ne le traduit pas en vocabulaire maison : un
 * code réécrit fait diverger le diagnostic de ce que la documentation du réseau permet de chercher.
 *
 * Le message ne contient jamais le jeton — un adaptateur qui recopierait la requête en cas d'erreur le
 * ferait fuir dans les journaux, qui sont précisément l'endroit où l'on regarde à plusieurs.
 */
final class SocialPublishingException extends \RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        public readonly bool $retryable,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** Quota dépassé, réseau indisponible, coupure : cela repassera tout seul. */
    public static function retryable(string $errorCode, string $message): self
    {
        return new self($errorCode, true, $message);
    }

    /** Jeton refusé, contenu invalide : réessayer ne changera rien. */
    public static function permanent(string $errorCode, string $message): self
    {
        return new self($errorCode, false, $message);
    }
}
