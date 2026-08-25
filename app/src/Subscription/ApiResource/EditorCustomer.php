<?php

declare(strict_types=1);

namespace App\Subscription\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Subscription\State\EditorCustomersProvider;

/**
 * La fiche complète d'un client de l'éditeur (ED-6).
 *
 * **Une seule requête, parce que la question qu'on se pose est une seule question.** Quand un client
 * appelle, l'exploitant veut savoir en même temps : qui il est, ce qu'il paie, si son prélèvement
 * tient, si sa plateforme est livrée, et qui de l'assistance a pu regarder chez lui. Répartir cela
 * sur cinq appels obligerait l'écran à assembler — et à afficher des morceaux dans le désordre
 * pendant qu'ils arrivent, ce qui est exactement le moment où l'on se trompe de client.
 *
 * **Ce que la fiche ne porte pas.** Aucune donnée d'exploitation du client : ni ses visiteurs, ni ses
 * ventes, ni ses réservations. L'éditeur vend une plateforme ; il n'a pas à lire ce qui s'y passe.
 * Pour regarder chez un client, il existe un accès d'assistance nominatif, borné et audité (ED-4) —
 * et c'est justement ce que la dernière section de cette fiche donne à voir.
 *
 * **L'IBAN n'apparaît jamais en entier**, seulement ses quatre derniers chiffres : c'est ce qu'il faut
 * pour reconnaître un compte au téléphone, et rien de plus.
 */
#[ApiResource(
    shortName: 'EditorCustomer',
    operations: [
        new GetCollection(uriTemplate: '/editor/customers', provider: EditorCustomersProvider::class),
        new Get(uriTemplate: '/editor/customers/{id}', provider: EditorCustomersProvider::class),
    ],
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
)]
final class EditorCustomer
{
    #[ApiProperty(identifier: true)]
    public string $id = '';

    public string $name = '';

    public ?string $email = null;

    public ?string $phone = null;

    /**
     * Ses abonnements, du plus récent au plus ancien.
     *
     * @var list<array{id: string, planLabel: string, status: string, monthlyPriceCents: int, startedAt: ?string, provisioningStatus: ?string, provisioningFailure: ?string, establishmentName: ?string}>
     */
    public array $subscriptions = [];

    /**
     * Le mandat de prélèvement, s'il en a signé un.
     *
     * @var array{rum: string, last4: string, status: string, signedAt: ?string}|null
     */
    public ?array $mandate = null;

    /**
     * Les accès d'assistance ouverts sur sa plateforme (ED-4, RG-ED-07).
     *
     * **Cette section est le contrôle citoyen de l'accès d'assistance.** Elle rend visible, sur la
     * fiche du client concerné, qui de l'éditeur a pu regarder chez lui, quand, et pourquoi. Un accès
     * qu'on ouvre sans que personne ne le voie finit par ne plus se refermer.
     *
     * @var list<array{grantee: string, reason: string, grantedAt: string, expiresAt: string, revokedAt: ?string, usable: bool}>
     */
    public array $supportAccesses = [];
}
