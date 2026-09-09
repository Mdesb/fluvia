<?php

declare(strict_types=1);

namespace App\Website\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Website\State\EditorWebsiteProvider;

/**
 * Les neuf activités de D15, avec leurs libellés — en lecture seule.
 *
 * ⚠ **ELLE EXISTE POUR QUE L'ÉCRAN NE LES RÉÉCRIVE PAS.** Les libellés vivent dans
 * {@see \App\Fonctionnalite\Enum\EstablishmentActivity::label()}. Une seconde orthographe en
 * JavaScript ne casserait rien le jour où on l'écrit, et dériverait en silence au premier libellé
 * corrigé : l'écran d'administration proposerait « Réservation de ressource » là où le site vendrait
 * « Réservation de créneaux », et rien ne le signalerait.
 *
 * ⚠ **AUCUNE ÉCRITURE, ET C'EST STRUCTUREL** : il n'y a qu'une `GetCollection`. Le vocabulaire est
 * fermé par D15 — « un paquet qui a besoin d'un dixième type est un SIGNALEMENT, pas une
 * extension ». Une opération d'écriture ici laisserait quelqu'un défaire D15 depuis un écran.
 */
#[ApiResource(
    shortName: 'EditorActivity',
    operations: [
        new GetCollection(uriTemplate: '/editor/website/activities', provider: EditorWebsiteProvider::class),
    ],
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
)]
final class EditorActivity
{
    #[ApiProperty(identifier: true)]
    public string $id = '';

    /**
     * Le libellé, tel que l'énumération le rend — et rien d'autre.
     *
     * ⚠ Pas de description ici : elle n'existe que dans les docblocs de l'énumération, et la
     * recopier dans une propriété en ferait une SECONDE source, exactement le défaut que cette
     * ressource existe pour éviter. L'écran explique la notion une fois, dans l'aide du champ.
     */
    public string $label = '';
}
