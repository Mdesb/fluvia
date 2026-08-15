<?php

declare(strict_types=1);

namespace App\Sport\Sepa\Adapter;

use App\Sport\Entity\RemiseSepa;
use App\Sport\Entity\RepresentationSepa;
use App\Sport\Sepa\Dto\ResultatGenerationRemise;
use App\Sport\Sepa\Dto\RetourSepaDto;
use App\Sport\Sepa\Port\CollecteurSepaInterface;

/**
 * Adaptateur SEPA par défaut — stub (Risque n°2 du plan). Génère une référence déterministe, ne
 * transmet rien à une vraie banque. `injecterRetourDeTest()` permet de simuler un retour (rejet ou
 * représentation) sans banque réelle, pour tester le moteur anti-impayés bout en bout.
 */
final class CollecteurSepaStubAdapter implements CollecteurSepaInterface
{
    /** @var list<RetourSepaDto> */
    private array $retoursDeTest = [];

    public function genererRemise(RemiseSepa $remise, array $echeances): ResultatGenerationRemise
    {
        $reference = 'REMISE-' . substr(hash('sha256', (string) $remise->getId() . count($echeances)), 0, 16);
        $total = array_sum(array_map(static fn ($e) => $e->getMontantCentimes(), $echeances));

        return new ResultatGenerationRemise($reference, count($echeances), $total);
    }

    public function transmettre(RemiseSepa $remise): void
    {
        // Aucune transmission bancaire réelle (stub, Risque n°2 du plan).
    }

    public function relerverRetours(\DateTimeImmutable $depuis): array
    {
        $retours = $this->retoursDeTest;
        $this->retoursDeTest = [];

        return $retours;
    }

    public function soumettreRepresentation(RepresentationSepa $representation): void
    {
        // Aucune soumission bancaire réelle (stub).
    }

    /** Réservé aux tests : injecte un retour SEPA simulé, relevé au prochain `relerverRetours()`. */
    public function injecterRetourDeTest(RetourSepaDto $retour): void
    {
        $this->retoursDeTest[] = $retour;
    }
}
