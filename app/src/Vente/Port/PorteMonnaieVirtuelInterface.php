<?php

declare(strict_types=1);

namespace App\Vente\Port;

use Symfony\Component\Uid\Uuid;

/**
 * Frontière M4 (CRM, hors périmètre L2) : porte-monnaie virtuel utilisable comme moyen de paiement
 * en caisse (RG-M4-03, §2.1 plan-crm.md). En L2, un stub renvoie systématiquement un solde nul/refus
 * pour ne pas bloquer les tests M2 existants qui n'exercent pas le moyen `pmv` en détail ; le câblage
 * réel au module M4 intervient à l'intégration (L5).
 */
interface PorteMonnaieVirtuelInterface
{
    /** Solde courant, statut et échéance du PMV d'un client — null si le client est inconnu du port. */
    public function solde(Uuid $clientId): ?SoldePmv;

    /**
     * Débit atomique : réussit seulement si le PMV est actif et le solde suffisant (RG-M4-03).
     * Journalise un `MouvementPmv(type=debit_vente)` dans la même transaction en cas de succès.
     */
    public function debiter(Uuid $clientId, string $montant, Uuid $venteId): ResultatDebitPmv;

    /**
     * Recrédite le PMV (remboursement d'une vente annulée/remboursée, RG-M4-03 / CA-9). Journalise
     * un `MouvementPmv(type=remboursement_vente)`.
     */
    public function crediter(Uuid $clientId, string $montant, Uuid $venteId, string $motif): void;
}
