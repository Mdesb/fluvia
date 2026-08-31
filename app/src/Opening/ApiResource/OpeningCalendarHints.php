<?php

declare(strict_types=1);

namespace App\Opening\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Opening\State\OpeningCalendarHintsProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * CE QUE LE CALENDRIER DOIT SAVOIR SANS QU'ON LE SAISISSE : fériés et vacances scolaires.
 *
 * ── DEUX CHOSES DIFFÉRENTES, ET ELLES NE SE MÉLANGENT PAS ───────────────────────────────────────
 *
 * Un JOUR FÉRIÉ est une date. Il peut fermer le site — ou pas, c'est le choix de l'exploitant :
 * il y a des patinoires qui font leur année le 25 décembre. Il est donc rendu comme une
 * PROPOSITION : l'écran le coche d'avance, l'exploitant décoche.
 *
 * Une période de VACANCES SCOLAIRES ne ferme rien. Elle change la fréquentation, donc parfois les
 * horaires — une piscine ouvre en journée quand les écoles sont fermées. Elle est rendue comme un
 * FOND de calendrier, et l'écran ne la rend pas cliquable : personne ne modifie un arrêté
 * ministériel depuis un logiciel de billetterie.
 *
 * Les confondre dans une seule liste donnerait un contrôle d'accès qui refuse du monde pendant les
 * vacances de la Toussaint. C'est pour ça qu'elles voyagent dans deux champs distincts et pas dans
 * un champ « événements » avec un type.
 *
 * ── `schoolHolidaysAvailable` DIT LA DIFFÉRENCE ENTRE RIEN ET PAS PU ────────────────────────────
 *
 * Sans lui, l'écran écrirait « aucune vacance sur la période » un jour où le ministère ne répond
 * pas. C'est un mensonge tranquille, et il ne se découvre jamais.
 */
#[ApiResource(
    shortName: 'OpeningCalendarHints',
    operations: [
        new Get(
            uriTemplate: '/opening/calendar-hints',
            security: "is_granted('PERM', 'acces.lire') or is_granted('PERM', 'organisation.gerer') or is_granted('PERM', 'reservation.lire')",
            provider: OpeningCalendarHintsProvider::class,
            normalizationContext: ['groups' => ['opening_hints:read']],
        ),
    ],
)]
final class OpeningCalendarHints
{
    #[ApiProperty(identifier: true)]
    #[Groups(['opening_hints:read'])]
    public string $id = 'hints';

    /** Zone retenue pour ce site, ou `null` si l'exploitant n'en a pas choisi. */
    #[Groups(['opening_hints:read'])]
    public ?string $schoolZone = null;

    /** Deux fériés de plus en Alsace-Moselle : l'écran doit pouvoir dire pourquoi. */
    #[Groups(['opening_hints:read'])]
    public bool $alsaceMoselle = false;

    /** @var list<array{date: string, label: string, local: bool, alreadyClosed: bool}> */
    #[Groups(['opening_hints:read'])]
    public array $publicHolidays = [];

    #[Groups(['opening_hints:read'])]
    public bool $schoolHolidaysAvailable = true;

    #[Groups(['opening_hints:read'])]
    public ?string $schoolHolidaysReason = null;

    /** @var list<array{label: string, start: string, end: string}> */
    #[Groups(['opening_hints:read'])]
    public array $schoolHolidays = [];
}
