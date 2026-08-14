<?php

declare(strict_types=1);

namespace App\Acces\Dto;

use App\Acces\Enum\SensPassage;
use Symfony\Component\Uid\Uuid;

/**
 * DTO domaine (§2.1) : événement de passage remonté par le matériel (scan au lecteur) ou rejoué à la
 * synchro. Aucun champ propre au protocole matériel (OSDP/REST) n'y transparaît.
 */
final class EvenementPassageDto
{
    public function __construct(
        public readonly Uuid $equipementId,
        public readonly ?string $identifiantSupport,
        public readonly ?SensPassage $sens,
        public readonly \DateTimeImmutable $horodatage,
        public readonly Uuid $cleIdempotence,
        public readonly bool $origineHorsLigne = false,
        /** Réservé à la synchro (§4.6) : ne pas refuser sur une révocation postérieure au passage. */
        public readonly bool $ignorerRevocationSiPosterieure = false,
    ) {
    }
}
