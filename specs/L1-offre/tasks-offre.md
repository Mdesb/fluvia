# Tâches — Offre & Tarification (`M1` / lot `L1`)

- **Plan source :** specs/L1-offre/plan-offre.md
- **Convention :** namespace `App\Offre\…`, `declare(strict_types=1)`, UUID `symfony/uid`, entités rattachées à `Etablissement` (socle L0). Aucune capacité du socle (droits, audit, contexte) n'est redéfinie.

| # | Tâche | Fichiers | Dépend de | Test associé | État |
|---|---|---|---|---|---|
| T0 | Prérequis : migration socle L0 jouée (tables `etablissement`, `permission`, `role`) — dépendance d'ordre, pas de code M1 | — (socle) | — | `GET /health` vert | ⬜ |
| T1 | Enums métier : `StatutProduit`, `ReglePca`, `Canal`, `AxeCategorie`, `TypePromotion`, `PeriodeQuota`, `PeriodiciteFormule` | src/Offre/Enum/*.php | T0 | (couverts indirectement) | ⬜ |
| T2 | Référentiels : entités `TypeProduit` (facettes, typesCompatibles, defauts), `TypeTarif` (visibilité canal, actif), `Saison` (dates, priorité, récurrence), `Categorie` (axe, arbre), `Promotion` | src/Offre/Entity/{TypeProduit,TypeTarif,Saison,Categorie,Promotion}.php | T1 | CA-9, CA-15 | ⬜ |
| T3 | Agrégat `Produit` + facettes `Formule`, `ServiceInclus`, `CarteMultiEntrees`, `Stock`, `Pool` ; relations ManyToMany `Etablissement`/`Categorie` ; OneToOne facettes | src/Offre/Entity/{Produit,Formule,ServiceInclus,CarteMultiEntrees,Stock,Pool}.php | T2 | CA-3, CA-8 | ⬜ |
| T4 | Grille tarifaire : `GrilleTarifaire` (unique produit×typeTarif×saison, prix nullable ≥0), `PrixHistorique` (append-only), `TrancheQuotientFamilial` | src/Offre/Entity/{GrilleTarifaire,PrixHistorique,TrancheQuotientFamilial}.php | T2 | CA-5, CA-6 | ⬜ |
| T5 | Validateurs d'ensemble : tranches QF (sans trou/chevauchement, résolution unique) ; saisons (non-chevauchement) ; carte (crédité ≥ payé, entiers >0) | src/Offre/Validator/*.php | T3, T4 | CA-6, CA-8, CA-9 | ⬜ |
| T6 | `ResolveurPrix` : prix = produit×typeTarif×saison(+QF), départage par priorité de saison, null = non commercialisé, filtre visibilité canal | src/Offre/Service/ResolveurPrix.php | T4 | CA-5, CA-12, CA-15 | ⬜ |
| T7 | Résolveur de facettes/onglets par type + purge des saisies orphelines à la sauvegarde | src/Offre/Service/ResolveurFacettes.php | T3 | CA-3, RG-M1-02 | ⬜ |
| T8 | Historisation prix : listener/handler créant `PrixHistorique` à chaque modif de `GrilleTarifaire.prix` (non rétroactif) | src/Offre/Service/HistorisationPrixHandler.php | T4 | CA-5 | ⬜ |
| T9 | Décompte quota semaine calendaire (lundi→dimanche, sans report) + simulation « semaine type » | src/Offre/Service/SimulateurQuota.php | T3 | CA-7, RG-M1-12 | ⬜ |
| T10 | Exposition API Platform + groupes sérialisation (`produit:read/write/compta/transition`, type verrouillé après création) + pagination + `SearchFilter`/`OrderFilter` catalogue | src/Offre/Entity/*.php (attributs `#[ApiResource]`), config/ | T3, T4, T6 | CA-1, CA-4 | ⬜ |
| T11 | Cadrage établissement : appliquer l'extension Doctrine du socle (`ContexteEtablissement`, `X-Etablissement`) aux entités `App\Offre` rattachées | config/ + src/Offre/Doctrine/ (réutilise socle) | T10 | RG-SOCLE-05 | ⬜ |
| T12 | Cycle de vie : `TransitionProduitHandler` + `PublicationGuard` (RG-M1-09) ; endpoints `publier`/`archiver`/`reactiver` (`security` via PermissionVoter socle) | src/Offre/State/*.php, src/Offre/Service/{TransitionProduitHandler,PublicationGuard}.php | T10 | CA-10, CA-11 | ⬜ |
| T13 | Action `depublier` + port `DependanceVenteInterface` (stub L1 « aucune dépendance ») : autorisée si pas de vente/dépendance active, sinon 409→archiver ; journalisée | src/Offre/State/DepublierProcessor.php, src/Offre/Port/DependanceVenteInterface.php | T12 | Test « dépublier » (US-L1-09) | ⬜ |
| T14 | Actions de masse (archiver/publier/recatégoriser sélection, confirmation irréversible) | src/Offre/State/ActionsDeMasseProcessor.php | T12 | CA-2 | ⬜ |
| T15 | Duplication : `DupliquerProcessor` (reprend type/tarifs/catégories, code régénéré, statut brouillon, libellé « – copie ») | src/Offre/State/DupliquerProcessor.php | T10 | CA-14 | ⬜ |
| T16 | Conversion assistée : `ConvertirProcessor` + mapping champs (conservés/ajoutés/abandonnés) + confirmation + entité journal `ConversionType` (append-only) | src/Offre/State/ConvertirProcessor.php, src/Offre/Entity/ConversionType.php, src/Offre/Service/MappingConversion.php | T3, T10 | CA-13, RG-M1-11 | ⬜ |
| T17 | Non-suppression des référentiels utilisés : gardes DELETE `TypeTarif`/`Saison` (409 si utilisé, désactivation seule) | src/Offre/State/*.php | T2, T10 | CA-9 | ⬜ |
| T18 | Onglet Compta : groupe `produit:compta` + `security` `offre.modifier_compta` (⚠ hypothèse) sur champs compte/TVA/`reglePca` | src/Offre/Entity/Produit.php (attributs), config/ | T10 | (couvre RG-M1-08, à confirmer M8) | ⬜ |
| T19 | Migration structurelle Doctrine (tables `offre_*` + jointures + index/checks uniques) | migrations/VersionM1_offre.php | T2..T18 | rejouable, `GET /health` vert | ⬜ |
| T20 | Migration de données : `Permission(module='offre', action=…)` (creer/lire/modifier/publier/archiver/gerer/modifier_compta) + fixtures rôles | migrations/VersionM1_permissions.php, src/Offre/DataFixtures/OffreFixtures.php | T19 | CA-5..CA-15 (droits) | ⬜ |
| T21 | Tests fonctionnels API (ApiTestCase) : CA-1, CA-2, CA-4, CA-5, CA-8..CA-11, CA-13, CA-14, dépublier, cloisonnement | tests/Offre/Api/*.php | T10..T20 | CA-1,2,4,5,8,9,10,11,13,14 | ⬜ |
| T22 | Tests unitaires : `ResolveurPrix` (CA-12,15), tranches QF (CA-6), quota semaine (CA-7), facettes (CA-3), validateurs (T5) | tests/Offre/Unit/*.php | T5, T6, T7, T9 | CA-3,6,7,12,15 | ⬜ |

État : ⬜ à faire · 🟦 en cours · ✅ fait · ⛔ bloqué

## Dépendances externes (rappel)
- **Socle L0** (T0) obligatoire avant T11/T12/T19 : `Etablissement`, `PermissionVoter`, `ContexteEtablissement`, `EntreeAudit` réutilisés tels quels.
- **M2/M5** : `DependanceVenteInterface` (T13) et `ServiceInclus.activiteRef` (T3) restent des stubs/refs logiques en L1.
- **M6** : `reglePca` et axe comptable portés (T18) sans exécution PCA/TVA.
