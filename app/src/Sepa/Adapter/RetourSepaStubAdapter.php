<?php

declare(strict_types=1);

namespace App\Sepa\Adapter;

use App\Sepa\Dto\RetourSepaDto;
use App\Sepa\Port\RetourSepaInterface;

/**
 * Adaptateur de retours SEPA par défaut — stub (§4/§9 du plan) : aucun parser pain.002/CAMT.054 réel
 * (le client n'a pas transmis de fichier retour). `injecterRetourDeTest()` permet de simuler un retour
 * banque sans fichier réel, pour les tests. En attendant, `POST /sepa/rejets` (saisie manuelle) reste
 * le point d'entrée opérationnel des rejets.
 */
final class RetourSepaStubAdapter implements RetourSepaInterface
{
    /** @var list<RetourSepaDto> */
    private array $retoursDeTest = [];

    public function relever(\DateTimeImmutable $depuis): array
    {
        $retours = $this->retoursDeTest;
        $this->retoursDeTest = [];

        return $retours;
    }

    /** Réservé aux tests : injecte un retour SEPA simulé, relevé au prochain `relever()`. */
    public function injecterRetourDeTest(RetourSepaDto $retour): void
    {
        $this->retoursDeTest[] = $retour;
    }
}
