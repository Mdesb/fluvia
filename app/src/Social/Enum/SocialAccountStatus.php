<?php

declare(strict_types=1);

namespace App\Social\Enum;

/**
 * État d'un compte social connecté (D14).
 *
 * L'état est distinct de « le jeton est-il présent » : un compte dont le jeton a expiré garde son
 * jeton chiffré en base — c'est lui qu'on rafraîchira. Effacer le jeton à la première erreur ferait
 * perdre le moyen de se rétablir, et transformerait une panne temporaire en reconnexion manuelle.
 */
enum SocialAccountStatus: string
{
    /** Jeton valide à la dernière utilisation connue. */
    case Connected = 'connected';

    /**
     * Le réseau a refusé le jeton (expiré ou révoqué de son côté). Le compte reste en base, ses
     * publications passées gardent leur historique, et une reconnexion le remet en service.
     */
    case TokenExpired = 'token_expired';

    /**
     * Déconnecté volontairement côté plateforme. Les jetons sont effacés — c'est le seul cas où on
     * les efface, parce que c'est le seul où l'utilisateur a demandé qu'on ne les détienne plus.
     */
    case Revoked = 'revoked';

    public function canPublish(): bool
    {
        return $this === self::Connected;
    }
}
