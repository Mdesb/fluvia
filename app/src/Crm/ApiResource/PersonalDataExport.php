<?php

declare(strict_types=1);

namespace App\Crm\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Crm\State\PersonalDataExportProvider;

/**
 * Export des données d'une personne (#344) — droit d'accès (RGPD art. 15) et droit à la portabilité
 * (art. 20 : « format structuré, couramment utilisé et lisible par machine »). N'est pas une
 * entité : un document JSON produit à la demande, jamais stocké.
 *
 * Réservé à `crm.rgpd_gerer` : l'export répond à une demande dont l'identité du demandeur a été
 * vérifiée par l'exploitant, comme l'effacement.
 */
#[ApiResource(
    shortName: 'PersonalDataExport',
    operations: [
        new Get(
            uriTemplate: '/clients/{id}/personal-data-export',
            security: "is_granted('PERM', 'crm.rgpd_gerer')",
            provider: PersonalDataExportProvider::class,
        ),
    ],
)]
final class PersonalDataExport
{
    #[ApiProperty(identifier: true)]
    public string $id = '';
}
