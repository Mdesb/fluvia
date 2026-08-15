<?php

declare(strict_types=1);

namespace App\Recouvrement\Service;

use App\Acces\Entity\DroitAcces;
use App\Organisation\Entity\Etablissement;
use App\Recouvrement\Port\RedevablePort;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Agrège tous les ports `RedevablePort` tagués `recouvrement.redevable_port` (un par verticale
 * branchée : Sport, demain Piscine…), même patron que `App\Sepa\Service\CompositeEcheanceSepaSource`.
 */
final class RedevableRegistry
{
    /** @param iterable<RedevablePort> $ports */
    public function __construct(
        #[AutowireIterator('recouvrement.redevable_port')]
        private readonly iterable $ports,
    ) {
    }

    public function droitAcces(string $typeRedevable, string $referenceRedevable): ?DroitAcces
    {
        return $this->resoudre($typeRedevable)?->droitAcces($referenceRedevable);
    }

    public function etablissement(string $typeRedevable, string $referenceRedevable): ?Etablissement
    {
        return $this->resoudre($typeRedevable)?->etablissement($referenceRedevable);
    }

    public function estLieA(string $typeRedevable, string $referenceRedevable, mixed $utilisateur): bool
    {
        return $this->resoudre($typeRedevable)?->estLieA($referenceRedevable, $utilisateur) ?? false;
    }

    private function resoudre(string $typeRedevable): ?RedevablePort
    {
        foreach ($this->ports as $port) {
            if ($port->typeRedevable() === $typeRedevable) {
                return $port;
            }
        }

        return null;
    }
}
