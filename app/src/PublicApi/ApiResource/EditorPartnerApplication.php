<?php

declare(strict_types=1);

namespace App\PublicApi\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\PublicApi\State\EditorPartnerApplicationProcessor;
use App\PublicApi\State\EditorPartnerApplicationProvider;
use App\PublicApi\State\EditorPartnerWebhookProcessor;

/**
 * Les applications partenaires et leurs clés, vues de l'administration éditeur (spec API partenaire v1, §3.1).
 *
 * **Une fiche de lecture et quatre gestes, pas l'entité exposée** — même choix que les accès
 * d'assistance : le corps de la requête n'atteint jamais `PartnerApplication` ni `ApiCredential`.
 *
 * ⚠ **LE PRÉFIXE `/editor/` NE PROTÈGE RIEN.** Le verrou est `EditorOnly::assertEditor()` dans le
 * provider et le processeur (404 hors du tenant éditeur, 403 sans `editor.manage_partner_api`).
 *
 * ⚠ **`issuedSecret` N'EST REMPLI QUE DANS LA RÉPONSE QUI ÉMET LA CLÉ.** Seule l'empreinte est en base :
 * la liste, relue une seconde plus tard, ne peut pas le rendre — c'est la promesse « elle ne sera plus
 * affichée », tenue par construction et non par discrétion.
 *
 * @sans-suppression: une application ne se supprime pas — ses clés et ses accords portent l'historique
 *   de qui a lu quoi ; on la désactive (`/deactivate`), ce qui coupe toutes ses clés.
 */
#[ApiResource(
    shortName: 'EditorPartnerApplication',
    operations: [
        new GetCollection(
            uriTemplate: '/editor/partner-applications',
            provider: EditorPartnerApplicationProvider::class,
        ),
        new Post(
            uriTemplate: '/editor/partner-applications',
            name: EditorPartnerApplicationProcessor::CREATE,
            read: false,
            input: false,
            processor: EditorPartnerApplicationProcessor::class,
        ),
        new Post(
            uriTemplate: '/editor/partner-applications/{id}/deactivate',
            name: EditorPartnerApplicationProcessor::DEACTIVATE,
            read: false,
            input: false,
            processor: EditorPartnerApplicationProcessor::class,
        ),
        new Post(
            uriTemplate: '/editor/partner-applications/{id}/credentials',
            name: EditorPartnerApplicationProcessor::ISSUE,
            read: false,
            input: false,
            processor: EditorPartnerApplicationProcessor::class,
        ),
        // Webhooks (spec §3.3) : l'URL et le secret sont chiffrés au repos, le secret n'est rendu
        // qu'une fois (`issuedWebhookSecret`). Mêmes gardes : `EditorOnly` dans le processeur.
        new Post(
            uriTemplate: '/editor/partner-applications/{id}/webhook',
            name: EditorPartnerWebhookProcessor::CONFIGURE,
            read: false,
            input: false,
            processor: EditorPartnerWebhookProcessor::class,
        ),
        new Post(
            uriTemplate: '/editor/partner-applications/{id}/webhook/rotate-secret',
            name: EditorPartnerWebhookProcessor::ROTATE,
            read: false,
            input: false,
            processor: EditorPartnerWebhookProcessor::class,
        ),
        new Post(
            uriTemplate: '/editor/partner-applications/{id}/webhook/disable',
            name: EditorPartnerWebhookProcessor::DISABLE,
            read: false,
            input: false,
            processor: EditorPartnerWebhookProcessor::class,
        ),
        new Post(
            uriTemplate: '/editor/partner-credentials/{id}/revoke',
            name: EditorPartnerApplicationProcessor::REVOKE,
            read: false,
            input: false,
            processor: EditorPartnerApplicationProcessor::class,
        ),
    ],
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
)]
final class EditorPartnerApplication
{
    #[ApiProperty(identifier: true)]
    public string $id = '';

    public string $name = '';

    public string $contactEmail = '';

    public bool $active = true;

    public string $createdAt = '';

    /**
     * Les clés, la plus récente d'abord : `id`, `prefix`, `status` (`active` | `expired` | `revoked`),
     * `issuedAt`, `expiresAt`, `lastUsedAt`, `revokedAt`, `revokedBy`. Jamais le secret ni son empreinte.
     *
     * @var list<array<string, string|null>>
     */
    public array $credentials = [];

    /** Le secret de la clé qu'on vient d'émettre, dans la seule réponse qui le porte ; `null` partout ailleurs. */
    public ?string $issuedSecret = null;

    public ?string $issuedCredentialId = null;

    /**
     * L'abonnement aux webhooks : `host`, `events`, `active`, `secretRotatedAt`, `pendingOver15Minutes`,
     * `failedDeliveries` (échecs définitifs récents) — ou `null` s'il n'y en a pas.
     *
     * @var array<string, mixed>|null
     */
    public ?array $webhook = null;

    /** @var list<string> le catalogue fermé des événements partenaires (`PartnerEventCatalog::EVENTS`) */
    public array $webhookEvents = [];

    /** Le secret de signature, dans la seule réponse qui le crée ou le régénère ; `null` partout ailleurs. */
    public ?string $issuedWebhookSecret = null;
}
