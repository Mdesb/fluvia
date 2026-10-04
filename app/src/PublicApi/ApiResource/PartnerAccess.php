<?php

declare(strict_types=1);

namespace App\PublicApi\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\PublicApi\State\PartnerAccessProcessor;
use App\PublicApi\State\PartnerAccessProvider;

/**
 * Les applications partenaires, vues d'UN établissement : ce qu'il leur a ouvert, et le geste pour
 * l'ouvrir ou le refermer (spec API partenaire v1, §3.1, côté exploitant).
 *
 * L'identifiant est celui de l'APPLICATION, jamais celui d'un accord : l'accord visé est toujours celui
 * de l'établissement actif de la session. Un exploitant de B qui présenterait l'identifiant d'une
 * application accordée par A n'agit donc que sur l'accord de B — il n'existe aucun identifiant qui
 * désignerait celui de A.
 *
 * @sans-suppression: retirer l'accord (`/withdraw`) est la suppression — un accord ne s'efface pas,
 *   il se date, pour que l'établissement puisse relire qui a ouvert ses données et jusqu'à quand.
 */
#[ApiResource(
    shortName: 'PartnerAccess',
    operations: [
        new GetCollection(
            uriTemplate: '/partner-accesses',
            provider: PartnerAccessProvider::class,
        ),
        new Post(
            uriTemplate: '/partner-accesses/{id}/grant',
            name: PartnerAccessProcessor::GRANT,
            read: false,
            input: false,
            processor: PartnerAccessProcessor::class,
        ),
        new Post(
            uriTemplate: '/partner-accesses/{id}/withdraw',
            name: PartnerAccessProcessor::WITHDRAW,
            read: false,
            input: false,
            processor: PartnerAccessProcessor::class,
        ),
    ],
    security: "is_granted('PERM', 'api.gerer')",
)]
final class PartnerAccess
{
    /** L'identifiant de l'application. */
    #[ApiProperty(identifier: true)]
    public string $id = '';

    public string $applicationName = '';

    public string $contactEmail = '';

    /** Fausse quand l'éditeur l'a désactivée : ses clés ne passent plus, quel que soit l'accord. */
    public bool $applicationActive = true;

    /**
     * L'accord en cours sur l'établissement actif — `scopes`, `grantedAt`, `grantedBy` — ou `null`.
     *
     * @var array<string, mixed>|null
     */
    public ?array $grant = null;

    /**
     * Les portées qu'on peut accorder, avec le libellé montré à l'exploitant.
     *
     * @var list<array{value: string, label: string}>
     */
    public array $availableScopes = [];
}
