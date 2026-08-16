<?php

declare(strict_types=1);

namespace App\Boutique\Paiement;

use Symfony\Component\Uid\Uuid;

/**
 * Paiement en ligne commuté PayFiP / PSP CB (US-L8-07, RG-M3-11). Chaque implémentation est taguée
 * `boutique.paiement_en_ligne` et résolue par `SelecteurPaiementEnLigne` selon
 * `ProfilExploitant.type` (RG-M6-01) — seul point de lecture de ce discriminant côté Boutique.
 */
interface PaiementEnLigneInterface
{
    /** Discriminant d'enregistrement — lu uniquement par le Sélecteur. */
    public function cle(): \App\Compta\Enum\TypeExploitant;

    public function initierPaiement(Uuid $venteId, int $montantCentimes, string $urlRetour): InitiationPaiementEnLigne;

    /** @param array<string, mixed> $donneesRetour */
    public function traiterRetour(array $donneesRetour): ResultatRetourPaiementEnLigne;
}
