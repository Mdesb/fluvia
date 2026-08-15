# Plan technique — Verticale Piscine (`Piscine` / lot `L6`)

- **Spec source :** specs/L6-piscine/spec-piscine.md
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Couvre :** US-L6-01 à US-L6-10 · RG-PISC-01 à RG-PISC-05 · CA-1 à CA-10

> **Réutilisation socle L0 (à ne pas dupliquer)** — hiérarchie `App\Organisation\Entity\{Groupe,Region,
> Etablissement,Espace}` (RG-SOCLE-01) ; `PermissionVoter` (attribut `PERM`, sujet `"module.action"`,
> RG-SOCLE-04) ; `ContexteEtablissement` (RG-SOCLE-05) ; `Utilisateur` (RG-SOCLE-06) ;
> `App\Audit\Doctrine\AuditWriteSubscriber` append-only (RG-SOCLE-07) — **étendu** (liste
> `CLASSES_SURVEILLEES`, fichier socle partagé déjà étendu par L1/L2/L3/L4) avec les entités sensibles
> L6 (T12).
>
> **Réutilisation M1 offre (à ne pas redéfinir)** — `App\Offre\Entity\{Produit,Formule,
> CarteMultiEntrees}` (classes réelles déjà codées, `app/src/Offre/Entity/`). Le catalogue « piscine
> type » (US-L6-01) est **instancié** par un générateur qui **crée des `Produit`/`Formule`/
> `CarteMultiEntrees` standards** via le module Offre — **aucune nouvelle table L6** pour le modèle
> lui-même (§1.7).
>
> **Réutilisation L3 Accès (clé du lot — à ne pas redéfinir)** — `App\Acces\Entity\{EspaceAcces,
> JaugeFmi, Equipement, Support, Appairage}` et `App\Acces\Service\{ValidationPassageHandler,
> RecalageFmiHandler}` (code réel lu, `app/src/Acces/`). **L6 n'ajoute et ne modifie aucun fichier
> `App\Acces\*`** : la POSS piscine est une **spécialisation/pilotage** du mécanisme FMI générique déjà
> livré, via des entités et services **`App\Piscine\*`** additifs (§2).
>
> **Réutilisation M4/CRM (référencée, code non encore livré)** — `App\Crm\Entity\{Famille,
> Beneficiaire}` n'existe pas encore en code (`app/src/Crm/` vide à date, seul `specs/L5-crm/plan-crm.md`
> existe). Le bracelet piscine référence le bénéficiaire par **UUID logique** (`beneficiaireRef`), **sans
> FK dure**, à câbler quand L5 sera codé (même pattern que L3 §1.3 pour M1/M2 avant leur codage).
>
> **Réutilisation M6 Compta/Régie (référencée)** — `App\Compta\Entity\RegieRecettes` existe déjà
> (`app/src/Compta/Entity/RegieRecettes.php`). La caution casier **ne crée pas** de mécanisme de régie
> parallèle : elle référence un mouvement de régie par **UUID logique** (`regieMouvementRef`), le
> rattachement effectif (compte 471/dépôt de garantie, versement) étant **hors périmètre L6** — cf.
> Risque n°8 (mécanisme transverse patinoire à harmoniser).

---

## 0. Principe directeur de l'intégration POSS ↔ FMI (résumé exécutif)

**La règle « une sortie = une entrée » (RG-PISC-01) est déjà entièrement implémentée par L3** : en
mode `EspaceAcces.modeSeuil = blocage`, `ValidationPassageHandler::valider()` (§4.3 étape 8, code réel
`app/src/Acces/Service/ValidationPassageHandler.php`) exécute un `UPDATE … WHERE valeur_courante <
seuil` à l'entrée (0 ligne affectée ⇒ refus `seuil_fmi`) et décrémente `valeur_courante` (min 0) à
chaque sortie. C'est **exactement** la sémantique POSS attendue — **aucune ligne de L3 à modifier**.

Ce que L6 apporte, en pur ajout (`App\Piscine\*`), sans toucher `App\Acces\*` :

1. **`Poss`** — objet piscine qui **pointe** vers un `EspaceAcces` L3 existant (établissement ou bassin
   dédié) et **garantit** que son `modeSeuil` reste `blocage` (jamais `alerte`), via un **listener
   Doctrine additif** (`PossModeSeuilGuard`, §2.2) — pas une modification du fichier `EspaceAcces.php`.
2. **Pré-alerte à X %** — **réutilise tel quel** le champ `EspaceAcces.preAlertePct` (colonne **déjà
   présente** dans L3, `smallint nullable 0..100`, posée comme *hook piscine* dans le plan L3 §4.5 mais
   **jamais lue par aucun code L3** à ce jour). L6 est le **premier consommateur** de ce champ : un
   provider piscine calcule `valeurCourante ≥ seuil × preAlertePct/100` (§2.3). Aucune nouvelle colonne
   de seuil de pré-alerte n'est créée (pas de duplication).
