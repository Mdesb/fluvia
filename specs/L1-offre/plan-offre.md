# Plan technique — Offre & Tarification (`M1` / lot `L1`)

- **Spec source :** specs/L1-offre/spec-offre.md
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Dépend de :** socle L0 (specs/L0-socle/plan-socle.md) — **réutilisé, non redéfini**
- **Couvre :** US-L1-01 à US-L1-11 · RG-M1-01 à RG-M1-13 · CA-1 à CA-15

> **Réutilisation du socle L0 (à ne pas dupliquer)** — hiérarchie `App\Organisation\Entity\{Groupe,Region,Etablissement,Espace}` (RG-SOCLE-01) ; `PermissionVoter` (attribut `PERM`, sujet `"module.action"`, RG-SOCLE-04) ; service `ContexteEtablissement` (en-tête `X-Etablissement`, RG-SOCLE-05) ; `App\Audit\Entity\EntreeAudit` append-only (RG-SOCLE-07). Toutes les entités M1 ci-dessous portent un `ManyToOne`/`ManyToMany` vers `Etablissement` **du socle** ; aucune ré-implémentation des droits, de l'audit ou du contexte n'est faite ici.

---

## 1. Entités & schéma

Namespace : `App\Offre\Entity\*`. `id` = UUID (`Symfony\Component\Uid\Uuid`, type Doctrine `uuid`). `declare(strict_types=1)` partout (constitution §3). Noms métier en français (constitution §7).

### 1.1 Produit (agrégat racine + facettes)

Choix de conception : **un seul `Produit`** générique (RG-M1-02, modèle unique couvrant les 4 métiers), les facettes spécifiques au type sont des entités liées optionnelles (`Formule`, `CarteMultiEntrees`, `Stock`) plutôt qu'un héritage de table — évite l'explosion de la table et permet la conversion assistée (RG-M1-11) sans changement de classe.

