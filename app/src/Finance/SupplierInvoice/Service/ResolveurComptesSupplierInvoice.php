<?php

declare(strict_types=1);

namespace App\Finance\SupplierInvoice\Service;

use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\Journal;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Regime\CompteLookupService;

/**
 * Résolution des comptes/journaux de la brique factures fournisseur (§0.5 du plan) : réutilise
 * `CompteLookupService` (FIN-1/Régime) tel quel, aucun nouveau moteur. Le compte fournisseur (401) est
 * **collectif** (RG-M6-13) : le détail par fournisseur passe par `counterpartyType`/`counterpartyId`/
 * `counterpartyLabel` sur la `LigneEcriture`, jamais un compte 401 par fournisseur.
 */
final class ResolveurComptesSupplierInvoice
{
    public function __construct(
        private readonly CompteLookupService $comptes,
    ) {
    }

    public function compteFournisseur(ProfilExploitant $profil): CompteComptable
    {
        return $this->comptes->compteParPrefixe($profil, '401');
    }

    public function compteTvaDeductible(ProfilExploitant $profil): CompteComptable
    {
        return $this->comptes->compteParPrefixe($profil, '4456');
    }

    /** Même compte que `RegimeBase` (ligne 118, déjà en production) — pas une nouvelle convention. */
    public function compteTresorerie(ProfilExploitant $profil): CompteComptable
    {
        return $this->comptes->compteParPrefixe($profil, '512');
    }

    /** `ACH` — Journal des achats (nouveau code, absent de `ComptaFixtures`, cf. §7 point 9 du plan). */
    public function journalAchats(ProfilExploitant $profil): Journal
    {
        return $this->comptes->journal($profil, 'ACH');
    }

    /** `BNQ` — Journal des règlements (distinct de `REG`, journal de la régie). */
    public function journalReglements(ProfilExploitant $profil): Journal
    {
        return $this->comptes->journal($profil, 'BNQ');
    }
}
