<?php

declare(strict_types=1);

namespace App\Acces\Hardware;

use App\Acces\Enum\SensPassage;
use Symfony\Component\Uid\Uuid;

/**
 * Une présentation de support à un équipement, telle qu'un pilote la reçoit du matériel (#337).
 *
 * C'est la seule chose qu'un pilote concret (ITBOX, SmartAccess, OSDP…) ait à construire pour
 * remonter un passage : l'identifiant BRUT et le format du lecteur. La normalisation, la décision,
 * l'enregistrement et la commande d'ouverture sont communs (`HardwareEventIngestor`).
 */
final readonly class HardwareRead
{
    public function __construct(
        public Uuid $equipmentId,
        public string $rawIdentifier,
        public CredentialFormat $format,
        public \DateTimeImmutable $readAt,
        /** Clé stable engendrée par le matériel au moment de la lecture : rejouée à l'identique. */
        public Uuid $idempotencyKey,
        public ?SensPassage $direction = null,
    ) {
    }
}
