<?php

declare(strict_types=1);

namespace App\Organisation\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\Organisation\State\CompanySearchProvider;
use App\Organisation\State\StructureOnboardingProcessor;

/**
 * OUVRIR UNE STRUCTURE — la porte d'entrée d'un nouveau client.
 *
 * Deux opérations, et elles se répondent :
 *
 *   - `GET /organisation/entreprises?q=…` interroge l'annuaire officiel des entreprises et propose
 *     ce qu'il trouve. Rien n'est enregistré : c'est une aide à la saisie ;
 *   - `POST /organisation/structures` crée d'un geste le groupe, la région, l'établissement,
 *     l'affectation de l'auteur, le point de vente et l'identité légale.
 *
 * Ressource autonome plutôt qu'opération sur `Etablissement` : ce qu'on crée ici n'est pas UN
 * établissement, c'est une structure entière. Le nommer autrement ferait croire qu'un `POST
 * /etablissements` suffit — et c'est précisément ce qui laissait des sites invisibles et
 * invendables.
 */
#[ApiResource(
    shortName: 'Structure',
    operations: [
        // La recherche n'écrit rien : le droit de lire l'organisation suffit.
        new Get(
            uriTemplate: '/organisation/entreprises',
            security: "is_granted('PERM', 'organisation.gerer')",
            provider: CompanySearchProvider::class,
        ),

        new Post(
            uriTemplate: '/organisation/structures',
            security: "is_granted('PERM', 'organisation.gerer')",
            deserialize: false,
            processor: StructureOnboardingProcessor::class,
        ),
    ],
)]
final class Structure
{
    #[ApiProperty(identifier: true)]
    public string $id = '';
}
