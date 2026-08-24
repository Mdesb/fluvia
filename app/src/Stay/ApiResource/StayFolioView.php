<?php

declare(strict_types=1);

namespace App\Stay\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Stay\State\StayFolioProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * La note du séjour : ce que le client doit, ligne à ligne (ACT-3, D16).
 *
 * **Ce n'est pas une entité, et ça ne doit pas en devenir une.** La note est une **vue calculée** au
 * moment où on la demande, jamais un total entretenu quelque part. Un compteur dénormalisé dérive dès
 * la première ligne annulée, corrigée ou rejouée — et un client qui conteste sa note a toujours raison
 * contre un compteur.
 *
 * **Elle expose des données nominatives** — ce qu'un client a consommé, quand. Son cloisonnement est
 * donc traité exactement comme celui du séjour lui-même : `StayFolioProvider` passe par
 * `StayFromRequest`, qui résout et vérifie le périmètre dans le même geste, et le test
 * `CloisonnementStayTest` l'attaque au même titre que les autres opérations.
 */
#[ApiResource(
    shortName: 'StayFolio',
    operations: [
        new Get(
            uriTemplate: '/stays/{id}/folio',
            security: "is_granted('PERM', 'stay.read')",
            provider: StayFolioProvider::class,
            normalizationContext: ['groups' => ['stay_folio:read']],
        ),
    ],
)]
final class StayFolioView
{
    #[ApiProperty(identifier: true)]
    #[Groups(['stay_folio:read'])]
    public string $id = '';

    /** La référence lisible au comptoir — c'est elle qu'on annonce au client, pas l'UUID. */
    #[Groups(['stay_folio:read'])]
    public string $reference = '';

    #[Groups(['stay_folio:read'])]
    public string $status = '';

    /** Le total dû, au format décimal du dépôt (`'153.50'`). */
    #[Groups(['stay_folio:read'])]
    public string $balance = '0.00';

    /**
     * Le nombre de lignes, exposé à part.
     *
     * Un solde de `0.00` est ambigu : « rien consommé » et « un extra compensé par un geste
     * commercial » s'affichent pareil. Le compte de lignes lève l'ambiguïté sans qu'on ait à les lire.
     */
    #[Groups(['stay_folio:read'])]
    public int $lineCount = 0;

    /** @var list<array{label: string, amount: string, occurredAt: string, sourceModule: string}> */
    #[Groups(['stay_folio:read'])]
    public array $lines = [];
}
