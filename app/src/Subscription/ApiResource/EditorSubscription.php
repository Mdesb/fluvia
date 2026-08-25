<?php

declare(strict_types=1);

namespace App\Subscription\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Subscription\State\EditorSubscriptionsProvider;

/**
 * Les abonnements vendus par l'éditeur — l'écran de pilotage de son activité (ED-6).
 *
 * **À ne pas confondre avec {@see PublicPlan}.** Celle-ci est l'inverse exact : une ressource
 * réservée, qui porte des noms de clients, des montants et l'état de leur plateforme. Elle ne doit
 * jamais être lisible par un client, ni figurer sur la vitrine.
 *
 * **Un seul tenant y a droit : l'éditeur.** Le contrôle n'est pas une permission mais une identité —
 * voir {@see EditorSubscriptionsProvider}. Une permission se délègue, s'hérite, se recopie dans un
 * rôle modèle ; l'appartenance au tenant éditeur, non.
 */
#[ApiResource(
    shortName: 'EditorSubscription',
    operations: [
        new GetCollection(
            uriTemplate: '/editor/subscriptions',
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            provider: EditorSubscriptionsProvider::class,
        ),
    ],
)]
final class EditorSubscription
{
    #[ApiProperty(identifier: true)]
    public string $id = '';

    /** Ce que l'éditeur lit d'abord : le nom du client, pas son identifiant. */
    public string $customerName = '';

    public ?string $customerEmail = null;

    public string $planCode = '';

    public string $planLabel = '';

    /** `draft`, `active`, `suspended`, `cancelled`. */
    public string $status = '';

    public int $monthlyPriceCents = 0;

    public ?string $startedAt = null;

    /**
     * Où en est la livraison de la plateforme : `pending`, `completed`, `failed`, ou `null` si le
     * provisionnement n'a jamais été demandé (abonnement encore au panier).
     *
     * C'est la colonne qui compte en exploitation : un abonnement actif dont le provisionnement a
     * échoué, c'est un client qui a payé et qui n'a rien.
     */
    public ?string $provisioningStatus = null;

    /** La cause de l'échec, en clair, quand il y en a une. */
    public ?string $provisioningFailure = null;

    public ?string $establishmentName = null;
}
