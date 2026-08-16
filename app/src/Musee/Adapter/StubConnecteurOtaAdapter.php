<?php

declare(strict_types=1);

namespace App\Musee\Adapter;

use App\Musee\Entity\AllocationQuotaOTA;
use App\Musee\Entity\ReservationOTA;
use App\Musee\Entity\Reversement;
use App\Musee\Port\ConnecteurOtaInterface;

/**
 * Stub du connecteur OTA (décision structurante n°4 du plan) : journalise en mémoire, ne contacte
 * aucune plateforme réelle. Débloque le développement et les tests sans intégration technique
 * (aucune spec `spec-boutique.md`/M3 livrée à ce jour), même rôle que
 * `App\Acces\Projection\StubProjectionDroit`.
 */
final class StubConnecteurOtaAdapter implements ConnecteurOtaInterface
{
    /** @var list<string> Journal des notifications, pour les tests. */
    private array $journal = [];

    public function notifierAllocation(AllocationQuotaOTA $allocation): void
    {
        $this->journal[] = sprintf('allocation:%s', (string) $allocation->getId());
    }

    public function notifierReversement(Reversement $reversement): void
    {
        $this->journal[] = sprintf('reversement:%s', (string) $reversement->getId());
    }

    public function notifierNoShowRecupere(ReservationOTA $reservationOta): void
    {
        $this->journal[] = sprintf('no_show_recupere:%s', (string) $reservationOta->getId());
    }

    /** @return list<string> */
    public function journal(): array
    {
        return $this->journal;
    }
}
