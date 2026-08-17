<?php

declare(strict_types=1);

namespace App\Personnel\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Personnel\State\RosterProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Roster hebdomadaire (§4.5 spec, US-PERSO-04, CA-6) : vue de restitution agrégeant `CreneauTravail`
 * et ses `AffectationTravail` — **pas un objet de données propre**. Affiche pour chaque créneau le
 * statut de couverture (complet/sous-couvert/conflit) et signale une qualification manquante/expirée.
 */
#[ApiResource(
    shortName: 'RosterHebdomadaire',
    operations: [
        new GetCollection(
            uriTemplate: '/personnel/roster',
            paginationEnabled: false,
            security: "is_granted('PERM', 'personnel.lire')",
            provider: RosterProvider::class,
        ),
    ],
    normalizationContext: ['groups' => ['roster:read']],
)]
final class RosterHebdomadaire
{
    #[ApiProperty(identifier: true)]
    #[Groups(['roster:read'])]
    public string $id = '';

    #[Groups(['roster:read'])]
    public string $etablissement = '';

    #[Groups(['roster:read'])]
    public ?string $espace = null;

    #[Groups(['roster:read'])]
    public string $poste = '';

    #[Groups(['roster:read'])]
    public string $debut = '';

    #[Groups(['roster:read'])]
    public string $fin = '';

    #[Groups(['roster:read'])]
    public ?string $qualificationRequise = null;

    #[Groups(['roster:read'])]
    public int $effectifRequis = 1;

    /** @var list<string> Noms « Prénom Nom » des employés affectés (statut non annulé). */
    #[Groups(['roster:read'])]
    public array $employesAffectes = [];

    /** complet | sous_couvert | conflit */
    #[Groups(['roster:read'])]
    public string $statutCouverture = 'complet';

    #[Groups(['roster:read'])]
    public bool $qualificationManquanteOuExpiree = false;
}
