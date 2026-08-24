<?php

declare(strict_types=1);

namespace App\Social\Message;

use App\Platform\Message\AsyncMessage;

/**
 * Demande d'envoi d'une publication vers son réseau (SOC-2, D7-bis).
 *
 * Le message ne porte **qu'un identifiant**, et c'est délibéré. Y recopier le texte, le jeton ou
 * l'établissement ferait deux choses également fâcheuses : un jeton dormirait en clair dans la table
 * de la file — donc dans les sauvegardes —, et le message porterait un état figé au moment de la mise
 * en file, alors que le compte a pu être révoqué entre-temps. Le handler relit tout depuis la base.
 *
 * Et l'identifiant qu'il porte est une **entrée non fiable**, au même titre qu'un corps de requête
 * HTTP : c'est au handler de rétablir le contexte et de le vérifier, jamais au message de l'affirmer.
 */
final readonly class PublishSocialPublication implements AsyncMessage
{
    public function __construct(
        public string $publicationId,
    ) {
    }
}
