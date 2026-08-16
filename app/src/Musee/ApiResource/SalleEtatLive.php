<?php

declare(strict_types=1);

namespace App\Musee\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Musee\State\SalleEtatLiveProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Tableau de bord temps réel du sous-quota d'une salle (US-MUSEE-02, CA-2) : présents (via `JaugeFmi`
 * L3, lecture seule) / seuil / mode / politique de délestage à appliquer par l'agent mobile.
 */
#[ApiResource(
    shortName: 'MuseeSalleEtatLive',
    operations: [
        new Get(
            uriTemplate: '/musee/salles/{id}/etat',
            security: "is_granted('PERM', 'musee.superviser_salle') or is_granted('PERM', 'acces.superviser')",
            provider: SalleEtatLiveProvider::class,
            normalizationContext: ['groups' => ['salle_live:read']],
        ),
    ],
)]
final class SalleEtatLive
{
    #[ApiProperty(identifier: true)]
    #[Groups(['salle_live:read'])]
    public string $id = '';

    #[Groups(['salle_live:read'])]
    public int $presents = 0;

    #[Groups(['salle_live:read'])]
    public int $seuil = 0;

    #[Groups(['salle_live:read'])]
    public ?string $modeSeuil = null;

    #[Groups(['salle_live:read'])]
    public bool $preAlerteAtteinte = false;

    #[Groups(['salle_live:read'])]
    public bool $seuilAtteint = false;

    #[Groups(['salle_live:read'])]
    public ?string $politiqueDelestageMode = null;

    #[Groups(['salle_live:read'])]
    public ?string $messageAgent = null;
}
