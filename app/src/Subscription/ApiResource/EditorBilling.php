<?php

declare(strict_types=1);

namespace App\Subscription\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Subscription\State\EditorBillingProcessor;
use App\Subscription\State\EditorBillingProvider;

/**
 * L'état de facturation d'un abonnement pour un mois donné (ED-7).
 *
 * **Cette ressource existe pour montrer ce qui N'A PAS eu lieu.** Une liste de factures émises se lit
 * à tête reposée ; ce qui doit sauter aux yeux, c'est l'abonnement actif qu'on a oublié de facturer.
 * Rien d'autre ne le signale — pas d'erreur, pas d'alerte, juste de l'argent qui n'est jamais
 * prélevé, et qu'on découvre en rapprochant les comptes trois mois plus tard.
 *
 * C'est le même raisonnement que la liste d'abonnements, qui remonte en tête les provisionnements
 * échoués : **le manque est plus intéressant que la réussite.**
 *
 * Une ligne par abonnement facturable et par mois, `invoiced` à faux tant que rien n'est émis.
 */
#[ApiResource(
    shortName: 'EditorBilling',
    operations: [
        new GetCollection(uriTemplate: '/editor/billing', provider: EditorBillingProvider::class),
        new Post(
            uriTemplate: '/editor/billing',
            read: false,
            input: false,
            processor: EditorBillingProcessor::class,
        ),
    ],
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
)]
final class EditorBilling
{
    /** L'abonnement concerné : c'est lui qu'on facture, pas la ligne. */
    #[ApiProperty(identifier: true)]
    public string $subscriptionId = '';

    public string $customerName = '';

    public string $planLabel = '';

    /** Le mois concerné, au format `AAAA-MM`. */
    public string $month = '';

    /** Ce que l'abonnement devrait coûter ce mois-ci, d'après le catalogue. */
    public int $expectedCents = 0;

    public bool $invoiced = false;

    public ?string $invoiceNumber = null;

    /**
     * Ce qui a réellement été facturé, quand une facture existe.
     *
     * **Rendu à côté de `expectedCents` et non à sa place**, pour qu'un écart se voie : une facture
     * émise avant un changement de tarif n'a pas le même montant que le catalogue d'aujourd'hui, et
     * c'est une information, pas une erreur à masquer.
     */
    public ?int $invoicedCents = null;

    public ?string $issuedAt = null;
}
