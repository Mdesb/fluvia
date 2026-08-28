<?php

declare(strict_types=1);

namespace App\Opening\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Opening\State\OpeningScheduleProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * LE PLANNING RÉSOLU : les fenêtres d'ouverture réelles entre deux dates.
 *
 * ── POURQUOI CETTE RESSOURCE EXISTE PLUTÔT QUE LA LECTURE DES TRANCHES ──────────────────────────
 *
 * Un client qui lirait `/ouverture/plage_ouvertures` recevrait des tranches HEBDOMADAIRES et devrait
 * appliquer lui-même les exceptions, la règle « le plus précis l'emporte », la traversée de minuit
 * et le fuseau de l'établissement. Il le ferait à sa façon — et l'agenda finirait par dessiner une
 * ouverture pendant laquelle la porte refuse. C'est exactement ce que le module existe pour éviter :
 * **une seule réponse, calculée une seule fois, pour l'écran comme pour le contrôle d'accès.**
 *
 * Paramètres : `du` et `au` (dates ISO, bornes incluses), `espace` (UUID, facultatif). Sans `du`,
 * la semaine en cours.
 */
#[ApiResource(
    shortName: 'OpeningSchedule',
    operations: [
        new Get(
            uriTemplate: '/opening/schedule',
            security: "is_granted('PERM', 'acces.lire') or is_granted('PERM', 'organisation.gerer') or is_granted('PERM', 'reservation.lire')",
            provider: OpeningScheduleProvider::class,
            normalizationContext: ['groups' => ['opening_schedule:read']],
        ),
    ],
)]
final class OpeningSchedule
{
    #[ApiProperty(identifier: true)]
    #[Groups(['opening_schedule:read'])]
    public string $id = 'resolu';

    /** Le planning fait-il loi au contrôle d'accès ? L'écran le dit en toutes lettres. */
    #[Groups(['opening_schedule:read'])]
    public bool $enforced = false;

    /** Fuseau dans lequel les heures ci-dessous se lisent — jamais deviné côté client. */
    #[Groups(['opening_schedule:read'])]
    public string $timezone = 'Europe/Paris';

    /** @var list<array{jour: string, debut: string, fin: string, libelle: string|null}> */
    #[Groups(['opening_schedule:read'])]
    public array $windows = [];

    /** @var list<array{date: string, type: string, motif: string, allDay: bool}> */
    #[Groups(['opening_schedule:read'])]
    public array $exceptions = [];
}
