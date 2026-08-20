<?php

declare(strict_types=1);

namespace App\Finance\ExpenseReport\Service;

use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\Journal;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Compta\Regime\CompteLookupService;

/**
 * Résolution des comptes/journaux de la brique notes de frais (§0.5/§0.6 du plan) : réutilise
 * `CompteLookupService` (FIN-1/Régime) tel quel, aucun nouveau moteur. Le compte salarié (421) est
 * **collectif** (RG-M6-13) : le détail par salarié passe par `counterpartyType`/`counterpartyId`/
 * `counterpartyLabel` sur la `LigneEcriture`, jamais un compte 421 par salarié.
 */
final class ResolveurComptesExpenseReport
{
    public function __construct(
        private readonly CompteLookupService $comptes,
    ) {
    }

    public function compteEmploye(ProfilExploitant $profil): CompteComptable
    {
        return $this->comptes->compteParPrefixe($profil, '421');
    }

    public function compteTvaDeductible(ProfilExploitant $profil): CompteComptable
    {
        return $this->comptes->compteParPrefixe($profil, '4456');
    }

    public function compteTresorerie(ProfilExploitant $profil): CompteComptable
    {
        return $this->comptes->compteParPrefixe($profil, '512');
    }

    /** `NDF` — Journal des notes de frais (nouveau code, non seedé par `ComptaFixtures`, §7 point 7 du plan). */
    public function journalNotesDeFrais(ProfilExploitant $profil): Journal
    {
        return $this->comptes->journal($profil, 'NDF');
    }

    /** `BNQ` — Journal des règlements (réutilise le code déjà introduit par FIN-2, pas un nouveau journal). */
    public function journalReglements(ProfilExploitant $profil): Journal
    {
        return $this->comptes->journal($profil, 'BNQ');
    }

    public function tauxHorsChamp(ProfilExploitant $profil): TauxTva
    {
        return $this->comptes->tauxHorsChamp($profil);
    }
}
