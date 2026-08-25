<?php

declare(strict_types=1);

namespace App\Subscription\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Subscription\State\OpenCartProcessor;

/**
 * Le panier composé depuis le site vitrine — première étape du tunnel (ED-5, spec §2).
 *
 * **Ce que la réponse rend, et pourquoi ce n'est pas rien.** L'identifiant sert à poursuivre vers le
 * mandat SEPA ; le prix mensuel est renvoyé **calculé par le serveur**, pas repris de ce que la page
 * avait additionné. Une page qui affiche son propre total et un serveur qui en facture un autre est
 * la manière la plus sûre de perdre la confiance d'un client au premier prélèvement.
 *
 * **Rien n'est vendu à cette étape.** L'abonnement naît en brouillon, aucun établissement n'est créé,
 * aucun prélèvement n'est possible. Un prospect qui abandonne ici ne laisse qu'une fiche et un panier
 * — c'est le cas nominal d'un tunnel de vente, pas une anomalie.
 */
#[ApiResource(
    shortName: 'PublicSubscriptionCart',
    operations: [
        new Post(
            uriTemplate: '/editor/carts',
            read: false,
            input: false,
            security: "is_granted('PUBLIC_ACCESS')",
            processor: OpenCartProcessor::class,
        ),
    ],
)]
final class PublicSubscriptionCart
{
    #[ApiProperty(identifier: true)]
    public string $id = '';

    public string $planCode = '';

    /** @var list<string> */
    public array $capabilities = [];

    /** Total mensuel calculé par le serveur : la formule plus les suppléments réellement facturables. */
    public int $monthlyPriceCents = 0;
}
