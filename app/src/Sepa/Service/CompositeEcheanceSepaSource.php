<?php

declare(strict_types=1);

namespace App\Sepa\Service;

use App\Organisation\Entity\Etablissement;
use App\Sepa\Entity\RemiseSepa;
use App\Sepa\Port\EcheanceSepaSource;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Agrège toutes les sources d'échéances SEPA taguées `sepa.echeance_source` (une par verticale
 * branchée : Sport, demain Piscine…), consommée par le point d'entrée générique `POST
 * /sepa/remises/generer` (plan §6). Une verticale dont l'établissement n'a pas d'échéances dues
 * renvoie simplement une liste vide — l'agrégation est donc sans risque de collision.
 */
final class CompositeEcheanceSepaSource implements EcheanceSepaSource
{
    /** @param iterable<EcheanceSepaSource> $sources */
    public function __construct(
        #[AutowireIterator('sepa.echeance_source')]
        private readonly iterable $sources,
    ) {
    }

    public function echeancesDues(Etablissement $etablissement, \DateTimeImmutable $dateExecution): array
    {
        $dues = [];
        foreach ($this->sources as $source) {
            $dues = [...$dues, ...$source->echeancesDues($etablissement, $dateExecution)];
        }

        return $dues;
    }

    public function marquerCollectees(RemiseSepa $remise, array $referencesOrigine): void
    {
        foreach ($this->sources as $source) {
            $source->marquerCollectees($remise, $referencesOrigine);
        }
    }
}
