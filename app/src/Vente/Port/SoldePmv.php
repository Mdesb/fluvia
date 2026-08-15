<?php

declare(strict_types=1);

namespace App\Vente\Port;

/**
 * Objet-valeur miroir minimal du PMV (M4) exposé à M2 — n'expose pas l'entité Doctrine de M4
 * (respect de la frontière modules, §2.1 plan-crm.md). `existe=false` si le client n'a pas de PMV.
 */
final class SoldePmv
{
    public function __construct(
        public readonly bool $existe,
        public readonly string $solde = '0.00',
        public readonly string $statut = 'expire',
        public readonly ?\DateTimeImmutable $dateEcheance = null,
    ) {
    }

    public function estUtilisableEnPaiement(): bool
    {
        return $this->existe && $this->statut === 'actif';
    }
}