| Entité (`App\Offre\Entity\*`) | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **Produit** | id | `uuid` | non | PK | — |
| | libelle | `json` (i18n {locale:valeur}) | non | 2–120 car. par locale (Assert) | — |
| | code | `string(64)` | non | **unique** `(etablissement, code)` | régénéré à la duplication (US-L1-11) |
| | type | — | non | FK, `nullable:false` | `ManyToOne` → `TypeProduit` |
| | statut | `string(16)` enum backed `StatutProduit` | non | défaut `brouillon` | RG-M1-09 |
| | canaux | `json` set ⊂ {guichet,en_ligne,borne} | non | ≥1 pour publier (garde) | RG-M1-09 |
| | dureeValidite | `dateinterval` | oui | défaut hérité du type | — |
| | noteInterne | `text` | oui | — | — |
| | description | `json` (i18n) | oui | — | onglet visible selon type |
| | couleurCaisse | `string(9)` | oui | hex | — |
| | champsPerso | `json` | oui | — | RG-M1-02 |
| | reglePca | `string(24)` enum `ReglePca` {aucune,etalement,consommation} | non | défaut `aucune` | **porte** la règle, ne l'exécute pas (RG-M1-08) |
| | etablissements | — | — | table jointure `produit_etablissement` | `ManyToMany` → `Etablissement` (socle) — « sites », ≥1 pour publier (RG-M1-09) |
| | categories | — | — | ≤1 par axe (contrainte applicative) | `ManyToMany` → `Categorie` |
| | formule | — | oui | `nullable`, cascade persist/remove | `OneToOne` → `Formule` (si type=abonnement/adhésion) |
| | carte | — | oui | `nullable`, cascade | `OneToOne` → `CarteMultiEntrees` (si type=carnet) |
| | stock | — | oui | `nullable` | `ManyToOne` → `Stock` (dédié ou pool partagé, RG-M1-10) |
| | grilles | — | — | — | `OneToMany` → `GrilleTarifaire` (mappedBy produit) |
| | produitsAssocies | — | oui | self-ref | `ManyToMany` → `Produit` |
| | creeLe / modifieLe | `datetime_immutable` | non | — | timestampable |
| **TypeProduit** | id | `uuid` | non | PK | — |
| | code | `string(48)` | non | **unique** | ~22 types (entree_simple, billet_date, abonnement, adhesion, carte, entrainement, location, sejour, boutique…) |
| | libelle | `string(120)` | non | — | — |
| | facettes | `json` set ⊂ {stock,consommateur,visibilite,carnet,billet,formule,acces} | non | pilote onglets visibles (RG-M1-02) | — |
| | typesCompatibles | — | oui | self-ref M-N | `ManyToMany` → `TypeProduit` — matrice de conversion (RG-M1-11, ⚠ hyp.) |
| | defauts | `json` | oui | marges compostage, TVA, durée par défaut | divulgation progressive (spec §4) |
| **TypeTarif** | id | `uuid` | non | PK | — |
| | nom | `json` (i18n) | non | requis | — |
| | visibiliteCanal | `json` set ⊂ {guichet,en_ligne,borne} | non | RG-M1-07 / CA-15 | — |
| | ordreAffichage | `integer` | non | défaut 0 | — |
| | actif | `boolean` | non | défaut true | désactivable, **non supprimable si utilisé** |
| **Saison** | id | `uuid` | non | PK | — |
| | nom | `string(120)` | non | requis | — |
| | dateDebut / dateFin | `date_immutable` | non | `debut ≤ fin` (Assert + check) | RG-M1-06 |
| | priorite | `integer` | non | requis | départage chevauchement (RG-M1-06/CA-12) |
| | recurrenceAnnuelle | `boolean` | non | défaut false | ⚠ HYPOTHÈSE (projection non spécifiée) |
| | actif | `boolean` | non | défaut true | non supprimable si utilisée |
| **GrilleTarifaire** | id | `uuid` | non | PK | — |
| | produit / typeTarif / saison | — | non | **unique `(produit,typeTarif,saison)`** | `ManyToOne` ×3 (RG-M1-01) |
| | prix | `decimal(10,2)` | **oui** | `≥ 0` (check) ; **null = non commercialisé** | US-L1-03 / CA-5 (null ≠ gratuit) |
| | trancheQf | — | oui | — | `ManyToOne` → `TrancheQuotientFamilial` |
| | historique | — | — | — | `OneToMany` → `PrixHistorique` (append-only) |
| **PrixHistorique** | id, grille, valeur, dateEffet, auteur | `uuid`,FK,`decimal(10,2)`,`datetime_immutable`,`uuid` | non | **append-only** (pas d'update/delete) | audit prix non rétroactif (US-L1-03) |
| **TrancheQuotientFamilial** | id | `uuid` | non | PK | — |
| | typeTarif | — | non | FK | `ManyToOne` → `TypeTarif` |
| | borneMin / borneMax | `decimal(10,2)` | non | contiguës, sans trou ni chevauchement (validation d'ensemble) | US-L1-04 / CA-6 |
| **Formule** | id | `uuid` | non | PK | facette abonnement/adhésion |
| | droitAcces | `json` {mode:illimite\|quota_passages\|plage_horaire, n?, plage?} | non | — | RG-M1-03 |
| | servicesInclus | — | — | cascade | `OneToMany` → `ServiceInclus` |
| | periodicite | `string(16)` enum {mensuel,annuel,personnalise} | non | requise | base prélèvement |
| | sepaActif / jourPrelevement | `boolean` / `smallint` | non/oui | mandat requis (M4/M6) si actif | — |
| | renouvellement | `json` {auto,prix:fixe\|evolutif,nbRenouvellements?,emailNotif} | non | nbRenouv null = tacite | cahier M1-03 |
| | engagement | `json` {dureeMin,pause,resiliation} | oui | — | CGV |
| | dateDebut / dateFin | `date_immutable` | oui | — | US-L1-05 |
| **ServiceInclus** | id, formule | `uuid`, FK | non | — | `ManyToOne` → `Formule` |
| | activiteRef | `uuid` | non | ref logique Activité (M5, non FK dure — hors périmètre) | RG-M1-03 |
| | quota | `integer` | non | `> 0` | RG-M1-12 |
| | periode | `string(24)` enum | non | défaut `semaine_calendaire` (lundi→dimanche, sans report) | RG-M1-12 / CA-7 |
| **CarteMultiEntrees** | id | `uuid` | non | PK | facette carnet |
| | nbPaye | `integer` | non | `> 0` | US-L1-06 |
| | nbCredite | `integer` | non | `≥ nbPaye` (Assert), `> 0` | RG-M1-13 / CA-8 |
| | validiteDuree | `dateinterval` | oui | — | paramétrable |
| | dateButoir | `date_immutable` | oui | — | bonus valable jusqu'à expiration (RG-M1-13) |
| **Promotion** | id | `uuid` | non | PK | — |
| | type | `string(24)` enum {pourcentage,montant,offre_groupee,bonus_10_12} | non | — | RG-M1-04 |
| | valeur | `decimal(10,2)` | oui | — | — |
| | conditions | `text` | oui | — | — |
| | dateDebut / dateFin | `date_immutable` | oui | — | US-L1-07 |
| | cumul | `string(12)` enum {cumulable,exclusif} | non | — | — |
| | canaux / eligibilite | `json` | oui | — | — |
| **Categorie** | id | `uuid` | non | PK | — |
| | axe | `string(12)` enum {marketing,comptable,rayon} | non | 3 axes indépendants (RG-M1-05) | — |
| | libelle | `string(120)` | non | — | — |
| | parent | — | oui | self-ref (arbre par axe) | `ManyToOne` → `Categorie` |
| | chemin | `string(255)` | oui | matérialisé (perf) | — |
| **Stock** | id | `uuid` | non | PK | RG-M1-10 |
| | type | `string(12)` enum {dedie,partage} | non | — | — |
| | disponibilite | `integer` | non | `≥ 0` | — |
| | pool | — | oui | si partagé | `ManyToOne` → `Pool` |
| **Pool** | id, libelle, disponibilite | `uuid`,`string`,`integer` | non | décrément mutualisé (RG-M1-10) | `OneToMany` ← Stock/Produit |
| **ConversionType** (journal) | id, produit, ancienType, nouveauType, auteur, dateHeure, mapping | `uuid`,FK,FK,FK,`uuid`,`datetime_immutable`,`json` | non | **append-only** | journalise conversion (RG-M1-11 / CA-13) — complète l'`EntreeAudit` du socle |

> Rattachement multi-entités : **Produit** est rattaché à ≥1 `Etablissement` (M-N « sites »). Les référentiels **TypeTarif / Saison / Categorie / Promotion / TypeProduit** sont portés au niveau `Etablissement` (ou périmètre supérieur) via `ContexteEtablissement` du socle ; leur cloisonnement suit RG-SOCLE-05. Le `code` produit est unique **par périmètre** (`etablissement, code`).

---

## 2. API (API Platform)

Toutes ressources : `#[ApiResource]`, pagination activée (30/page), `security` via le `PermissionVoter` du socle → `is_granted('PERM', 'offre.<action>')`. Lecture cadrée par `ContexteEtablissement` (extension Doctrine du socle appliquée aux entités rattachées). Le surensemble `offre.gerer` (admin) est accordé par le Voter comme couvrant `creer/lire/modifier/publier/archiver` (résolution dans le Voter, pas dupliquée dans chaque `security`).

| Ressource | Opérations | `security:` | Groupes sérialisation | Filtres |
|---|---|---|---|---|
| **Produit** | GET coll, GET item | `is_granted('PERM','offre.lire')` | `produit:read`, `produit:list` | SearchFilter(libelle, code partial ; type, statut exact), catalogue |
| | POST | `is_granted('PERM','offre.creer')` | `produit:write` | — |
| | PATCH | `is_granted('PERM','offre.modifier')` | `produit:write` (type **exclu**, RG-M1-11/CA-4) | — |
| | `POST /produits/{id}/publier` | `is_granted('PERM','offre.publier')` | `produit:transition` | garde RG-M1-09 |
| | `POST /produits/{id}/depublier` | `is_granted('PERM','offre.publier')` | — | garde « aucune vente/dépendance active » (voir §3, arbitrage) |
| | `POST /produits/{id}/archiver` | `is_granted('PERM','offre.archiver')` | — | confirmation (CA-2) |
| | `POST /produits/{id}/reactiver` (archivé→brouillon) | `is_granted('PERM','offre.modifier')` | — | US-L1-09 |
| | `POST /produits/{id}/dupliquer` | `is_granted('PERM','offre.creer')` | `produit:write` | naît brouillon, code régénéré, libellé « – copie » (CA-14) |
| | `POST /produits/{id}/convertir` | `is_granted('PERM','offre.modifier')` | `produit:conversion` | mapping + confirmation + journal (CA-13) |
| | `POST /produits/actions-de-masse` | `is_granted('PERM','offre.modifier')` | — | archiver/publier/recatégoriser sélection (CA-2) |
| **GrilleTarifaire** | GET, POST, PATCH | lire / creer / modifier | `grille:read/write` | SearchFilter(produit, saison, typeTarif) |
| | *(prix compta)* PATCH champ `reglePca`/compte via Produit | `is_granted('PERM','offre.modifier_compta')` ⚠ hyp. | `produit:compta` | onglet Compta (comptable) |
| **TypeTarif** | GET, POST, PATCH ; DELETE **refusé si utilisé** (409) | lire / gerer | `ref:read/write` | — |
| **Saison** | GET, POST, PATCH ; DELETE refusé si utilisée | lire / gerer | `ref:read/write` | chevauchement refusé (CA-9) |
| **Promotion** | GET, POST, PATCH, DELETE | lire / gerer | `ref:read/write` | — |
| **Categorie** | GET, POST, PATCH, DELETE | lire / gerer | `cat:read/write` | TreeFilter par axe |
| **TypeProduit** | GET (coll/item) | lire | `type:read` | — (référentiel administré) |
| **ConversionType** | GET seulement | lire | `conv:read` | — (journal, pas d'écriture API) |
| **PrixHistorique** | GET seulement | lire | `prix:read` | append-only |

- `security` d'écriture des **référentiels** (TypeTarif, Saison, Promotion, Categorie) : `offre.gerer` (admin) — cf. acteur Administrateur de la spec §3.
- Groupes de sérialisation : UUID exposé ; `produit:compta` isole les champs comptables (compte, TVA, `reglePca`) pour l'onglet Compta ; le champ `type` est en lecture seule après création (retiré du groupe `produit:write`, présent en `produit:read`).
- Catalogue (CA-1) : pagination + tri (`OrderFilter` sur libelle, prix, statut, modifieLe) + filtres cumulables ; la persistance des filtres entre visites est **côté client/UI** (hors périmètre API, noté en risque).

---

## 3. Sécurité & droits

- **Permissions requises (module `offre`)** : `offre.creer`, `offre.lire`, `offre.modifier`, `offre.publier`, `offre.archiver`, `offre.gerer` (surensemble admin), `offre.modifier_compta` (⚠ HYPOTHÈSE, onglet Compta comptable — à arbitrer avec M8).
- **Voter** : **aucun voter nouveau** — réutilise `PermissionVoter` du socle (attribut `PERM`, sujet `"offre.action"`, RG-SOCLE-04). Le mapping des permissions dans les tables `Permission`/`Role` est ajouté par **fixtures/migration de données** (module `offre` × actions ci-dessus).
- **Cadrage établissement** : `ContexteEtablissement` (en-tête `X-Etablissement`) du socle ; une extension Doctrine (celle du socle, étendue aux entités `App\Offre`) filtre les collections sur les établissements affectés (RG-SOCLE-05 / cas limite « utilisateur sans affectation »).
- **Cycle de vie produit** — champ `statut` (enum `StatutProduit`) + **gardes de transition** dans un service `TransitionProduitHandler` (pas de bundle Workflow pour rester léger et testable ; noté en risque comme alternative). Transitions autorisées :
  - `brouillon → publié` : garde `PublicationGuard` vérifiant RG-M1-09 (libellé + ≥1 site + ≥1 canal + ≥1 prix valide non-null + catégorie axe comptable). Échec → 422 listant **précisément** chaque prérequis manquant (CA-11).
  - `publié → archivé` : toujours autorisé (produit archivé = non vendable, reste consultable/historisé, CA-11).
  - `publié → brouillon` (**dépublier**) : **arbitrage appliqué (US-L1-09)** — voir décision ci-dessous.
  - `archivé → brouillon` (réactivation) : autorisé (permet de remettre en chantier).
- **DÉCISION DE CONCEPTION — « dépublier » (arbitrage US-L1-09)** : pas de retour **direct** `publié → brouillon` par simple édition. Une action explicite `depublier` existe, autorisée **uniquement si le produit n'a aucune vente ni dépendance active** (pas de commande/abonnement/carte en cours le référençant — vérifié via un port `DependanceVenteInterface` implémenté plus tard par M2/M5, stub « aucune dépendance » en L1). Si des dépendances existent, la dépublication directe est refusée (409) et l'API oriente vers `archiver`. Journalisé (`EntreeAudit`). Cela concilie le cahier détaillé (action « Dépublier » existante) et US-L1-09 (pas de régression silencieuse vers brouillon).

---

## 4. Migrations

- **Migration structurelle** `VersionM1_offre` : crée toutes les tables `offre_*` (produit, type_produit, type_tarif, saison, grille_tarifaire, prix_historique, tranche_qf, formule, service_inclus, carte_multi_entrees, promotion, categorie, stock, pool, conversion_type) + tables de jointure (`produit_etablissement`, `produit_categorie`, `produit_produit_associe`, `type_produit_compatibles`).
- **Index / contraintes** : unique `(etablissement_id, code)` sur produit ; unique `(produit_id, type_tarif_id, saison_id)` sur grille ; unique `type_produit.code` ; check `prix >= 0`, `saison.date_debut <= date_fin`, `carte.nb_credite >= nb_paye`. FK vers `etablissement` (table socle) — **la migration M1 suppose la migration socle jouée d'abord** (dépendance d'ordre).
- **Migration de données** `VersionM1_permissions` : insère les `Permission(module='offre', action=…)` pour `creer/lire/modifier/publier/archiver/gerer/modifier_compta`.
- Rejouables, versionnées Doctrine ; jamais de `schema:update --force` (constitution §7).

---

## 5. Tests (PHPUnit + ApiTestCase)

| Test | Type | Couvre |
|---|---|---|
| Catalogue : recherche libelle/code + filtres cumulables + tri colonnes | API | CA-1 |
| Actions de masse limitées à la sélection + confirmation archivage | API | CA-2 |
| Facettes/onglets selon type + pas de saisie orpheline sur onglet masqué | Unit (résolveur facettes) | CA-3, RG-M1-02 |
| `type` verrouillé en PATCH après création (400/champ ignoré) | API | CA-4, RG-M1-11 |
| Grille : case vide = non commercialisé (null ≠ gratuit), prix ≥ 0, chevauchement saison refusé, historisation prix | API + Unit | CA-5, RG-M1-01 |
| Tranches QF : refus trou/chevauchement, résolution unique d'une valeur QF | Unit (validateur d'ensemble) | CA-6, US-L1-04 |
| Service inclus : quota remis à zéro lundi (semaine calendaire, sans report) + simulation semaine type | Unit | CA-7, RG-M1-12 |
| Carte 10=12 : stock initial 12, crédité ≥ payé, entiers > 0, validité paramétrable | API + Unit | CA-8, RG-M1-13 |
| Référentiels : libellé unique, saison sans chevauchement, non-suppression si utilisé (409/désactivation) | API | CA-9, US-L1-07 |
| Publication bloquée sans catégorie comptable ; marketing/rayon facultatifs | API | CA-10, RG-M1-05 |
| Publication : garde complète (libellé+site+canal+prix+cat compta) sinon liste des blocages ; transitions autorisées uniquement | API | CA-11, RG-M1-09 |
| Résolution de prix : saison priorité supérieure sur chevauchement | Unit (`ResolveurPrix`) | CA-12, RG-M1-06 |
| Conversion assistée : mapping conservés/ajoutés/abandonnés + confirmation + journal `ConversionType` | API | CA-13, RG-M1-11 |
| Duplication : type/tarifs/catégories repris, code régénéré, statut brouillon, libellé « – copie » | API | CA-14, US-L1-11 |
| Visibilité canal : tarif « guichet uniquement » absent du canal en ligne | Unit + API | CA-15, RG-M1-07 |
| Dépublier : refusé si dépendance active (409 → archiver), autorisé sinon, journalisé | API | Décision « dépublier » (US-L1-09) |
| Cloisonnement : utilisateur sans affectation sur l'étab du produit → 403/absent | API | RG-SOCLE-05 (socle réutilisé) |

---

## 6. Tâches (voir tasks-offre.md)

T1 référentiels & enums → T2 Produit + facettes → T3 grille/QF/résolveur prix → T4 formule/carte → T5 API+sérialisation+filtres → T6 cycle de vie & gardes → T7 conversion/duplication → T8 permissions+migrations → T9 tests. (ordonnées, cf. fichier tasks.)

---

## 7. Risques / à valider

1. **DÉCISION appliquée — « dépublier »** : action `depublier` conditionnée à l'absence de vente/dépendance active, sinon `archiver` (US-L1-09). Le port `DependanceVenteInterface` est un **stub** en L1 (renvoie « aucune dépendance ») ; à câbler réellement par M2/M5. **Décision métier requise** sur l'existence même du « dépublier » direct dans l'UI.
2. **⚠ HYPOTHÈSE — `offre.modifier_compta`** : permission fine onglet Compta (comptable vs gestionnaire) créée mais nom/portée à arbitrer avec M8/M6 (spec §3).
3. **⚠ HYPOTHÈSE — récurrence annuelle des saisons** : champ `recurrenceAnnuelle` porté mais **mécanique de projection non implémentée** (résolveur de prix ne gère que les dates absolues en L1) ; à préciser avec le métier.
4. **⚠ HYPOTHÈSE — matrice des types compatibles** (RG-M1-11) : modélisée via `TypeProduit.typesCompatibles` (self M-N) mais **contenu à définir avec le métier** avant activation de la conversion ; l'assistant ne propose que les types présents dans cette relation.
5. **⚠ HYPOTHÈSE — solde de compostages perdu à l'expiration** : traitement comptable/PCA relève de M6, hors L1 ; M1 se contente de rendre les compostages inutilisables après `dateButoir`.
6. **Workflow vs champ statut** : champ `statut` + gardes retenu (léger, testable) ; `symfony/workflow` reste une alternative si les transitions se complexifient (à réévaluer en L2).
7. **PCA / TVA / plan de comptes** : M1 **porte** `reglePca` et l'axe comptable sans exécuter la reconnaissance ni appliquer la TVA (RG-M1-08) — dépendance M6, ne pas déborder.
8. **Stock partagé (pool)** : décrément mutualisé modélisé ; la concurrence à la vente (rupture simultanée) est un sujet **M2**, pas L1.
9. **Persistance des filtres catalogue entre visites** (CA-1) : côté UI, pas d'API — à confirmer avec M8.
10. **`ServiceInclus.activiteRef`** : référence logique vers Activité (M5) sans FK dure (module hors périmètre) — intégrité applicative, à consolider à l'intégration M5.
