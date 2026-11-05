<?php

declare(strict_types=1);

namespace App\Vente\Nf525;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Signe l'empreinte d'une archive fiscale (#342) avec la clé de scellement NF525 — la même que la
 * chaîne, pour qu'une archive ne puisse pas être refabriquée par qui n'a pas la clé.
 *
 * ⚠ Même réserve que `HashChainSignataire` : HMAC symétrique, à revoir si le référent conformité
 * exige une signature asymétrique. Le jour où ça change, c'est ici et là, pas ailleurs.
 */
final class FiscalArchiveSigner
{
    public function __construct(
        // Aucune valeur par défaut : sans clé, l'application ne démarre pas (même règle que la chaîne).
        #[Autowire(env: 'NF525_SEAL_KEY')]
        private readonly string $key,
    ) {
    }

    public function sign(string $sha256): string
    {
        return hash_hmac('sha256', $sha256, $this->key);
    }

    public function verify(string $sha256, string $signature): bool
    {
        return hash_equals($this->sign($sha256), $signature);
    }
}
