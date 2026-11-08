<?php

declare(strict_types=1);

namespace App\Vente\Nf525;

use App\Platform\Nf525\SealCheck;
use App\Platform\Nf525\SealKeyring;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Signe l'empreinte d'une archive fiscale (#342) avec la clé de scellement NF525 — la même que la
 * chaîne, pour qu'une archive ne puisse pas être refabriquée par qui n'a pas la clé.
 *
 * ⚠ Même réserve que `HashChainSignataire` : HMAC symétrique, à revoir si le référent conformité
 * exige une signature asymétrique. Le jour où ça change, c'est ici et là, pas ailleurs.
 *
 * #361 — passe par le trousseau de la chaîne de vente : la clé ACTIVE signe ; la vérification essaie
 * la clé active puis les clés retirées. Une rotation ne rend donc pas invérifiable une archive déjà
 * produite, à condition que l'ancienne clé soit gardée au trousseau (conservation six ans,
 * BOI-TVA-DECLA-30-10-30 §155).
 */
final class FiscalArchiveSigner
{
    public function __construct(
        // Aucune valeur par défaut : sans clé active, l'application ne démarre pas (même règle que la chaîne).
        #[Autowire(service: 'app.nf525.keyring.sales')]
        private readonly SealKeyring $keyring,
    ) {
    }

    public function sign(string $sha256): string
    {
        return $this->keyring->signWithoutId($sha256);
    }

    public function verify(string $sha256, string $signature): bool
    {
        return $this->keyring->check($sha256, $signature, null) === SealCheck::Valid;
    }
}
