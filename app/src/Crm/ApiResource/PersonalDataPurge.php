<?php

declare(strict_types=1);

namespace App\Crm\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\Crm\State\ApplyPersonalDataPurgeProcessor;
use App\Crm\State\PreviewPersonalDataPurgeProcessor;
use App\Crm\State\RetentionRuleProvider;
use App\Crm\State\SaveRetentionRuleProcessor;

/**
 * Épuration des données personnelles et durée de conservation (#344). N'est pas une entité : point
 * d'ancrage de quatre gestes, tous bornés à la collectivité de l'établissement actif
 * (`CustomerGroupGuard`).
 *
 *  - `POST /rgpd/purge/preview` : ce qu'une épuration toucherait — n'écrit rien ;
 *  - `POST /rgpd/purge`         : anonymise les fiches cochées dans l'aperçu, re-vérifiées une à une ;
 *  - `GET  /rgpd/retention-rule`: la durée de conservation de la collectivité ;
 *  - `POST /rgpd/retention-rule`: la fixer (appliquée par la tâche `crm:rgpd:appliquer-conservation`).
 *
 * @sans-suppression: aucune donnée propre n'est créée ici — les POST lisent ou anonymisent des
 * fiches existantes, et une règle de conservation ne se supprime pas : on la règle (une collectivité
 * sans durée n'en applique aucune).
 */
#[ApiResource(
    shortName: 'PersonalDataPurge',
    operations: [
        new Post(
            uriTemplate: '/rgpd/purge/preview',
            read: false,
            input: false,
            deserialize: false,
            security: "is_granted('PERM', 'crm.rgpd_gerer')",
            processor: PreviewPersonalDataPurgeProcessor::class,
        ),
        new Post(
            uriTemplate: '/rgpd/purge',
            read: false,
            input: false,
            deserialize: false,
            security: "is_granted('PERM', 'crm.rgpd_gerer')",
            processor: ApplyPersonalDataPurgeProcessor::class,
        ),
        new Get(
            uriTemplate: '/rgpd/retention-rule',
            security: "is_granted('PERM', 'crm.rgpd_gerer') or is_granted('PERM', 'crm.parametrer')",
            provider: RetentionRuleProvider::class,
        ),
        new Post(
            uriTemplate: '/rgpd/retention-rule',
            read: false,
            input: false,
            deserialize: false,
            security: "is_granted('PERM', 'crm.parametrer')",
            processor: SaveRetentionRuleProcessor::class,
        ),
    ],
)]
final class PersonalDataPurge
{
    #[ApiProperty(identifier: true)]
    public string $id = 'purge';
}
