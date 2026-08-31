<?php

declare(strict_types=1);

namespace App\Calendar\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Calendar\State\CalendarFeedProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * `GET /agenda/journal?du=&au=&scope=site|moi` — tout ce qui occupe l'intervalle demandé.
 *
 * Une seule requête pour l'écran, quel que soit le nombre de sources agrégées. Le contraire — un
 * appel par source, assemblé côté client — ferait dépendre l'affichage de l'ordre d'arrivée des
 * réponses, et ferait réécrire la règle de tri dans chaque écran qui voudrait s'en servir.
 */
#[ApiResource(
    shortName: 'CalendarFeed',
    operations: [
        new Get(
            uriTemplate: '/calendar/feed',
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            provider: CalendarFeedProvider::class,
            normalizationContext: ['groups' => ['calendar_feed:read']],
        ),
    ],
)]
final class CalendarFeed
{
    #[ApiProperty(identifier: true)]
    #[Groups(['calendar_feed:read'])]
    public string $id = 'journal';

    /** `site` ou `moi` — repris tel quel, pour que l'écran sache ce qu'il a reçu. */
    #[Groups(['calendar_feed:read'])]
    public string $scope = 'site';

    /** Fuseau de l'établissement : les instants sont en ATOM, mais la journée se découpe ici. */
    #[Groups(['calendar_feed:read'])]
    public string $timezone = 'Europe/Paris';

    /** @var list<array<string, mixed>> */
    #[Groups(['calendar_feed:read'])]
    public array $events = [];
}
