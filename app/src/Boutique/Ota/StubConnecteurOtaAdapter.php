<?php

declare(strict_types=1);

namespace App\Boutique\Ota;

use App\Boutique\Entity\AllocationQuotaOTA;
use App\Boutique\Entity\PartenaireOTA;
use App\Boutique\Entity\ReversementOTA;
use App\Vente\Entity\Vente;
use Psr\Log\LoggerInterface;

/** Adaptateur par défaut — journalise uniquement, aucune intégration technique par plateforme. */
final class StubConnecteurOtaAdapter implements ConnecteurOtaInterface
{
    /** @var list<array<string, mixed>> journal introspectable en test */
    private array $journal = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function notifierAllocation(AllocationQuotaOTA $allocation): void
    {
        $this->enregistrer('allocation', ['allocation' => (string) $allocation->getId()]);
    }

    public function notifierVenteConfirmee(PartenaireOTA $partenaire, Vente $vente): void
    {
        $this->enregistrer('vente_confirmee', ['partenaire' => (string) $partenaire->getId(), 'vente' => (string) $vente->getId()]);
    }

    public function notifierReversement(ReversementOTA $reversement): void
    {
        $this->enregistrer('reversement', ['reversement' => (string) $reversement->getId()]);
    }

    /** @return list<array<string, mixed>> */
    public function journal(): array
    {
        return $this->journal;
    }

    /** @param array<string, mixed> $donnees */
    private function enregistrer(string $type, array $donnees): void
    {
        $this->journal[] = ['type' => $type] + $donnees;
        $this->logger->info('boutique.ota.' . $type, $donnees);
    }
}
