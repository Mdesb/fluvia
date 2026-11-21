<?php

declare(strict_types=1);

namespace App\Platform\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Platform\State\Nf525AlertViewProvider;

/**
 * Les alertes « chaîne fiscale bloquée » vues par l'administrateur de l'établissement (NF525, G-5).
 *
 * **Lecture seule, et sans détail technique.** Aucune opération d'écriture n'est déclarée : POST,
 * PATCH et DELETE rendent 405. Les champs exposés sont EXACTEMENT ceux ci-dessous ; `technicalDetail`,
 * `note` (Q-4 : elle peut citer du technique), `detectionCount`, `origin` et les identifiants de chaîne
 * n'ont pas de propriété ici, donc aucune sérialisation accidentelle ne peut les rendre (risque R-6).
 *
 * Le cloisonnement est dans le fournisseur : l'établissement actif seul, jamais « tout ».
 */
#[ApiResource(
    shortName: 'Nf525Alert',
    operations: [
        new GetCollection(uriTemplate: '/nf525-alerts', provider: Nf525AlertViewProvider::class),
        new Get(uriTemplate: '/nf525-alerts/{id}', provider: Nf525AlertViewProvider::class),
    ],
    security: "is_granted('PERM', 'securite.gerer')",
)]
final class Nf525AlertView
{
    #[ApiProperty(identifier: true)]
    public string $id = '';

    /** Donnée au caissier : `NF525-` + 8 hexadécimaux. */
    public string $reference = '';

    public string $chainType = '';

    /** Libellé public (« Caisse X »). */
    public string $chainLabel = '';

    public string $cause = '';

    /** La cause en français, en clair. */
    public string $causeLabel = '';

    public string $status = '';

    public string $firstEventAt = '';

    public string $lastEventAt = '';

    public int $refusalCount = 0;

    public ?string $resolvedAt = null;
}
