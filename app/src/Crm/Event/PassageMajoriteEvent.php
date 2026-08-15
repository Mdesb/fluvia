<?php

declare(strict_types=1);

namespace App\Crm\Event;

use App\Crm\Entity\Client;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Passage à la majorité d'un bénéficiaire mineur (RG-M4-10, CA-19/20, §3.2 plan-crm.md). Destiné à
 * déclencher la relance de renouvellement du consentement (envoi effectif hors périmètre L5, aucun
 * module notifications spécifié dans ce dépôt).
 */
final class PassageMajoriteEvent extends Event
{
    /**
     * @param list<string> $canauxARenouveler
     */
    public function __construct(
        public readonly Client $client,
        public readonly array $canauxARenouveler,
    ) {
    }
}
