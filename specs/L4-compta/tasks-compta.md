# Tâches — Comptabilité & Régie (`M6` / lot `L4`, module bi-régime)

- **Plan source :** specs/L4-compta/plan-compta.md

| # | Tâche | Fichiers | Dépend de | Test associé | État |
|---|---|---|---|---|---|
| T1 | Enums Compta (`TypeExploitant`, `ReferentielComptable`, `Qualification`, `SensCompte`, `StatutEcriture`, `StatutPeriode`, `NaturePca`, `MethodePca`, `StatutPayFiP`, `FormatExport`, `StatutExport`, `StatutEnvoi`) | `app/src/Compta/Enum/*.php` | — | — | ✅ |
| T2 | Ports du moteur bi-régime : `RegimeComptableInterface`, DTO (`VenteProjectionDto`, `AvoirProjectionDto`, `EcritureADto`, `LigneEcritureADto`) | `app/src/Compta/Regime/RegimeComptableInterface.php`, `app/src/Compta/Regime/Dto/*.php` | T1 | Unit (contrat, mock) | ✅ |
| T3 | Ports d'intégration : `ProjectionVenteInterface`, `ProjectionPassageInterface`, `ExportComptableInterface`, `PayFipInterface`, `PdpInterface`, `ChorusProInterface` | `app/src/Compta/Port/*.php` | T1 | — | ✅ |
| T4 | Entité `ProfilExploitant` + value object `ParametresRegime` (6 points EXPERT) | `app/src/Compta/Entity/ProfilExploitant.php`, `app/src/Compta/ValueObject/ParametresRegime.php` | T1 | Unit (defaults) | ✅ |
| T5 | Entité `QualificationEquipement` (1 par Espace) | `app/src/Compta/Entity/QualificationEquipement.php` | T4 | API (CRUD) | ✅ |
| T6 | Plan de comptes : `CompteComptable`, `Journal`, `TauxTva`, `MappingComptable` | `app/src/Compta/Entity/{CompteComptable,Journal,TauxTva,MappingComptable}.php` | T4 | API (CRUD, unicité) | ✅ |
| T7 | `MoyenPaiement` (référentiel réel) + `ReferentielReglementDoctrineAdapter` + wiring `services.yaml` (remplace le stub L2) | `app/src/Compta/Entity/MoyenPaiement.php`, `app/src/Compta/Adapter/ReferentielReglementDoctrineAdapter.php`, `app/config/services.yaml` | T1 | API (intégration L2↔L4, §3 plan) | ✅ |
| T8 | `SelecteurReferentielPublic` (choix M4/M57 par équipement, seul point de test SPIC/SPA) | `app/src/Compta/Regime/SelecteurReferentielPublic.php` | T5, T6 | Unit — point EXPERT #1 | ✅ |
| T9 | Implémentation `RegimeRegieDirecte` (+ `#[AutoconfigureTag]`) | `app/src/Compta/Regime/RegimeRegieDirecte.php` | T2, T6, T8 | Unit | ✅ |
| T10 | Implémentation `RegimeDspPcg` (+ points d'extension RAD `calculerRedevance()` non implémentée) | `app/src/Compta/Regime/RegimeDspPcg.php` | T2, T6 | Unit | ✅ |
| T11 | Implémentation `RegimeGroupePrive` (décore `RegimeDspPcg`, axes analytiques obligatoires) | `app/src/Compta/Regime/RegimeGroupePrive.php` | T10 | Unit | ✅ |
| T12 | `RegimeComptableResolver` (itérateur taggé, aucun switch) | `app/src/Compta/Regime/RegimeComptableResolver.php` | T9, T10, T11 | Unit — test d'architecture (§0/§12 plan) | ✅ |
| T13 | Entités écritures : `PeriodeComptable`, `EcritureComptable`, `LigneEcriture`, `LettrageEcriture` (+ champs chaînage NF525 embarqués) | `app/src/Compta/Entity/{PeriodeComptable,EcritureComptable,LigneEcriture,LettrageEcriture}.php` | T6 | Unit (équilibre) | ✅ |
| T14 | `ProjectionVenteDoctrineAdapter` (lecture M2 → DTO, conversion decimal→centimes) | `app/src/Compta/Adapter/ProjectionVenteDoctrineAdapter.php` | T3, T13 | Unit (conversion sans dérive) | ✅ |
| T15 | `MappingComptableGuard` (blocage écriture si mapping incomplet, CA-2) | `app/src/Compta/Service/MappingComptableGuard.php` | T6, T14 | API — CA-2 | ✅ |
| T16 | `GenerateurEcrituresHandler` (génération vente/avoir, ventilation TVA ligne à ligne, RG-M6-05) | `app/src/Compta/Service/GenerateurEcrituresHandler.php` | T12, T14, T15 | API + Unit — CA-7, CA-9 | ✅ |
| T17 | `ScellementEcritureHandler` (réutilise `App\Vente\Nf525\SignataireOperation`/`HashChainSignataire`) + listener d'immuabilité | `app/src/Compta/Nf525/ScellementEcritureHandler.php`, `app/src/Compta/Nf525/EcritureInalterableListener.php` | T16 | Unit + API — CA-13 | ✅ |
| T18 | Lettrage & contrôle (`LettrageHandler`, détection déséquilibre/non lettré/hors période) | `app/src/Compta/Service/LettrageHandler.php` | T17 | API | ✅ |
| T19 | Entités PCA : `EtalementPca`, `MouvementPca` + `compteAttente487()` câblé aux régimes | `app/src/Compta/Entity/{EtalementPca,MouvementPca}.php` | T9, T13 | Unit | ✅ |
| T20 | Dotation PCA à la génération d'écriture (crédite 487 au lieu du produit si `reglePca≠aucune`) | `app/src/Compta/Service/GenerateurEcrituresHandler.php` (extension) | T16, T19 | Unit — CA-8 | ✅ |
| T21 | Reprise prorata temporis (`compta:pca:reprise-mensuelle`) | `app/src/Compta/Command/RepriseMensuellePcaCommand.php` | T20 | Unit — CA-8 | ✅ |
| T22 | `ProjectionPassageDoctrineAdapter` (lecture Accès, cumul uniquement, jamais FMI) + reprise au passage | `app/src/Compta/Adapter/ProjectionPassageDoctrineAdapter.php`, `app/src/Compta/Service/RepriseAuPassageHandler.php` | T20 | Unit — CA-8, RG-ACC-04 | ✅ |
| T23 | `RegieRecettes`, `BordereauVersement` + `RegieHandler` (plafond, alerte, versement→écriture) | `app/src/Compta/Entity/{RegieRecettes,BordereauVersement}.php`, `app/src/Compta/Service/RegieHandler.php` | T9, T17 | API — CA-4, CA-5 | ✅ |
| T24 | `BordereauPayFiP` + `PayFipStubAdapter` + `TraiterRetourPayFipHandler` (webhook + rejeu) + port `NotificationPaiementLigneInterface` côté M2 | `app/src/Compta/Entity/BordereauPayFiP.php`, `app/src/Compta/Adapter/PayFipStubAdapter.php`, `app/src/Compta/Service/TraiterRetourPayFipHandler.php`, `app/src/Vente/Port/NotificationPaiementLigneInterface.php` | T14 | API — CA-6 | ✅ |
| T25 | Port `ExportComptableInterface` + `ExportComptableResolver` (itérateur taggé) | `app/src/Compta/Export/ExportComptableResolver.php` | T3 | Unit | ✅ |
| T26 | `ExportFecAdapter` (réel, 18 champs) + `ControleExportGuard` | `app/src/Compta/Export/ExportFecAdapter.php`, `app/src/Compta/Service/ControleExportGuard.php` | T17, T25 | Unit + API — CA-11 | ✅ |
| T27 | `ExportEtatRegieAdapter` (réel) | `app/src/Compta/Export/ExportEtatRegieAdapter.php` | T23, T25 | API | ✅ |
| T28 | `ExportPesV2HeliosAdapter` (squelette + `genereTitreRegularisation`) | `app/src/Compta/Export/ExportPesV2HeliosAdapter.php` | T25 | API — CA-10 | ✅ |
| T29 | `ExportCielAdapter`, `ExportEbpAdapter`, `ExportSageAdapter`, `ExportCegidAdapter` (squelettes) | `app/src/Compta/Export/Export{Ciel,Ebp,Sage,Cegid}Adapter.php` | T25 | API (masquage par profil) | ✅ |
| T30 | `ExportComptable` entité + handler de génération (contrôle → statut → fichier) | `app/src/Compta/Entity/ExportComptable.php`, `app/src/Compta/Service/GenererExportHandler.php` | T26–T29 | API — CA-10, CA-11 | ✅ |
| T31 | `VenteImpayeeRegie` + `DeclarationEReporting` + `GenerateurEReportingHandler` + `PdpStubAdapter` | `app/src/Compta/Entity/{VenteImpayeeRegie,DeclarationEReporting}.php`, `app/src/Compta/Service/GenerateurEReportingHandler.php`, `app/src/Compta/Adapter/PdpStubAdapter.php` | T16, T30 | API — CA-12 | ✅ |
| T32 | `FactureB2G` + `ChorusProStubAdapter` | `app/src/Compta/Entity/FactureB2G.php`, `app/src/Compta/Adapter/ChorusProStubAdapter.php` | T3 | API | ✅ |
| T33 | Clôture de période : `ClotureGuard` (contrôles bloquants) + `ClotureHandler` (gel, état récap) | `app/src/Compta/Service/{ClotureGuard,ClotureHandler}.php` | T18, T23 | API — CA-14 | ✅ |
| T34 | `RAD`, `Redevance` (points d'extension, squelettes non implémentés) | `app/src/Compta/Entity/{Rad,Redevance}.php` | T10 | API (retour "non disponible") | ✅ |
| T35 | Ressources API Platform (toutes entités §9 du plan) : opérations, `security`, groupes de sérialisation, filtres | `app/src/Compta/Entity/*.php` (attributs `#[ApiResource]`), `app/src/Compta/State/*Processor.php` | T4–T34 | API (couverture ressources) | ✅ |
| T36 | Migration structurelle `VersionM6_compta` (tables + index/contraintes) | `app/migrations/VersionM6_compta.php` | T4–T34 | Migration rejouable | ✅ |
| T37 | Migrations de données : `VersionM6_permissions`, `VersionM6_moyens_paiement`, `VersionM6_plan_comptes_base` | `app/migrations/VersionM6_{permissions,moyens_paiement,plan_comptes_base}.php` | T36 | Migration rejouable | ✅ |
| T38 | Tests fonctionnels API + unitaires couvrant CA-1 à CA-15 et l'architecture bi-régime (voir §12 du plan) | `app/tests/Compta/**/*Test.php` | T1–T37 | — | ✅ |

État : ⬜ à faire · 🟦 en cours · ✅ fait · ⛔ bloqué