3. **Journalisation des changements de seuil (CA-2)** — **déjà couverte** : `EspaceAcces` figure dans
   `AuditWriteSubscriber::CLASSES_SURVEILLEES` (L3 l'y a inscrite). Toute `PATCH` de `seuilFmi` est déjà
   auditée (qui/quand/valeur avant-après via `EntreeAudit`). **Zéro travail supplémentaire pour CA-2.**
4. **Tableau de bord présents/POSS/places réservées (CA-3)** — nouvelle ressource API **piscine**
   (`GET /piscine/poss/{id}/etat`) qui **compose** en lecture `JaugeFmi` (L3, présents/seuil) et les
   objets piscine (`CreneauPublic`, `JaugeGrandPublicCalculee`) pour les places réservées — **sans**
   toucher à `App\Acces\ApiResource\Supervision` (qui reste générique, non piscine-spécifique).
5. **Granularité bassin** — un `Poss.perimetre = bassin` **exige** un `EspaceAcces` L3 **dédié** au
   bassin (portique/tripode physique propre à ce bassin). **Si un tel équipement physique n'existe pas**
   (cas le plus fréquent en bassin ouvert), il n'y a **pas** de `Poss` de bassin : la capacité du bassin
   (`Bassin.capacite`/`occupationCourante`) reste un **compteur administratif de réservation** (lignes
   d'eau occupées), **distinct** de la FMI physique — conforme à RG-PISC/US-L6-05 (« compteur de
   capacité par bassin… distinct de la FMI globale ») et documenté comme **Risque n°1 à valider avec
   l'exploitant** (topologie réelle des points de contrôle par bassin).

---

## 1. Entités & schéma

Namespace : **`App\Piscine\Entity\*`**. `id` = UUID (`Symfony\Component\Uid\Uuid`, type Doctrine
`uuid`). `declare(strict_types=1)` partout. Noms métier en français. Toute entité racine porte un
`ManyToOne` vers `Etablissement` (socle, RG-SOCLE-01) et/ou `Espace`, cloisonnée par
`ContexteEtablissement` (RG-SOCLE-05), même pattern que L3 (`app/src/Acces/Entity/*.php`).

### 1.1 Poss — spécialisation piscine de la FMI L3 (RG-PISC-01, US-L6-02)

| Entité (`App\Piscine\Entity\*`) | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **Poss** | id | `uuid` | non | PK | POSS = capacité d'accueil réglementaire |
| | espaceAcces | — | non | **OneToOne unique** | `OneToOne` → `App\Acces\Entity\EspaceAcces` (**réutilisé, pas de FK vers une copie**) |
| | perimetre | `string(12)` enum `PerimetrePoss` {etablissement, bassin} | non | requis | §4.2/4.5 spec |
| | bassin | — | oui | requis si `perimetre=bassin` | `ManyToOne` → `Bassin` (§1.2) — informatif : quel bassin cet `EspaceAcces` dédié contrôle |
| | baseReglementaire | `string(255)` | oui | optionnel | texte/réf. réglementaire — **informatif seulement** (Risque n°2) |
| | reservationsProtegees | `boolean` | non | défaut `true` | politique « priorité réservations » (US-L6-03) — **appliquée côté vente/réservation**, pas au tripode (§2.4) |
| | etablissement | — | non | FK | `ManyToOne` → `Etablissement` (socle, dénormalisé comme en L3) |

> **Pas de colonne `seuilPresents`/`modeSeuil`/`preAlertePct` sur `Poss`** — ce sont des **propriétés de
> l'`EspaceAcces` L3 référencé** (`espaceAcces.seuilFmi`, `.modeSeuil`, `.preAlertePct`), lues via
> l'association. Dupliquer ces champs violerait la règle « ne pas dupliquer une capacité du socle ».
> Les getters piscine (`Poss::getSeuil()`, `Poss::getPreAlertePct()`) sont de **simples délégations**
> vers `espaceAcces`.

### 1.2 Bassin & LigneEau (US-L6-05)

| Entité | Champ | Type | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **Bassin** | id, libelle | `uuid`, `string(120)` | non | requis | — |
| | espace | — | non | FK | `ManyToOne` → `Espace` (socle, RG-SOCLE-01) |
| | nbLignes | `smallint` | non | `> 0` | US-L6-05 |
| | capacite | `integer` | non | `> 0` | capacité déclarative du bassin, **indépendante** de la FMI (§4.5) |
| | occupationCourante | `integer` | non | `≥ 0`, défaut 0, dérivé | **compteur de réservation** (lignes/places engagées sur créneaux à venir), pas un compteur de présence physique — cf. §0 point 5 |
| | espaceAccesDedie | — | oui | FK unique si renseigné | `ManyToOne`/`OneToOne` optionnel → `EspaceAcces` L3 — présent **seulement** si le bassin a un point de contrôle physique propre (condition d'un `Poss.perimetre=bassin`, Risque n°1) |
| | etablissement | — | non | FK | dénormalisé |
| **LigneEau** | id, numero | `uuid`, `smallint` | non | **unique `(bassin, numero)`** | US-L6-05 |
| | bassin | — | non | FK | `ManyToOne` → `Bassin` |
| | etat | `string(10)` enum `EtatLigneEau` {publique, reservee} | non | défaut `publique` | dérivé (recalculé) |
| | surfaceM2 | `decimal(6,2)` | oui | optionnel | **point d'extension** mode de prorata `surface` (non implémenté, §1.5) |

### 1.3 CreneauBassin (provisoire — gap M5) & CreneauPublic (US-L6-04/06)

> ⚠ **Extension provisoire** (spec §2/§8, point ouvert n°7) : `CreneauBassin` modélise le **strict
> nécessaire** pour couvrir US-L6-04/05/06/07 en l'absence de `spec-planning.md` (M5). Champs de
> planification avancée (récurrence, liste d'attente, émargement) **hors périmètre** — à réconcilier
> dès que M5 sera spécifié (Risque n°3).

| Entité | Champ | Type | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **CreneauBassin** | id | `uuid` | non | PK | — |
| | bassin | — | non | FK | `ManyToOne` → `Bassin` |
| | debut, fin | `datetime_immutable` | non | `debut < fin` (validateur) | — |
| | encadrantRequis | `string(8)` enum `TypeEncadrement` {MNS, BNSSA, aucune} | non | défaut `aucune` | RG-PISC-02 |
| | statut | `string(10)` enum `StatutCreneauBassin` {brouillon, valide, annule} | non | défaut `brouillon` | passe à `valide` par l'endpoint dédié (§3) — bloqué si encadrant manquant (CA-4) |
| **CreneauPublic** | id | `uuid` | non | PK | US-L6-06 |
| | creneauBassin | — | non | FK | `ManyToOne` → `CreneauBassin` |
| | typePublic | `string(12)` enum `TypePublic` {grand_public, scolaire, club} | non | requis | — |
| | lignes | — | — | `≥ 1`, sans chevauchement temporel (garde applicative) | `ManyToMany` → `LigneEau` (table de jointure `piscine_creneau_public_ligne`) |
| | jauge | `integer` | non | `≥ 0` | capacité propre à ce public sur ce créneau |
| | occupationCourante | `integer` | non | `≥ 0`, défaut 0 | places consommées de ce public (réservations individuelles) |

> **Garde « pas de chevauchement » (CA-6)** — service `AffectationLigneGuard::verifier(CreneauPublic)` :
> pour chaque `LigneEau` de l'affectation, recherche des `CreneauPublic` **existants** sur la même ligne
> dont l'intervalle `[creneauBassin.debut, fin]` **chevauche** celui du nouveau créneau ⇒ refus 422. Pas
> de contrainte SQL simple possible (chevauchement temporel) ⇒ garde applicative, comme
> `TopologieCoherente` en L3.

### 1.4 JaugeGrandPublicCalculee — prorata (US-L6-07)

| Entité | Champ | Type | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **JaugeGrandPublicCalculee** | creneauBassin | — | non | **OneToOne unique** (PK = FK) | `OneToOne` → `CreneauBassin` |
| | capaciteRestante | `integer` | non | `≥ 0`, dérivé | recalculé à chaque réservation/libération de ligne club/scolaire |
| | modeProrata | `string(10)` enum `ModeProrata` {lignes, surface, forfait} | non | défaut `lignes` | paramétrable par établissement (`ParametrePiscineEtablissement`, §1.6) |
| | recalculeLe | `datetime_immutable` | non | — | fraîcheur |

**Formule par défaut (mode `lignes`, arbitrage retenu — équivalent aux deux formulations du cahier et
de la demande) :**

```
lignesReservees = count(LigneEau où etat = reservee, pour ce bassin/créneau)
lignesTotales   = Bassin.nbLignes
capaciteRestante = round( Bassin.capacite × (1 − lignesReservees / lignesTotales) )
                 = round( Bassin.capacite × lignesLibres / lignesTotales )   // formulation cahier §4.7, équivalente
```

Service `PossProrataCalculator::recalculer(CreneauBassin)` — appelé par les processors de
`CreneauPublic` (création/mise à jour/suppression, §3) **dans la même requête** (recalcul synchrone,
US-L6-07 « en temps réel »). Modes `surface` et `forfait` : **structure prête** (`LigneEau.surfaceM2`,
`ParametrePiscineEtablissement.forfaitProrataJson`) mais **calcul non implémenté** — `modeProrata` ≠
`lignes` retombe sur le calcul `lignes` avec un **incident de configuration tracé** (log + champ
`Poss`/supervision) ⇒ **⚠ Risque n°4 (formule non figée, spec §4.7 point ouvert n°3)**.

### 1.5 Encadrants qualifiés (RG-PISC-02, US-L6-04)

| Entité | Champ | Type | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **QualificationEncadrant** | id | `uuid` | non | PK | — |
| | encadrant | — | non | FK | `ManyToOne` → `App\Securite\Entity\Utilisateur` (socle — hypothèse retenue spec §3/§8 point ouvert n°6) |
| | type | `string(8)` enum `TypeEncadrement` {MNS, BNSSA, autre} | non | requis | — |
| | dateValidite | `date_immutable` | non | requis | expirée ⇒ non prise en compte (calcul, pas de suppression) |
| | etablissement | — | non | FK | dénormalisé |
| **AffectationEncadrant** | id | `uuid` | non | PK | — |
| | creneauBassin | — | non | FK | `ManyToOne` → `CreneauBassin` |
| | qualification | — | non | FK | `ManyToOne` → `QualificationEncadrant` |

**Garde « ouverture/validation créneau » (CA-4)** — `ValiderCreneauBassinHandler::valider(CreneauBassin)`
(appelé par `POST /piscine/creneaux-bassin/{id}/valider`) : si `encadrantRequis ≠ aucune`, exige **au
moins une** `AffectationEncadrant` dont `qualification.type = encadrantRequis` **et**
`qualification.dateValidite ≥ creneauBassin.debut::date` ; sinon refus 422 (« aucun encadrant qualifié à
diplôme valide »). Sur succès, `statut = valide`.

### 1.6 Paramètres établissement (transverse, évite la duplication de « paramétrable par établissement »)

| Entité | Champ | Type | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **ParametrePiscineEtablissement** | id | `uuid` | non | PK | 1-1 par établissement |
| | etablissement | — | non | **OneToOne unique** | `OneToOne` → `Etablissement` (socle) |
| | delaiForcageCasierJours | `smallint` | non | `> 0`, défaut **⚠ non chiffré (Risque n°5)** — valeur de départ 3 | US-L6-09 |
| | montantCautionCasierDefaut | `decimal(6,2)` | non | `≥ 0`, défaut **⚠ non chiffré** — valeur de départ 10.00 € | US-L6-09 |
| | modeProrataDefaut | `string(10)` enum `ModeProrata` | non | défaut `lignes` | US-L6-07 |

> Centralise les points « paramétrable par établissement » répétés dans la spec (§4.7, §4.9) en **une
> seule** entité de configuration, plutôt que des valeurs par défaut dispersées/codées en dur
> (constitution §4 « aucune logique métier codée en dur »).

### 1.7 Casiers, caution, bracelet (US-L6-09/10)

| Entité | Champ | Type | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **Casier** | id, numero | `uuid`, `smallint` | non | **unique `(etablissement, zone, numero)`** | cahier §4 |
| | zone | `string(60)` | non | requis | ex. vestiaire homme/femme |
| | etat | `string(10)` enum `EtatCasier` {libre, occupe, non_rendu} | non | défaut `libre` | — |
| | bracelet | — | oui | requis si `occupe`/`non_rendu` | `ManyToOne` → `BraceletEtanche` |
| | etablissement | — | non | FK | — |
| **CautionCasier** | id | `uuid` | non | PK | — |
| | casier | — | non | FK + **index unique partiel** « un seul actif » | `ManyToOne` → `Casier` (technique colonne générée `casier_actif`, identique au pattern `Appairage.support_actif` de L3 §1.2/§7) |
| | montant | `decimal(6,2)` | non | `≥ 0` | défaut = `ParametrePiscineEtablissement.montantCautionCasierDefaut` |
| | statut | `string(10)` enum `StatutCaution` {encaissee, liberee, retenue} | non | défaut `encaissee` | `retenue` si forçage (§4.9) |
| | moyenEncaissement | `string(30)` | oui | optionnel | **⚠ non précisé par les sources** (Risque n°6) |
| | regieMouvementRef | `uuid` | oui | ref logique, pas de FK dure | vers un mouvement `App\Compta\Entity\RegieRecettes`/M2 — **à harmoniser transversalement** (Risque n°8, mécanisme potentiellement mutualisé patinoire) |
| | dateEncaissement, dateLiberation | `datetime_immutable` | oui/oui | — | — |
| **RelanceCasier** | id, casier | `uuid`, FK | non | requis | `ManyToOne` → `Casier` |
| | dateRelance | `datetime_immutable` | non | horodaté | — |
| | delaiForcageJours | `smallint` | non | copié de `ParametrePiscineEtablissement` à la création | — |
| **ForcageCasier** | id, casier | `uuid`, FK | non | requis | `ManyToOne` → `Casier` |
| | agent | — | non | FK | `ManyToOne` → `Utilisateur` (socle) |
| | motif | `string(255)` | non | requis | RG-SOCLE-07 |
| | horodatage | `datetime_immutable` | non | — | — |
| **BraceletEtanche** | id | `uuid` | non | PK | RG-PISC-04 |
| | support | — | non | **OneToOne unique** | `OneToOne` → `App\Acces\Entity\Support` (existant L3 — **doit** avoir `type = RFID`, validé) |
| | beneficiaireRef | `uuid` | oui | ref logique, pas de FK dure | vers `App\Crm\Entity\Beneficiaire` — **L5 non codé à date** (§0 en-tête) |
| | roles | `simple_array` (set `{acces, casier, douche}`) | non | `≥ 1` élément | cahier §2 — paramétrage des droits rattachés, pas une propriété physique |

> **`etat` du bracelet n'est pas dupliqué** : lu via `support.getStatut()` (`actif`/`bloque`, L3). Aucun
> champ `BraceletEtanche.etat`.

---

## 2. Intégration POSS ↔ FMI L3 — détail technique

### 2.1 Schéma de rattachement

```
App\Acces\Entity\EspaceAcces  (L3, existant)
   ├─ seuilFmi, modeSeuil, preAlertePct, antiPassbackActif/Delai, recalageOuverture   ← inchangés
   └─ (OneToOne)  App\Piscine\Entity\Poss   (L6, nouveau)
                     ├─ perimetre {etablissement|bassin}
                     ├─ bassin (si perimetre=bassin, informatif)
                     └─ baseReglementaire, reservationsProtegees

App\Acces\Entity\JaugeFmi (L3, existant, 1-1 avec EspaceAcces)
   └─ valeurCourante, seuil, mode, cumulJour     ← lu en lecture seule par le provider piscine (§2.3)
```

### 2.2 Garde « mode blocage strict imposé » — `PossModeSeuilGuard`

`App\Piscine\EventListener\PossModeSeuilGuard` (`#[AsDoctrineListener(event: Events::onFlush)]`,
enregistré uniquement dans le module Piscine, **aucun fichier `App\Acces\*` modifié**) :

- Sur `INSERT`/`UPDATE` d'un `Poss` : vérifie `poss.espaceAcces.modeSeuil === ModeSeuil::Blocage`, sinon
  lève `PossModeSeuilInvalideException` (422, « RG-PISC-01 : une POSS piscine doit être en blocage
  strict, jamais en alerte simple »).
- Sur `UPDATE` d'un `EspaceAcces` **déjà référencé par un `Poss`** : si `modeSeuil` passe à `alerte`
  dans le même flush, même refus. (Recherche `Poss` par `espaceAcces` avant le `flush`, via une requête
  légère dans le `postFlush` précédent mis en cache, ou vérification `preUpdate`-style sur le
  changeset — implémentation détaillée en tâche T3, pattern proche de `AuditWriteSubscriber::onFlush`
  déjà lu dans le code réel L0.)
- Ce listener est le **seul point d'extension** requis pour « imposer le blocage strict piscine » —
  conforme à la consigne « pas de logique verticale codée en dur dans L3 » (le générique L3 reste
  paramétrable `blocage`/`alerte` pour les autres verticales).

### 2.3 Pré-alerte & tableau de bord — `PossEtatLiveProvider`

Nouvelle ressource API non-Doctrine (même patron que `App\Acces\ApiResource\Supervision` /
`SupervisionProvider`, lus en code réel) :

`App\Piscine\ApiResource\PossEtatLive` + `App\Piscine\State\PossEtatLiveProvider` —
`GET /piscine/poss/{id}/etat` :

1. Charge `Poss`, puis `JaugeFmi` **via** `poss.espaceAcces` (requête `JaugeFmi` par `espace`, comme le
   fait déjà `SupervisionProvider`).
2. `presents = jauge.valeurCourante` ; `seuilPoss = jauge.seuil` (miroir `espaceAcces.seuilFmi`).
3. `preAlerteAtteinte = espaceAcces.preAlertePct !== null && presents >= seuilPoss × preAlertePct / 100`
   — **première lecture applicative** du champ `preAlertePct` posé par L3 (§0 point 2).
4. `placesReserveesRestantes` = somme, pour les `CreneauPublic` **actifs ou à venir** du/des bassin(s)
   rattaché(s) à l'établissement du `Poss` et dont `typePublic ∈ {scolaire, club}`, de
   `(jauge - occupationCourante)` — traduit `Poss.reservationsProtegees` : ces places **ne sont pas**
   comptées dans le disponible « entrée libre » du tableau de bord (US-L6-03).
5. Réponse `{presents, seuilPoss, preAlerteAtteinte, placesReserveesRestantes}` — `security:
   is_granted('PERM', 'piscine.lire')` ou `piscine.gerer_casier`/`acces.superviser` (lecture large, cf.
   spec Acteurs).

> **Non-enforcement au tripode** — `reservationsProtegees` est une **information de dashboard et une
> règle de vente/réservation** (contrôlée au moment de la réservation/vente via `CreneauPublic.jauge`,
> `JaugeGrandPublicCalculee.capaciteRestante`, M1/M2/M3), **pas** une modification de l'algorithme
> `ValidationPassageHandler` du tripode (qui reste un simple compteur global blocage/seuil, RG-ACC-04).
> Documenté comme **Risque n°7** (le mécanisme concret de priorisation abonnés Gold au tripode lui-même
> reste un point ouvert de la spec, §4.3, non tranché ici).

### 2.4 Bébés & accompagnants comptés dans la FMI (US-L6-08, RG-PISC-05)

**Aucun développement L6 requis.** Le mécanisme est **déjà livré et opérationnel en L3** :
`POST /acces/passages/non-nominatif` (`App\Acces\State\PassageNonNominatifProcessor`,
`ComptageNonNominatifHandler`) incrémente/décrémente `JaugeFmi` sans décompte de crédit, motif requis,
tracé distinctement (§4.4 du plan L3, lu en code réel). L6 **consomme tel quel** ce endpoint pour les
bébés/accompagnants piscine (motif = « bébé » ou « accompagnant »). Le test L6 pour CA-8 est donc un
**test d'intégration** vérifiant que ce endpoint générique, appliqué à un `EspaceAcces` référencé par un
`Poss`, produit bien le comportement attendu — **pas** un nouveau handler piscine.

---

## 3. API (API Platform)

Toutes ressources : `#[ApiResource]`, `security` via `is_granted('PERM', 'piscine.<action>')` (module
`piscine`) ou permissions réutilisées `acces.*`/`offre.*` quand l'action relève de ces modules (spec
§3, Acteurs & droits). Lecture cadrée par `ContexteEtablissement` (RG-SOCLE-05), même extension Doctrine
que L3.

| Ressource | Opérations | `security:` | Groupes | Notes |
|---|---|---|---|---|
| **Poss** | GET coll/item ; POST ; PATCH | `piscine.lire` / `piscine.configurer` | `poss:read/write` | POST/PATCH déclenchent `PossModeSeuilGuard` (422 si `EspaceAcces` en alerte) |
| **PossEtatLive** | `GET /piscine/poss/{id}/etat` | `piscine.lire` | `poss_live:read` | custom — composition `JaugeFmi`(L3) + réservations (§2.3), CA-3 |
| **Bassin** | GET coll/item ; POST ; PATCH | `piscine.lire` / `piscine.configurer` | `bassin:read/write` | — |
| **LigneEau** | GET coll ; POST ; PATCH | `piscine.lire` / `piscine.configurer` | `ligne:read/write` | — |
| **CreneauBassin** | GET coll/item ; POST ; PATCH | `piscine.lire` / `piscine.configurer` | `creneau:read/write` | — |
| | `POST /piscine/creneaux-bassin/{id}/valider` | `piscine.configurer` | — | custom — `ValiderCreneauBassinHandler` (CA-4) |
| **CreneauPublic** | GET coll ; POST ; PATCH ; DELETE | `piscine.lire` / `piscine.configurer` | `creneau_public:read/write` | POST/PATCH/DELETE déclenchent `AffectationLigneGuard` (CA-6) + `PossProrataCalculator` (CA-7) |
| **JaugeGrandPublicCalculee** | GET coll/item | `piscine.lire` | `jauge_public:read` | lecture seule, dérivée |
| **QualificationEncadrant** | GET coll ; POST ; PATCH | `piscine.lire` / `piscine.gerer` | `qualif:read/write` | référentiel qualifications (Administrateur) |
| **AffectationEncadrant** | GET coll ; POST ; DELETE | `piscine.lire` / `piscine.configurer` | `affect:read/write` | — |
| **Casier** | GET coll/item | `piscine.lire` | `casier:read` | état + bracelet |
| | `POST /piscine/casiers/{id}/attribuer` | `piscine.gerer_casier` | `casier:write` | custom — attribution bracelet + `CautionCasier` (encaissement), CA-9 |
| | `POST /piscine/casiers/{id}/liberer` | `piscine.gerer_casier` | — | custom — restitution + libération caution, CA-9 |
| | `POST /piscine/casiers/{id}/relancer` | `piscine.gerer_casier` | — | custom — `RelanceCasier`, passage `non_rendu` |
| | `POST /piscine/casiers/{id}/forcer` | `piscine.forcer_casier` | — | custom — `ForcageCasier` journalisé (agent/motif/horodatage), refuse si délai non dépassé |
| **BraceletEtanche** | GET coll/item ; POST ; PATCH | `piscine.lire` / `acces.appairer` | `bracelet:read/write` | POST valide `support.type = RFID` (RG-PISC-04) |
| **Catalogue piscine type** | `POST /piscine/modeles/piscine-type/instancier` | `piscine.configurer` **ET** `offre.creer` | `modele:write` | custom — `ModelePiscineTypeGenerator` crée `Produit`/`Formule`/`CarteMultiEntrees` (Offre), CA-1 |
| **ParametrePiscineEtablissement** | GET item ; PATCH | `piscine.lire` / `piscine.gerer` | `param:read/write` | 1-1 établissement |

- **Groupes de sérialisation** : `Poss`/`Bassin`/… suivent le même pattern read/write que L3
  (`normalizationContext`/`denormalizationContext` par ressource). `Casier` n'expose jamais
  `CautionCasier.regieMouvementRef` en écriture (champ système).
- **Custom vs CRUD** : attribution/libération/relance/forçage casier, validation créneau, affectation de
  lignes et instanciation du catalogue sont des **opérations métier** (State Processors délégant à des
  handlers testables) — pas du CRUD Doctrine brut, même logique que L3 §3.

---

## 4. Sécurité & droits

- **Permissions requises (module `piscine`)** — `piscine.configurer` (bassins, lignes, POSS, créneaux,
  seuils, prorata), `piscine.gerer_casier` (attribution/libération/relance/encaissement caution),
  `piscine.forcer_casier` (forçage administratif, distinct de `gerer_casier`), `piscine.lire` (lecture
  seule dashboard/référentiels), `piscine.gerer` (surensemble Administrateur : référentiel
  qualifications MNS/BNSSA + tout ce qui précède).
- **Permissions réutilisées** (non redéfinies) — `offre.creer`/`offre.modifier` (instanciation
  catalogue), `acces.superviser`/`acces.appairer`/`acces.controler` (supervision FMI, bracelet, contrôle
  mobile), `securite.gerer` (délégation forçage, socle).
- **Voter** — **aucun voter nouveau** : réutilise `PermissionVoter` du socle, exactement comme L3 §5.
- **Cadrage établissement** — `ContexteEtablissement`, extension Doctrine du socle étendue à
  `App\Piscine` (même mécanique que L3/M1/M6).
- ⚠ **HYPOTHÈSE (point ouvert n°10 de la spec)** — noms `piscine.*` dérivés du tableau Acteurs & droits
  de la spec, non littéraux dans les sources ; à figer avec M8 (comme `acces.*` en L3 et `crm.*` en L5).

---

## 5. Migrations

- **Migration structurelle** `VersionL6_piscine` : tables `piscine_poss`, `piscine_bassin`,
  `piscine_ligne_eau`, `piscine_creneau_bassin`, `piscine_creneau_public`,
  `piscine_creneau_public_ligne` (jointure), `piscine_jauge_grand_public_calculee`,
  `piscine_qualification_encadrant`, `piscine_affectation_encadrant`, `piscine_parametre_etablissement`,
  `piscine_casier`, `piscine_caution_casier`, `piscine_relance_casier`, `piscine_forcage_casier`,
  `piscine_bracelet_etanche`.
  - **Index/contraintes** : `OneToOne` unique `Poss.espaceAcces` ; unique `(bassin, numero)` sur
    `LigneEau` ; unique `(etablissement, zone, numero)` sur `Casier` ; **un seul `CautionCasier` actif
    par casier** via colonne générée `casier_actif` + index unique partiel (identique technique
    `Appairage.support_actif` de L3, code réel lu) ; `OneToOne` unique `JaugeGrandPublicCalculee.creneauBassin` ;
    `OneToOne` unique `BraceletEtanche.support` ; `OneToOne` unique
    `ParametrePiscineEtablissement.etablissement` ; checks `nbLignes > 0`, `capacite > 0`,
    `occupationCourante ≥ 0`, `montant ≥ 0`, `delaiForcageCasierJours > 0`. FK vers `etablissement`/
    `espace`/`utilisateur` (socle) et vers `App\Acces\Entity\{EspaceAcces,Support}` (L3) — **suppose
    migrations socle L0 + M1 + L3 jouées d'abord** (dépendance d'ordre, comme L3 dépend de L0/M1/M2).
- **Migration de données** `VersionL6_permissions` : insère `Permission(module='piscine', action ∈
  {configurer, gerer_casier, forcer_casier, lire, gerer})`.
- **Modification de fichier partagé (hors migration DB, tâche de code)** — ajout des classes L6
  sensibles (`Poss`, `Bassin`, `CreneauBassin`, `Casier`, `CautionCasier`, `ForcageCasier`,
  `QualificationEncadrant`) à `App\Audit\Doctrine\AuditWriteSubscriber::CLASSES_SURVEILLEES`
  (`app/src/Audit/Doctrine/AuditWriteSubscriber.php`), **exactement comme L1/L2/L3/L4 l'ont fait avant**
  — fichier socle partagé, extension additive d'une liste de classes, pas de logique réécrite.
- Rejouables, versionnées Doctrine ; jamais de `schema:update --force`.

---

## 6. Tests (PHPUnit + ApiTestCase)

| Test | Type | Couvre |
|---|---|---|
| Catalogue : instanciation « piscine type » crée entrée adulte/enfant, carte 10 (bonus paramétrable), Gold, Classique, cours ; chaque produit reste éditable indépendamment ; publiable/achetable sans étape L6 | API | CA-1, US-L6-01 |
| POSS blocage : `EspaceAcces` référencé par un `Poss` en `modeSeuil=blocage` ; entrée refusée au seuil (`seuil_fmi`) ; sortie validée libère **exactement** une place ; nouvelle entrée acceptée | API (réutilise le endpoint `POST /acces/passages` L3, aucun code piscine dans le chemin de validation) | CA-2, RG-PISC-01 |
| Garde mode seuil : création/`PATCH` d'un `Poss` sur un `EspaceAcces` en `alerte` → 422 ; `PATCH EspaceAcces.modeSeuil=alerte` alors qu'un `Poss` le référence → 422 | Unit (`PossModeSeuilGuard`) | §2.2, RG-PISC-01 |
| Journalisation seuil : `PATCH EspaceAcces.seuilFmi` (référencé par un `Poss`) → entrée `EntreeAudit` créée (qui/quand/valeur) — **sans code piscine**, vérifie la couverture socle existante | API/Unit | CA-2 (journalisation) |
| Pré-alerte & dashboard : `preAlertePct` paramétré ; franchissement → `PossEtatLive.preAlerteAtteinte=true` ; `placesReserveesRestantes` exclut les lignes club/scolaire ; `présents/POSS/réservées` exposés | API (`PossEtatLiveProvider`) | CA-3, US-L6-03 |
| Encadrant qualifié : créneau `encadrantRequis=MNS` sans affectation valide → `valider` refuse (422) ; qualification expirée → non prise en compte ; affectation valide couvrant la date → `valider` accepte | API + Unit (`ValiderCreneauBassinHandler`) | CA-4, RG-PISC-02 |
| Bassin/lignes : réservation dépassant lignes/capacité disponible → refusée ; `occupationCourante` distinct du compteur FMI (`JaugeFmi` de l'établissement inchangée par une réservation) | API | CA-5, US-L6-05 |
| Créneaux multi-publics : deux `CreneauPublic` sur lignes distinctes du même `CreneauBassin` acceptés ; affectation chevauchante sur une ligne déjà prise → refus (`AffectationLigneGuard`) ; jauges indépendantes par public | API + Unit (`AffectationLigneGuard`) | CA-6, RG-PISC-03 |
| Prorata grand public : réservation/libération de lignes club → `JaugeGrandPublicCalculee.capaciteRestante` recalculée immédiatement selon la formule par défaut ; exposée en temps réel | Unit (`PossProrataCalculator`) + API | CA-7, US-L6-07 |
| Bébés/accompagnants : `POST /acces/passages/non-nominatif` sur un `EspaceAcces` avec `Poss` → FMI incrémentée sans crédit ; sortie décrémente ; comptage systématique, non désactivable | API (test d'intégration L3↔L6) | CA-8, RG-PISC-05 |
| Casier/caution : attribution → caution encaissée, casier `occupe` ; restitution → caution libérée, casier `libre` ; délai dépassé sans restitution → `RelanceCasier` + état `non_rendu` ; délai de forçage dépassé → `ForcageCasier` journalisé (agent/motif/horodatage), refus si délai non atteint | API | CA-9, US-L6-09 |
| Un seul CautionCasier actif par casier : deuxième attribution sans libération préalable → refus (index unique partiel) | API/Unit (concurrence) | CA-9 (intégrité) |
| Bracelet RFID : `BraceletEtanche` lié à un `Support` `type=RFID` ; lu/validé comme tout support L3 au tripode/casier ; désactivation/réattribution tracée via `Appairage`/`Support.statut` réutilisés | API | CA-10, RG-PISC-04 |
| Architecture : aucun fichier `App\Acces\*` modifié par L6 (contrôle statique — diff/grep des classes touchées) ; `App\Piscine\*` ne référence `App\Acces\*` qu'en lecture/association | Unit (architecture) | §0/§2 (non-régression L3) |
| Cloisonnement établissement : agent sans affectation → 403/absent sur toutes les ressources `piscine.*` | API | RG-SOCLE-05 (réutilisé) |

---

## 7. Tâches (voir tasks-piscine.md)

T1 enums Piscine → T2 `ParametrePiscineEtablissement` → T3 `Poss` + `PossModeSeuilGuard` → T4
`Bassin`/`LigneEau` → T5 `CreneauBassin` + `QualificationEncadrant`/`AffectationEncadrant` +
`ValiderCreneauBassinHandler` → T6 `CreneauPublic` + `AffectationLigneGuard` → T7
`JaugeGrandPublicCalculee` + `PossProrataCalculator` → T8 `Casier`/`CautionCasier`/`RelanceCasier`/
`ForcageCasier` + handlers → T9 `BraceletEtanche` → T10 `ModelePiscineTypeGenerator` (catalogue) → T11
`PossEtatLiveProvider`/`PossEtatLive` (dashboard) → T12 API Platform (ressources + sérialisation +
droits) → T13 sécurité (permissions `piscine.*` + extension `AuditWriteSubscriber`) → T14 migrations →
T15 tests. (ordonnées, cf. fichier tasks.)

---

## 8. Risques / à valider

1. **⚠ RÉGLEMENTATION POSS/ERP + topologie des points de contrôle par bassin (priorité haute, points
   ouverts spec n°1)** — aucune source ne référence le texte réglementaire exact ; de plus, un
   `Poss.perimetre=bassin` **exige un `EspaceAcces` physique dédié au bassin** (§0 point 5) — la
   présence réelle de tels équipements par bassin (vs un seul contrôle d'accès établissement) **n'est
   pas confirmée** et conditionne si la granularité bassin de la POSS est physiquement enforcée ou
   seulement administrative (compteur de réservation). **À valider avec l'exploitant/la commission de
   sécurité avant mise en service.**
2. **⚠ Champ `baseReglementaire` informatif seulement** — pas de contrôle documentaire applicatif (spec
   §7).
3. **⚠ GAP DE DÉPENDANCE M5** — `CreneauBassin`/`CreneauPublic` sont une extension provisoire ; à
   réconcilier dès que `spec-planning.md` existera (spec §8 point ouvert n°7).
4. **⚠ Formule de prorata grand public** — seul le mode `lignes` (par défaut) est **calculé** ; `surface`
   et `forfait` sont des points d'extension **non implémentés** (structure de données prête). À trancher
   avec l'exploitant (spec §4.7 point ouvert n°3).
5. **⚠ Paramétrage casier non chiffré** — `delaiForcageCasierJours` et `montantCautionCasierDefaut` ont
   des **valeurs de départ arbitraires** (3 jours / 10 €) dans `ParametrePiscineEtablissement`, à
   ajuster avec l'exploitant (spec §4.9 point ouvert n°4) ; conduite en cas d'objets trouvés dans un
   casier forcé **non modélisée** (hors périmètre technique, procédure métier).
6. **⚠ Mode d'encaissement de la caution** — `CautionCasier.moyenEncaissement` est un champ libre, sans
   intégration paiement précise (empreinte CB, espèces, PMV M4) — à préciser avec M2/M4 (spec §4.9 point
   ouvert n°8, partiellement).
7. **⚠ Priorisation abonnés Gold au-delà du seuil de pré-alerte** — `Poss.reservationsProtegees` protège
   les places de réservations **connues** (scolaire/club) mais **le mécanisme concret de priorité
   « abonné »** aux entrées libres (file dédiée ? réservation préalable obligatoire ?) reste **non
   tranché**, conformément à la spec (§4.3 point ouvert n°2) — non implémenté au-delà du dashboard
   d'information.
8. **⚠ Mécanisme de caution transverse (patinoire)** — `CautionCasier.regieMouvementRef` est une
   référence logique volontairement faible ; aucun port/mécanisme M2/M6 générique de caution n'existe à
   ce jour ; si la patinoire (verticale future) a besoin d'un mécanisme identique, une **factorisation**
   (ex. `App\Caisse` ou `App\Compta`) sera nécessaire — **à arbitrer transversalement**, non fait ici
   pour ne pas anticiper une spec non écrite (spec §4.9 point ouvert n°8).
9. **⚠ Statut socle de l'Encadrant MNS/BNSSA** — hypothèse retenue : utilisateur socle avec affectation
   établissement (`QualificationEncadrant.encadrant` → `Utilisateur`) ; à confirmer si M5 introduit un
   profil RH distinct (spec §3 point ouvert n°6).
10. **⚠ Noms des permissions `piscine.*`** — dérivés du tableau Acteurs & droits de la spec, à figer avec
    M8 (spec §3/§8 point ouvert n°10, même réserve que L3 `acces.*`).
11. **Dépendance de code L5/CRM non livrée** — `BraceletEtanche.beneficiaireRef` reste une référence
    logique tant que `App\Crm\Entity\Beneficiaire` n'est pas codé ; aucun contrôle d'intégrité
    applicatif possible avant le câblage (pattern déjà accepté en L3 pour M1/M2 avant leur codage).
