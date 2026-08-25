<?php

declare(strict_types=1);

namespace App\Subscription\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Subscription\State\EditorReceivablesProvider;

/**
 * Ce qui reste dû à l'éditeur, facture par facture (ED-8).
 *
 * **Une facture émise n'est pas une facture payée**, et rien dans l'administration ne le montrait :
 * l'écran de facturation dit ce qui a été émis, celui-ci dit ce qui a été encaissé. Sans lui,
 * automatiser l'émission produirait un flot de factures dont personne ne sait lesquelles sont
 * honorées — c'est-à-dire de l'automatisation à l'aveugle.
 *
 * **Le retard est calculé ici, pas laissé à l'écran.** Un nombre de jours calculé côté client dépend
 * de l'horloge du poste et du fuseau du navigateur ; deux exploitants verraient deux retards
 * différents pour la même facture, et le plus optimiste ferait foi.
 */
#[ApiResource(
    shortName: 'EditorReceivable',
    operations: [
        new GetCollection(uriTemplate: '/editor/receivables', provider: EditorReceivablesProvider::class),
    ],
    security: "is_granted('IS_AUTHENTICATED_FULLY')",
)]
final class EditorReceivable
{
    #[ApiProperty(identifier: true)]
    public string $invoiceId = '';

    public ?string $invoiceNumber = null;

    public string $customerName = '';

    public ?string $customerEmail = null;

    public int $totalCents = 0;

    public int $paidCents = 0;

    /** Ce qu'il reste à encaisser. C'est la seule colonne qui compte. */
    public int $remainingCents = 0;

    public ?string $dueDate = null;

    /** Jours de retard, zéro si l'échéance n'est pas passée. Calculé par le serveur. */
    public int $daysLate = 0;

    public string $status = '';
}
