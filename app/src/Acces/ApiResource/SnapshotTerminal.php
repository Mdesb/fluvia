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

    /**
     * @var list<array<string, mixed>>
     */
    // T21 (plan-acces-terminal.md §7 Lot E) : `entrees` est typé `array<string, mixed>` (envelope
    // calculée par le provider depuis `EntreeSnapshotDto`) — API Platform ne peut pas inférer la forme
    // d'un item. On la déclare pour que l'OpenAPI généré documente le contenu d'une entrée de snapshot.
    #[ApiProperty(openapiContext: [
        'type' => 'array',
        'description' => 'Droits d\'accès projetés pour la borne (delta ou complet selon `versionCourante`).',
        'items' => [
            'type' => 'object',
            'properties' => [
                'identifiant' => ['type' => 'string', 'description' => 'Identifiant du support (QR/badge).'],
                'revoque' => ['type' => 'boolean', 'description' => 'Support bloqué/révoqué : la borne doit refuser localement.'],
                'nomPorteur' => ['type' => 'string', 'nullable' => true, 'description' => 'Toujours `null` en v1 (R-3).'],
                'numeroBillet' => ['type' => 'string', 'nullable' => true],
                'typeSupport' => ['type' => 'string', 'nullable' => true],
                'typeDroit' => ['type' => 'string', 'nullable' => true, 'description' => 'App\\Acces\\Enum\\TypeDroitAcces.'],
                'compostagesRestants' => ['type' => 'integer', 'nullable' => true, 'description' => 'Renseigné pour une carte à quota.'],
                'validiteDebut' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                'validiteFin' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                'portesEligibles' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Identifiants des équipements/portes autorisés pour ce droit.'],
                'sousReseauId' => ['type' => 'string', 'nullable' => true],
                'versionMaj' => ['type' => 'integer', 'description' => 'Version de mise à jour du support (curseur du delta).'],
            ],
        ],
    ])]
    #[Groups(['snapshot_terminal:read'])]
    public array $entrees = [];
}
