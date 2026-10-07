<?php

declare(strict_types=1);

namespace App\Fonctionnalite\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Fonctionnalite\State\VocabularyProvider;

/**
 * Le vocabulaire résolu, servi au frontend (#100, option B). Une entrée par SOURCE : le défaut FR
 * (`id = default`) et chaque verticale qui déclare son vocabulaire dans son manifeste. L'entrée
 * `courant` marque la verticale de l'établissement quand il n'en a qu'une active — le repli des
 * ressources non taguées.
 *
 * ⚠ Le front NE RECOPIE PAS ces mots : il lit cette table. `specs/verticales/vocabulaire.md` interdit
 * une liste en dur côté front (elle divergerait au premier module ajouté).
 */
#[ApiResource(
    shortName: 'Vocabulary',
    operations: [
        new GetCollection(
            uriTemplate: '/vocabulary',
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            provider: VocabularyProvider::class,
        ),
    ],
)]
final class Vocabulary
{
    #[ApiProperty(identifier: true)]
    public string $id = '';

    /** @var array<string, string> clé courte (resource, slot…) => libellé résolu */
    public array $labels = [];

    /** Cette source est-elle la verticale de l'établissement courant (repli des ressources non taguées) ? */
    public bool $courant = false;
}
