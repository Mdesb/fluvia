<?php

declare(strict_types=1);

namespace App\Patinoire\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Patinoire\State\ConflitGlaceProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Chevauchement détecté entre deux créneaux glace (RG-PAT-03, décision actée « surbooking de la
 * glace », US-PATIN-09, CA-9), exposé **en lecture seule** au gestionnaire (`patinoire.arbitrer_surbooking`).
 * Aucun blocage n'est appliqué à l'écriture côté réservation (§4.8) : ce DTO ne fait que
 * **signaler** ; la résolution (contact client, réaffectation, remboursement) reste 100 % manuelle,
 * hors périmètre applicatif. Non-Doctrine, jamais persisté (même patron que
 * `App\Padel\ApiResource\ClassementTournoi`).
 */
#[ApiResource(
    shortName: 'PatinoireConflitGlace',
    operations: [
        new Get(
            uriTemplate: '/patinoire/conflits-glace',
            security: "is_granted('PERM', 'patinoire.arbitrer_surbooking')",
            provider: ConflitGlaceProvider::class,
            normalizationContext: ['groups' => ['conflit_glace:read']],
        ),
    ],
)]
final class ConflitGlace
{
    #[ApiProperty(identifier: true)]
    #[Groups(['conflit_glace:read'])]
    public string $id = 'conflits-glace';

    /**
     * @var list<array{
     *     ressource: string,
     *     creneauA: array{id: string, debut: string, fin: string, publicReserve: ?string},
     *     creneauB: array{id: string, debut: string, fin: string, publicReserve: ?string}
     * }>
     */
    #[Groups(['conflit_glace:read'])]
    public array $conflits = [];
}
