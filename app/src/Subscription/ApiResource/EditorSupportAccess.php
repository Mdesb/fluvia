<?php

declare(strict_types=1);

namespace App\Subscription\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Subscription\State\EditorSupportAccessProvider;
use App\Subscription\State\GrantSupportAccessProcessor;
use App\Subscription\State\RevokeSupportAccessProcessor;

/**
 * Les accès d'assistance ouverts par l'éditeur sur les établissements de ses clients (ED-4, RG-ED-07).
 *
 * **Ce que cette fiche rend praticable, et pourquoi ça compte plus qu'un contrôle de plus.**
 * `SupportAccessGuard` existait depuis des jours et **rien ne permettait d'ouvrir un accès**. Le
 * cloisonnement ordinaire refusant déjà à un agent de l'éditeur l'établissement d'un client, la règle
 * tenait — mais sans chemin praticable.
 *
 * Or le jour où un client appelle parce que sa caisse ne s'ouvre pas, quelqu'un devra regarder ses
 * données. Sans ce chemin, la seule façon est de **donner à l'agent une affectation sur
 * l'établissement du client** : permanente, invisible, indistinguable d'une affectation normale, et
 * que personne ne pensera à retirer. C'est RG-ED-07 obtenu par la porte de service — et pire que si la
 * règle n'existait pas, parce que tout le monde croirait qu'elle protège.
 *
 * **Une règle sans chemin praticable ne tient pas : elle se contourne, et le contournement devient la
 * pratique.**
 *
 * **Une fiche de lecture et deux gestes, pas une entité exposée.** Même choix que pour les offres et
 * la facturation de l'éditeur : la ressource décrit ce qu'on veut montrer, l'entité reste hors de
 * portée du corps de la requête.
 *
 * **La liste ne montre que les accès encore ouverts.** C'est une liste qu'on doit pouvoir **vider**
 * (D55) : elle s'affiche avec le geste qui la traite — révoquer — parce qu'une liste qu'on ne peut pas
 * fermer cesse d'être lue. L'historique complet vit au journal d'audit, qui est fait pour ça.
 */
#[ApiResource(
    shortName: 'EditorSupportAccess',
    operations: [
        new GetCollection(
            uriTemplate: '/editor/support-accesses',
            provider: EditorSupportAccessProvider::class,
        ),
        new Post(
            uriTemplate: '/editor/support-accesses',
            read: false,
            input: false,
            processor: GrantSupportAccessProcessor::class,
        ),
        new Post(
            uriTemplate: '/editor/support-accesses/{id}/revoke',
            read: false,
            input: false,
            processor: RevokeSupportAccessProcessor::class,
        ),
    ],
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
)]
final class EditorSupportAccess
{
    #[ApiProperty(identifier: true)]
    public string $id = '';

    /** Qui peut regarder. Nominatif : un accès d'assistance ne s'accorde pas à un rôle. */
    public string $granteeName = '';

    public string $granteeEmail = '';

    /** Chez quel client. */
    public string $establishmentName = '';

    public string $establishmentId = '';

    /**
     * Pourquoi.
     *
     * Champ libre et obligatoire. Une liste déroulante de motifs produirait « autre » dans la grande
     * majorité des cas ; une phrase écrite se relit six mois plus tard et se justifie devant le client.
     * C'est ce qui distingue une trace d'un formulaire.
     */
    public string $reason = '';

    public ?string $grantedBy = null;

    public string $grantedAt = '';

    public string $expiresAt = '';

    /** Combien de temps il reste, en minutes. Négatif si l'accès a expiré. */
    public int $remainingMinutes = 0;
}
