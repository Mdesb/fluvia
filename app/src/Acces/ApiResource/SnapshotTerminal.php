<?php

declare(strict_types=1);

namespace App\Acces\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Acces\State\SnapshotTerminalProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Snapshot local incrémental/complet (GET /terminal/snapshot, US-TERM-03/04/05, plan-acces-terminal.md
 * §2.3). N'est pas une entité Doctrine (même pattern que `Supervision`/`SynchronisationAcces`) : la
 * réponse est un envelope calculé par `SnapshotTerminalProvider`, pas une collection Hydra standard.
 */
#[ApiResource(
    shortName: 'SnapshotTerminal',
    operations: [
        new Get(
            uriTemplate: '/terminal/snapshot',
            security: "is_granted('PERM_TERMINAL', 'acces.snapshot')",
            provider: SnapshotTerminalProvider::class,
            normalizationContext: ['groups' => ['snapshot_terminal:read']],
        ),
    ],
)]
final class SnapshotTerminal
{
    #[ApiProperty(identifier: true)]
    #[Groups(['snapshot_terminal:read'])]
    public string $id = 'courant';

    #[Groups(['snapshot_terminal:read'])]
    public int $versionCourante = 0;

    #[Groups(['snapshot_terminal:read'])]
    public int $page = 1;

    #[Groups(['snapshot_terminal:read'])]
    public int $taillepage = 500;

    #[Groups(['snapshot_terminal:read'])]
    public bool $pageSuivante = false;

    /** @var list<array<string, mixed>> */
    #[Groups(['snapshot_terminal:read'])]
    public array $entrees = [];
}
