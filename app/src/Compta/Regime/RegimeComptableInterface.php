<?php

declare(strict_types=1);

namespace App\Compta\Regime;

use App\Compta\Entity\BordereauVersement;
use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\EtalementPca;
use App\Compta\Entity\Journal;
use App\Compta\Entity\MappingComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\QualificationEquipement;
use App\Compta\Enum\FormatExport;
use App\Compta\Enum\NatureOperation;
use App\Compta\Regime\Dto\AvoirProjectionDto;
use App\Compta\Regime\Dto\EcritureADto;
use App\Compta\Regime\Dto\VenteProjectionDto;

/**
 * Port du moteur bi-régime enfichable (§0 du plan) : toute variation de régime (régie directe / DSP /
 * groupe privé) passe par cette interface, résolue une seule fois par `RegimeComptableResolver` —
 * **seul** point de lecture du discriminant `ProfilExploitant::type` dans tout le module. Les
 * implémentations ne produisent que des DTO : la persistance, l'équilibrage et le scellement NF525
 * sont communs (`GenerateurEcrituresHandler`).
 */
interface RegimeComptableInterface
{
    /** Discriminant d'enregistrement — lu uniquement par le Resolver. */
    public function cle(): \App\Compta\Enum\TypeExploitant;

    /** RG-COMPTA-04 — vente validée M2 → écriture équilibrée (débit encaissement / crédit produit+TVA). */
    public function genererEcritureVente(VenteProjectionDto $vente, ProfilExploitant $profil, MappingResolver $mapping): EcritureADto;

    /** RG-M2-07 pendant côté avoir — extourne équilibrée (contre-passation, jamais de suppression). */
    public function genererEcritureExtourne(\App\Compta\Entity\EcritureComptable $origine, AvoirProjectionDto $avoir): EcritureADto;

    /** RG-M6-10 — encaissement/versement de régie. */
    public function genererEcritureRegie(BordereauVersement $bordereau): EcritureADto;

    /** RG-EXPORT-07 — formats proposés par ce régime (masque les non pertinents). */
    /** @return list<FormatExport> */
    public function formatsExportDisponibles(): array;

    /** Journal cible par nature d'opération (ventes/encaissements/régie/PCA-OD/extourne). */
    public function journalPour(ProfilExploitant $profil, NatureOperation $nature): Journal;

    /** Point EXPERT #1 (§8) — compte de rattachement 487, paramétrable par régime. */
    public function compteAttente487(ProfilExploitant $profil, ?QualificationEquipement $qualif): CompteComptable;

    /** Compte de contrepartie « encaissement » débité à la vente (frontière simple, hors ventilation par moyen). */
    public function compteEncaissement(ProfilExploitant $profil): CompteComptable;

    /**
     * Compte de TVA collectée (unique par profil, numéro 4457* du référentiel actif) ; la ventilation
     * par taux (RG-M6-05) est portée par `LigneEcriture::tauxTva`, pas par un compte distinct.
     */
    public function compteTvaCollectee(ProfilExploitant $profil): CompteComptable;
}
