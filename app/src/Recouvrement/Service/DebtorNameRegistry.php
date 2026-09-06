<?php

declare(strict_types=1);

namespace App\Recouvrement\Service;

use App\Recouvrement\Port\DebtorNamePort;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Agrège les `DebtorNamePort` — même patron que `RedevableRegistry` juste à côté.
 *
 * ⚠ UN TYPE SANS PORT REND `null`, ET C'EST UN ÉTAT NORMAL. Tous les types de redevable n'ont pas de
 * module capable de les nommer, et ce sera vrai chaque fois qu'une verticale s'ajoute avant son
 * port. L'écran doit dire « nom non résolu », jamais inventer un libellé : un « client inconnu »
 * ferait croire à une fiche incomplète là où c'est le pont qui manque.
 */
final class DebtorNameRegistry
{
    /** @param iterable<DebtorNamePort> $ports */
    public function __construct(
        #[AutowireIterator('recouvrement.debtor_name_port')]
        private readonly iterable $ports,
    ) {
    }

    public function nameFor(string $debtorType, string $debtorReference): ?string
    {
        foreach ($this->ports as $port) {
            if ($port->debtorType() === $debtorType) {
                return $port->debtorName($debtorReference);
            }
        }

        return null;
    }
}
