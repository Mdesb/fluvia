<?php

declare(strict_types=1);

namespace App\Subscription\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Subscription\State\PublicPlansProvider;

/**
 * Les formules en vente, lisibles sans compte — ce que le site vitrine affiche (ED-5).
 *
 * **Une lecture publique du catalogue réel, et non des prix recopiés dans une page.** Un tarif affiché
 * qui diverge du tarif facturé est un écart qu'un prospect relève avant nous, et qu'on ne découvre
 * qu'au premier prélèvement contesté. La vitrine lit donc la même source que la facturation
 * (RG-ED-03, esprit).
 *
 * **Ressource dédiée plutôt qu'exposition de `Plan`.** L'entité porte des champs qui ne regardent
 * personne — l'état d'activation, les identifiants internes — et une ressource publique se juge à ce
 * qu'elle refuse de dire autant qu'à ce qu'elle dit. Ici : un code, un libellé, un prix, les
 * capacités comprises. Rien sur les clients, rien sur les volumes, rien sur les établissements.
 */
#[ApiResource(
    shortName: 'PublicPlan',
    operations: [
        new GetCollection(
            uriTemplate: '/editor/plans',
            security: "is_granted('PUBLIC_ACCESS')",
            provider: PublicPlansProvider::class,
        ),
    ],
)]
final class PublicPlan
{
    #[ApiProperty(identifier: true)]
    public string $code = '';

    public string $label = '';

    public int $monthlyPriceCents = 0;

    /**
     * Les capacités comprises, chacune avec son libellé lisible.
     *
     * **Le code technique accompagne le libellé, il ne le remplace pas.** Le tunnel a besoin du code
     * pour composer le panier ; le visiteur, lui, ne doit jamais voir « controle_acces » sur une page
     * de vente. Renvoyer les deux permet à la page d'afficher l'un et de transmettre l'autre, sans
     * qu'un intégrateur ait à deviner lequel est destiné à l'œil.
     *
     * @var list<array{capability: string, label: string}>
     */
    public array $includedCapabilities = [];
}
