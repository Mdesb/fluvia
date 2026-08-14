<?php

declare(strict_types=1);

namespace App\Vente\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\Vente\State\EtatSynchroProvider;
use App\Vente\State\SynchroOperationsProcessor;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Ressource de synchronisation hors-ligne (RG-M2-08 / US-L2-12). N'est pas une entité Doctrine :
 * expose l'indicateur d'état (en ligne / dégradé / synchro en cours) et le point d'ancrage du lot
 * d'opérations remontées depuis un poste hors-ligne (rejeu chronologique, anti-doublon idempotent).
 */
#[ApiResource(
    shortName: 'Synchronisation',
    operations: [
        new Get(
            uriTemplate: '/synchro/etat',
            security: "is_granted('PERM', 'vente.lire')",
            provider: EtatSynchroProvider::class,
            normalizationContext: ['groups' => ['synchro:read']],
        ),
        new Post(
            uriTemplate: '/synchro/operations',
            read: false,
            input: false,
            security: "is_granted('PERM', 'vente.encaisser')",
            processor: SynchroOperationsProcessor::class,
        ),
    ],
)]
final class Synchronisation
{
    #[ApiProperty(identifier: true)]
    #[Groups(['synchro:read'])]
    public string $id = 'etat';

    /** en_ligne | degrade | synchro_en_cours */
    #[Groups(['synchro:read'])]
    public string $etat = 'en_ligne';

    /** Nombre de ventes d'origine hors-ligne enregistrées (informational). */
    #[Groups(['synchro:read'])]
    public int $ventesHorsLigne = 0;
}
