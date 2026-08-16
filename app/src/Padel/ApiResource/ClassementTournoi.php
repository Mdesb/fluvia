<?php

declare(strict_types=1);

namespace App\Padel\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Padel\State\ClassementTournoiProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Classement/avancement calculé d'un tournoi (US-PADEL-06, CA-7), non-Doctrine — dérivé à la lecture
 * des `MatchTournoi.statut=joue`, jamais persisté (même patron que `Piscine\ApiResource\PossEtatLive`).
 */
#[ApiResource(
    shortName: 'PadelClassementTournoi',
    operations: [
        new Get(
            uriTemplate: '/padel/tournois/{id}/classement',
            security: "is_granted('PERM', 'padel.lire')",
            provider: ClassementTournoiProvider::class,
            normalizationContext: ['groups' => ['classement:read']],
        ),
    ],
)]
final class ClassementTournoi
{
    #[ApiProperty(identifier: true)]
    #[Groups(['classement:read'])]
    public string $id = '';

    #[Groups(['classement:read'])]
    public string $format = '';

    /** @var list<array{poule: string, classement: list<array{inscription: string, joueur1: string, joueur2: string, victoires: int, matchsJoues: int}>}> */
    #[Groups(['classement:read'])]
    public array $poules = [];

    /** @var list<array{id: string, tour: int|null, statut: string, paireA: string, paireB: string, vainqueur: string|null}> */
    #[Groups(['classement:read'])]
    public array $matchs = [];
}
