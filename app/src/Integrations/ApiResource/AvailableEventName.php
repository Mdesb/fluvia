<?php

declare(strict_types=1);

namespace App\Integrations\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Integrations\State\AvailableEventNamesProvider;

/**
 * LES ÉVÉNEMENTS AUXQUELS UNE DESTINATION PEUT S'ABONNER.
 *
 * ⚠ **CETTE LISTE EXISTE PARCE QU'UNE SAISIE LIBRE SERAIT UN PIÈGE.** Le nom d'un événement est la
 * clé d'abonnement du bus, et `EventName` le dit dans ses propres termes : « une faute de frappe ne
 * provoquerait aucune erreur — l'abonné ne serait simplement jamais appelé, en silence ».
 *
 * Un exploitant qui taperait `booking.confirme` au lieu de `booking.confirmed` aurait donc une
 * destination qui a l'air configurée, qui ne signale rien, et qui n'enverra jamais rien. On ne
 * découvre ce genre de réglage qu'en attendant une alerte qui ne vient pas.
 *
 * ⚠ **LA SOURCE EST LE REGISTRE DES MODULES, PAS UNE LISTE RECOPIÉE.** Les manifestes déclarent ce
 * qu'ils émettent ; c'est cette union qu'on sert. Une liste tenue à la main dans le frontal aurait
 * divergé au premier module ajouté par une autre session — et la divergence se serait vue, là
 * encore, sous la forme d'un silence.
 */
#[ApiResource(
    shortName: 'IntegrationAvailableEventName',
    operations: [
        new GetCollection(
            uriTemplate: '/integrations/evenements-disponibles',
            security: "is_granted('PERM', 'connecteurs.lire')",
            provider: AvailableEventNamesProvider::class,
        ),
    ],
)]
final class AvailableEventName
{
    #[ApiProperty(identifier: true)]
    public string $nom = '';

    /** Le domaine, première moitié du nom — sert à grouper l'écran. */
    public string $domaine = '';

    /** Le module qui l'émet, pour que l'exploitant sache de quoi on parle. */
    public string $module = '';
}
