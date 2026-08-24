<?php

declare(strict_types=1);

namespace App\Social\Message;

use App\Platform\Message\AsyncMessage;

/**
 * Demande d'instantané des statistiques d'une publication (SOC-3, D7-bis).
 *
 * Comme `PublishSocialPublication`, ne porte qu'un identifiant : le handler relit tout depuis la base.
 * Un message qui transporterait le jeton le ferait dormir en clair dans la table de la file.
 *
 * Message distinct plutôt qu'un paramètre du message de publication : publier et mesurer n'ont ni la
 * même fréquence, ni les mêmes quotas, ni le même droit d'échouer. Les mêler ferait porter à la
 * publication la fragilité de la mesure.
 */
final readonly class CollectSocialMetrics implements AsyncMessage
{
    public function __construct(
        public string $publicationId,
    ) {
    }
}
