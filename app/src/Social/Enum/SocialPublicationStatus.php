<?php

declare(strict_types=1);

namespace App\Social\Enum;

/**
 * État d'une publication — une ligne, un réseau (D14).
 *
 * C'est ici que vit la vérité : l'identifiant distant, l'erreur exacte, le nombre de tentatives. Le
 * jour où un client demande « pourquoi ça n'est pas paru sur Bluesky », c'est cette ligne qui répond.
 */
enum SocialPublicationStatus: string
{
    /** En attente de traitement par la file. */
    case Pending = 'pending';

    /** Confiée à la file, pas encore de réponse du réseau. */
    case Publishing = 'publishing';

    /** Le réseau a accepté ; `remotePostId` est renseigné. */
    case Published = 'published';

    /**
     * Le réseau a refusé et les reprises sont épuisées. `errorCode` porte le motif rendu par le
     * réseau — jamais un message inventé par la plateforme, qui ferait diverger le diagnostic de la
     * réalité.
     */
    case Failed = 'failed';

    /**
     * Non tentée délibérément : compte révoqué ou jeton absent au moment de l'envoi. Distinct de
     * `Failed`, parce que rien n'a été demandé au réseau — confondre les deux ferait chercher une
     * panne côté réseau là où le compte n'était simplement plus connecté.
     */
    case Skipped = 'skipped';

    public function isTerminal(): bool
    {
        return $this === self::Published || $this === self::Failed || $this === self::Skipped;
    }
}
