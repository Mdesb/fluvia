# Plan technique — API Terminal d'accès & mode dégradé (`acces-terminal`, transverse `L3`)

- **Spec source :** specs/acces-terminal/spec-acces-terminal.md
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB 11.4
- **Dépend de :** `specs/L3-acces/plan-acces.md` (moteur `ValidationPassageHandler`/`SynchroPassageHandler`,
  entités `Support`/`DroitAcces`/`Appairage`/`Passage`/`Controleur`/`Equipement`/`ListeRevocation`,
  `CodeMotifRefus`, `PermissionVoter`) ; `App\Vente\Service\GenerateurCodeSupport` (M2, HMAC) ;
  socle L0 (`PermissionVoter`, `ContexteEtablissement`, hachage type mot de passe).
- **Couvre :** US-TERM-01 à US-TERM-09 · CA-1 à CA-11

> **Principe directeur du plan** — **aucune réécriture** du moteur de décision. Les endpoits
> `POST /acces/passages` et `POST /acces/synchro` **restent inchangés** (contrat, sécurité,
> comportement). Le contrat terminal ajoute des **opérations API Platform nouvelles**
> (`/terminal/passages`, `/terminal/snapshot`, `/terminal/passages/lot`) pointant vers des
> **processors/handlers qui décorent ou étendent** l'existant (`ValidationPassageHandler`,
> `SynchroPassageHandler`), plus une **nouvelle chaîne d'authentification** (firewall dédié
> `^/terminal`) strictement isolée du firewall `api` (JWT `Utilisateur`, `^/`). Les deux seules
> modifications du **code partagé** existant sont documentées explicitement en §1.6 et §4.4
> (extension `EvenementPassageDto`, branche « crédit négatif borné » dans `ValidationPassageHandler`,
> généralisation `SynchroPassageHandler::synchroniser()` au niveau `Terminal`) — chacune gardée par un
> flag inactif par défaut côté flux **en ligne**, donc **sans régression observable** sur
> `/acces/passages`/`/acces/synchro` pour les appelants existants (agents humains, `Controleur` seul).

---

## 1. Entités & schéma

Namespace `App\Acces\Entity\*` (sauf mention contraire). `id` = UUID. `declare(strict_types=1)`.
Toute entité racine porte un `ManyToOne` → `Etablissement` (socle, RG-SOCLE-01), cloisonnée par
`PerimetreAccesExtension` (déjà existante — **non modifiée**, cf. §3.3).

### 1.1 Terminal — identité machine (US-TERM-01, US-TERM-09)

| Entité | Champ | Type Doctrine | Null | Index/Contrainte | Notes |
|---|---|---|---|---|---|
| **Terminal** *(nouveau)* | id | `uuid` | non | PK | — |
| | nom | `string(120)` | non | requis | ex. « ITBOX Entrée Sud » |
| | itboxRef | `string(128)` | non | **index** (recherche portée) | aligné `Controleur.itboxRef` — portée = tous les `Controleur`/`Equipement` du même `itboxRef` **et** établissement (décision proposée spec §4.1) |
| | etablissement | — | non | FK `nullable:false` | `ManyToOne` → `Etablissement` (socle) |
| | statut | `string(12)` enum `StatutTerminal` {actif, revoque, en_attente} | non | défaut `actif` à la création (§7 décision) | cf. note ci-dessous |
| | dernierAppel | `datetime_immutable` | oui | — | mis à jour au fil de l'eau (§3.1), alimente A-03 |
| | dernierAppelReussi | `boolean` | oui | — | dernier appel = succès/échec, pour supervision |
| | dernierSnapshotVersion | `bigint` | oui | — | curseur delta le plus récent servi (traçabilité, pas source de vérité côté serveur) |
| | createdAt | `datetime_immutable` | non | — | audit |
| **JetonTerminal** *(nouveau)* | id | `uuid` | non | PK | 1 `Terminal` → plusieurs jetons dans le temps (rotation) |
| | terminal | — | non | FK | `ManyToOne` → `Terminal` |
| | secretHash | `string(64)` | non | **unique, index** | `hash('sha256', secret)` — **pas** bcrypt, cf. Risque R-1 |
| | dateEmission | `datetime_immutable` | non | — | — |
| | dateExpiration | `datetime_immutable` | oui | — | politique non tranchée (§8 spec pt.3) ; `null` = pas d'expiration MVP |
| | statut | `string(12)` enum `StatutJetonTerminal` {actif, revoque} | non | défaut `actif` | révocation immédiate |
| | revoqueLe | `datetime_immutable` | oui | — | — |
| | revoquePar | — | oui | FK | `ManyToOne` → `Utilisateur` (socle) |
| | etablissement | — | non | FK | dénormalisé de `terminal.etablissement`, pour `PerimetreAccesExtension` |

> **Décision — statut initial `actif` dès la création** (écart mineur avec la table `spec-acces-terminal.md`
> §5 qui documente un défaut `en_attente` « passe à actif à la 1ʳᵉ authentification ») : le CA-1 exige
> littéralement que l'enrôlement rende le `Terminal` **actif** immédiatement (le premier `JetonTerminal`
> étant émis synchrone à la création, il n'y a pas d'état intermédiaire utile pour le MVP). `en_attente`
> reste défini dans l'enum pour un usage futur (ex. provisioning en deux temps) — **à confirmer**, tracé
> en §7 Risques.

### 1.2 JournalReconciliation — litige double-consommation (US-TERM-08, CA-8)

| Entité | Champ | Type Doctrine | Null | Index/Contrainte | Notes |
|---|---|---|---|---|---|
| **JournalReconciliation** *(nouveau)* | id | `uuid` | non | PK | — |
| | passage | — | non | FK | `ManyToOne` → `Passage` (le passage accepté malgré dépassement) |
| | droit | — | non | FK | `ManyToOne` → `DroitAcces` (crédit devenu négatif) |
| | ecart | `integer` | non | — | ex. `-1` ; ampleur du dépassement au moment du rejeu |
| | statut | `string(12)` enum `StatutJournalReconciliation` {ouvert, regularise, ignore} | non | défaut `ouvert` | traitement par un agent, hors périmètre de ce plan (cf. M2) |
| | horodatage | `datetime_immutable` | non | — | — |
| | etablissement | — | non | FK | cloisonnement |

### 1.3 Objets **non persistés** (DTO / projections — pas de table)

- **`EntreeSnapshotDto`** (`App\Acces\Dto\EntreeSnapshotDto`, readonly) — projection calculée à la volée
  par le provider snapshot (§2.2) à partir de `Support` + `Appairage` (actif) + `DroitAcces`. **Aucune
  table dédiée** : la « version » de coupure (tombstone/à jour) est **recalculée à la lecture**, pas
  stockée comme un flag figé (évite une désynchronisation entre un flag et l'état réel).
- **`PassageHorsLigneDto`** — pas de nouvelle classe DTO stricte : le lot reste un tableau associatif
  brut lu par `LecteurCorps`, comme le fait déjà `SynchroProcessor`. Les champs informatifs
  `resultatLocal`/`codeMotifLocal` transmis par la borne sont **lus et journalisés en log structuré**
  (canal Monolog `acces.reconciliation`, niveau `info`) pour audit/comparaison, **mais ne sont pas
  persistés en base** — la spec les qualifie elle-même de « DTO d'échange, pas persisté tel quel »
  (§5). Si un futur besoin de requêtage SQL sur l'écart borne/serveur apparaît, deux colonnes
  nullables pourront être ajoutées à `Passage` sans rupture (migration additive).
- **`MessageAffichage`** — catalogue fermé implémenté comme **enum PHP** `App\Acces\Enum\CodeMessageAffichage`
  (pas d'entité/table) + service `App\Acces\Service\CatalogueMessageAffichage` qui mappe
  `CodeMotifRefus|null → CodeMessageAffichage → libellé`. Un enum PHP garantit un catalogue *fermé* par
  construction (contrainte du langage, zéro migration). La personnalisation par établissement
  (⚠ non actée, spec §4.5) est un point d'extension **futur** : le service est conçu avec un point
  d'injection (`?LibellesParEtablissementInterface`, stub `null` = libellés par défaut uniquement) pour
  ne pas bloquer une évolution ultérieure sans re-toucher l'appelant.

### 1.4 Colonne de versionnement du snapshot (US-TERM-03/04, CA-5/6)

| Entité (existante, **étendue**) | Champ ajouté | Type Doctrine | Null | Notes |
|---|---|---|---|---|
| **Support** (`App\Acces\Entity\Support`) | versionMaj | `bigint` | non, défaut = valeur de séquence à la création | horodate logiquement (pas datetime) la dernière mutation affectant la projection de ce support (blocage/déblocage, appairage/révocation, décompte crédit du `DroitAcces` appairé). **Index** `idx_support_version_maj`. |

- **Générateur de version** — `App\Acces\Service\VersionSnapshotSequencer`, adossé à une **séquence
  native MariaDB** (`CREATE SEQUENCE acces_snapshot_seq`, MariaDB ≥ 10.3, disponible en 11.4) :
  `SELECT NEXT VALUE FOR acces_snapshot_seq` → entier monotone, sans contention de ligne (contrairement
  à un compteur mono-ligne `UPDATE ... SET valeur = valeur + 1`). Le curseur `depuis`/`versionCourante`
  du contrat snapshot (§2.2) est directement cette valeur — **pas** une date (évite les pertes par
  égalité d'horodatage, cohérent avec `ListeRevocation.version` déjà existant, §4.3 spec).
- **Points de mise à jour** (chacun ajoute **une ligne** `$support->setVersionMaj($sequencer->suivant())`
  dans une transaction déjà existante — aucune requête réseau supplémentaire) :
  1. `App\Acces\Service\BlocageSupportHandler` (blocage/déblocage perte-vol, RG-ACC-07) ;
  2. `App\Acces\State\AppairageProcessor` / `RevoquerAppairageProcessor` (nouvel appairage / révocation) ;
  3. `ValidationPassageHandler::valider()` — étape crédit (§4.6 plan L3) : à chaque décompte
     `creditRestant` (carte à quota), un flush de version sur le `Support` concerné, pour que le
     `compostagesRestants` remonté au prochain snapshot delta reflète le solde réel ;
  4. futur connecteur M2 (`ProjectionDroitInterface`, actuellement `StubProjectionDroit`) — **à
     compléter à l'intégration réelle M1/M2** (dévalidation d'un droit, RG-M2-07) : point d'extension
     documenté, pas implémenté ici (hors périmètre de ce document, cf. plan-acces.md §1.3).
- **Impact perf online** — un `UPDATE` supplémentaire (colonne `version_maj`) sur le chemin déjà
  transactionnel de `ValidationPassageHandler` (même transaction que le décompte crédit et la jauge
  FMI) : **négligeable** (pas d'I/O réseau, une écriture de plus dans une transaction déjà ouverte),
  ne remet pas en cause le budget < 1 s (RG-ACC-01).

---

## 2. API (API Platform)

### 2.1 Enrôlement & administration d'un `Terminal` (US-TERM-01, US-TERM-09)

| Ressource | Opération | `security:` | Groupes sérialisation | Notes |
|---|---|---|---|---|
| `Terminal` | `GetCollection` `/acces/terminaux` | `is_granted('PERM','acces.superviser') or is_granted('PERM','acces.gerer')` | `terminal:read` | liste + état réseau (A-03) |
| `Terminal` | `Get` `/acces/terminaux/{id}` | idem | `terminal:read` | — |
| `Terminal` | `Post` `/acces/terminaux` | `is_granted('PERM','acces.gerer')` | `terminal:write`→`terminal:read` | processor `EnrolerTerminalProcessor` — crée `Terminal` (statut `actif`) + 1er `JetonTerminal`, renvoie **le secret en clair une seule fois** dans la réponse (`secret`, absent de toute lecture ultérieure — même patron que la génération de mot de passe temporaire, `RG-SOCLE-06`) |
| `Terminal` | `Post /acces/terminaux/{id}/jetons` | `is_granted('PERM','acces.gerer')` | — | processor `RotationJetonTerminalProcessor` — émet un nouveau `JetonTerminal` actif, **révoque l'ancien actif** (pas de chevauchement MVP, cf. Risque R-2), renvoie le secret une fois |
| `Terminal` | `Post /acces/terminaux/{id}/revoquer` | `is_granted('PERM','acces.gerer')` | — | processor `RevoquerTerminalProcessor` — `Terminal.statut = revoque` + révoque tout `JetonTerminal` actif ; **idempotent** (200 même si déjà révoqué) |

- Toutes ces opérations **réutilisent** `PermissionVoter`/`PERM` existant (acteur = `Utilisateur`
  humain), aucune nouveauté sécurité ici — seule la ressource `Terminal` est nouvelle.
- `Terminal`/`JetonTerminal` suivent le même pattern d'affichage-unique-du-secret que
  `App\Securite\Security\GestionnaireSuccesConnexionMfa`/réinitialisation mot de passe (constitution,
  RG-SOCLE-06) : `secretHash` n'a **jamais** de `Groups(['...:read'])`, aucun getter exposé en API.

### 2.2 Flux 1 — Validation en ligne (US-TERM-02)

| Ressource | Opération | `security:` | Notes |
|---|---|---|---|
| `Passage` (existant) | `Post` `/terminal/passages` *(nouvelle opération sur la ressource existante)* | `is_granted('PERM_TERMINAL','acces.ingestion')` | processor **nouveau** `TerminalPassageProcessor` — décore `PassageIngestionProcessor` (§3.2) : vérifie la portée (`equipementId` ∈ portée du `Terminal` authentifié, sinon **403 avant tout appel moteur**, §4.1 spec), délègue à `ValidationPassageHandler::valider()` (inchangé), puis **enrichit** la réponse technique avec `message` (§1.3) et `affichage` (§2.4). `POST /acces/passages` reste **strictement inchangé** (opération, security, processor, réponse) — utilisé par les agents humains / `Controleur` seul (cf. plan-acces.md). |

- **Corps de requête** (`/terminal/passages`) : `{ equipementId, identifiantSupport?, sens?,
  horodatageBorne, cleIdempotence }` — même structure que `EvenementPassageDto` avec `horodatageBorne`
  en plus (mappé sur `EvenementPassageDto::horodatage`, cf. §1.6 sur la conservation de l'horodatage
  borne).
- **Réponse** (contrat observable, §4.2 spec) :
```json
{
  "resultat": "valide|refuse|compte",
  "codeMotif": "credit_epuise|null",
  "horodatageServeur": "2026-08-17T10:02:31+02:00",
  "message": { "codeMessage": "BONNE_SEANCE", "libelle": "Bonne séance !" },
  "affichage": { "nomPorteur": "J. Dupont", "numeroBillet": "B-000123", "typeSupport": "QR",
                 "compostagesRestants": 6, "validiteAbonnement": null }
}
```
- **Résolution `affichage`** — service **nouveau** `App\Acces\Service\AffichagePorteurResolver` :
  dérive `numeroBillet`/`nomPorteur` depuis `DroitAcces.billetSupportRef` (référence logique vers
  `App\Vente\Entity\BilletSupport`, déjà en `ManyToOne` implicite non-FK, cf. plan-acces.md §1.3) via
  **lecture directe** (même précédent que `ValidationPassageHandler` qui dépend déjà de
  `App\Vente\Service\GenerateurCodeSupport`, cross-module accepté dans ce code) ; `compostagesRestants`
  = `DroitAcces.creditRestant` ; `validiteAbonnement` = `{valide, debut, fin}` calculé depuis
  `fenetreDebut/fenetreFin` si `sourceType = Abonnement`. **Absent (`null`)** si `resultat = refuse` sur
  signature invalide/support inconnu (§4.2 spec, pas de fuite d'info). ⚠ `App\Vente\Entity\BilletSupport`
  ne porte **pas** aujourd'hui de champ `nomPorteur` explicite (vérifié dans le code, §7 Risque R-3) —
  point à trancher avec M2/CRM (rattachement porteur nominatif), le resolver renvoie `nomPorteur: null`
  tant que la source n'est pas cadrée, dégradant gracieusement sans bloquer CA-2.
- **Catalogue message** — `CatalogueMessageAffichage::pour(?CodeMotifRefus $motif, ResultatPassage $resultat): CodeMessageAffichage`
  implémente exactement le tableau §4.5 de la spec (11 entrées) ; ajout d'un futur motif = ajout d'un
  `case` enum + une ligne `match`, aucune migration.

### 2.3 Flux 2 — Snapshot local (US-TERM-03/04/05)

| Ressource | Opération | `security:` | Notes |
|---|---|---|---|
| `SnapshotTerminal` *(nouvel `ApiResource` non-Doctrine, pattern `SynchronisationAcces`)* | `GetCollection` `/terminal/snapshot` | `is_granted('PERM_TERMINAL','acces.snapshot')` | provider **nouveau** `SnapshotTerminalProvider` |

- **Paramètres de requête** :
  - `depuis` (int, optionnel, défaut `0`) — curseur `versionMaj` ; absent/`0` = **snapshot complet**
    (delta depuis l'origine, §2.3 spec : « snapshot complet = cas particulier du delta »).
  - `jusqua` (int, optionnel) — borne haute stable pour paginer un snapshot complet sans dérive pendant
    des mutations concurrentes (1ᵉʳᵉ page fixe `jusqua = versionCourante`, pages suivantes le
    reproduisent) ; absent = borne haute = `MAX(version_maj)` au moment de la requête.
  - `page` (int, défaut 1), `taille` (int, défaut 500, max 2000, configurable via
    `acces.snapshot_taille_page_defaut` — §7 Risque R-4, volumétrie non chiffrée par la spec).
- **Réponse** :
```json
{
  "versionCourante": 481233,
  "page": 1,
  "taillepage": 500,
  "pageSuivante": true,
  "entrees": [
    { "identifiant": "BIL-K7QR...9F3A2B7C1D", "revoque": false, "nomPorteur": "J. Dupont",
      "numeroBillet": "B-000123", "typeSupport": "QR", "typeDroit": "carte_quota",
      "compostagesRestants": 6, "validiteDebut": null, "validiteFin": null,
      "portesEligibles": ["<equipementId>", "..."], "sousReseauId": null, "versionMaj": 481012 }
  ]
}
```
- **Requête SQL** — `Support` filtré par : (a) `etablissement = Terminal.etablissement`, (b)
  `versionMaj > depuis AND versionMaj <= jusqua`, ordonné `versionMaj ASC, id ASC`, `OFFSET/LIMIT`
  page/taille. Pour chaque `Support` retenu, **recalcul à la lecture** de `revoque` :
  `true` si `statut = bloque` **ou** aucun `Appairage` actif **ou** `droit.statutProjection ≠ valide` ;
  sinon `false` avec projection complète (§1.3).
- **`portesEligibles`** — simplification MVP assumée et **tracée en Risque R-5** : liste = tous les
  `Equipement.id` de la portée du `Terminal` (son `itboxRef`) **si** `droit.sousReseau === null` ; si
  `droit.sousReseau` renseigné, filtrée par compatibilité statique (même règle que l'étape « sous-réseau
  / fédération » de `ValidationPassageHandler` : sous-réseau actif + `produitRef` éligible le cas
  échéant). Les **marges horaires** (`margeAvance/margeRetard` par équipement) ne sont **pas** répétées
  dans chaque entrée (répétition inutile, elles varient peu) : exposées une fois via `GET
  /acces/terminaux/{id}` (portée déjà lisible par l'admin, §2.1) — à consommer par la borne au moment de
  l'enrôlement/rafraîchissement de config, hors du flux snapshot support-par-support.
- **`SnapshotTerminalProvider`** met à jour `Terminal.dernierSnapshotVersion`/`dernierAppel` (best-effort,
  écriture asynchrone tolérée en retard, cf. §3.1).

### 2.4 Flux 3 — Remontée hors-ligne (US-TERM-06/07/08)

| Ressource | Opération | `security:` | Notes |
|---|---|---|---|
| `SynchronisationAcces` (existant, **nouvelle opération ajoutée**) | `Post` `/terminal/passages/lot` | `is_granted('PERM_TERMINAL','acces.ingestion')` | processor **nouveau** `TerminalSynchroProcessor` — généralise `SynchroPassageHandler::synchroniser()` au niveau `Terminal` (plusieurs `Controleur`/`Equipement` d'un même `itboxRef`), §4.4. `POST /acces/synchro` reste **inchangé** (opération, security `is_granted('PERM','acces.ingestion')`, processor `SynchroProcessor`), toujours utilisable au niveau `Controleur` seul si un intégrateur l'appelle directement (rétrocompatibilité totale). |

- **Corps** : `{ "lot": [ { "identifiantSupport"?, "equipementId", "sens"?, "horodatageBorne",
  "cleIdempotence", "resultatLocal"?, "codeMotifLocal"? } ] }` (le `terminal` est déduit du jeton
  authentifié, pas du corps — évite qu'une borne usurpe l'identité d'une autre).
- **`TerminalSynchroProcessor`** :
  1. Résout `Terminal` depuis le token (§3.1).
  2. **Filtre de portée par lot** — une requête `Equipement` batch (`IN (...)`) résout
     `equipementId → itboxRef` pour tout le lot en une fois ; toute entrée hors portée est
     **exclue du rejeu** et renvoyée avec `statut = rejete`, `codeMotif = hors_portee` *(nouveau case
     `CodeMotifRefus`, §1 plan-acces.md — ajout additif, aucune migration nécessaire, colonne déjà
     `VARCHAR(32)` sans `CHECK`, cf. §4 Migrations)* — **jamais** silencieusement ignorée (traçabilité).
  3. Délègue le lot filtré à `SynchroPassageHandler::synchroniser()` **étendu** (§4.4 ci-dessous, mêmes
     garanties d'idempotence et de tri chronologique qu'aujourd'hui).
  4. Réponse **par entrée** (contrat CA-7) :
```json
{
  "recus": 12,
  "resultats": [
    { "cleIdempotence": "…", "statut": "accepte", "codeMotif": null, "enConflit": false,
      "ecartHorlogeSuspect": false },
    { "cleIdempotence": "…", "statut": "doublon", "codeMotif": null, "enConflit": false },
    { "cleIdempotence": "…", "statut": "accepte", "codeMotif": "credit_epuise_hors_ligne_litige",
      "enConflit": true, "ecartHorlogeSuspect": false }
  ]
}
```

---

## 3. Sécurité & droits

### 3.1 Authentification d'un `Terminal` — firewall dédié, isolé du firewall `api`

- **Décision structurante** — nouveau firewall Symfony `terminal` (`config/packages/security.yaml`),
  **placé avant** le firewall `api` existant, `pattern: ^/terminal`, `stateless: true`, **aucun
  provider Doctrine classique** (pas de `UserProviderInterface` sur `Terminal` — la résolution se fait
  par hash de jeton, pas par identifiant/mot de passe). Le firewall `api` (`pattern: ^/`, JWT,
  `provider: app_utilisateurs`) **continue de matcher tout le reste, y compris `/acces/*`,
  inchangé** — Symfony évalue les firewalls dans l'ordre et s'arrête au premier `pattern` qui matche :
  isolation garantie par construction, **zéro risque de collision** avec l'authenticator JWT existant.
```yaml
firewalls:
    terminal:
        pattern: ^/terminal
        stateless: true
        custom_authenticators: [App\Acces\Security\TerminalAuthenticator]
    api:            # inchangé — continue de gérer /acces/*, /auth, etc.
        pattern: ^/
        ...
```
- **`App\Acces\Security\TerminalAuthenticator`** (`AbstractAuthenticator`) :
  1. `supports()` — présence d'un header `Authorization: Bearer <secret>`.
  2. `authenticate()` — `hash('sha256', $secret)` → `JetonTerminal` (recherche **exacte, indexée**,
     `secretHash`) ; vérifie `statut = actif`, `dateExpiration` non dépassée, puis `Terminal.statut =
     actif` ; sinon `CustomUserMessageAuthenticationException` → **401** (message générique, pas de
     distinction jeton inconnu/expiré/révoqué, §7 spec « borne inconnue »). Retourne un
     `SelfValidatingPassport` avec `UserBadge` chargeant `App\Acces\Security\TerminalUtilisateur`
     (wrapper léger `implements UserInterface`, `getRoles(): ['ROLE_TERMINAL']`, porte la référence au
     `Terminal` résolu).
  3. `onAuthenticationSuccess()` — planifie la mise à jour de `Terminal.dernierAppel`/`dernierAppelReussi`
     (écriture **best-effort** : `UPDATE` direct sans lecture préalable, throttlée à 1 écriture / 30 s
     par `Terminal` pour ne pas alourdir le chemin `< 1 s` de la validation en ligne — implémentation
     via un compteur en mémoire de requête + flush différé, ou écoute d'un `kernel.terminate` pour ne
     jamais bloquer la réponse HTTP).
  4. Aucune modification de `App\Securite\Security\PermissionVoter`, `VerificateurUtilisateur`,
     `EcouteurConnexion` ni de la config JWT — code strictement additif.

### 3.2 Permissions `Terminal` — nouveau voter dédié, aucune modification de `PermissionVoter`

- **`App\Acces\Security\TerminalPermissionVoter`** (`extends Voter<string,string>`, attribut
  **`PERM_TERMINAL`** — délibérément distinct de `PERM` pour ne **jamais** intersecter avec
  `PermissionVoter::supports()`/`voteOnAttribute()`, ni avec `CalculateurDroits`/`Affectation`/`Role`
  humains) :
  - `supports()` : `attribute === 'PERM_TERMINAL'`.
  - `voteOnAttribute()` : `$token->getUser() instanceof TerminalUtilisateur` **et** `Terminal.statut ===
    actif` **et** `$subject ∈ {'acces.ingestion', 'acces.snapshot'}` — permissions **fixes**, pas de
    RBAC pour un `Terminal` (un acteur machine n'a que ces deux capacités, §3 spec, tableau Acteurs).
  - Enregistrement des deux permissions dans le catalogue `sec_permission` (`Permission(module=acces,
    action=ingestion)` déjà probablement présente vu son usage existant côté `PassageIngestionProcessor`/
    `SynchroProcessor` — à vérifier en fixtures ; `Permission(module=acces, action=snapshot)`
    **nouvelle**, insérée en fixtures/migration de données pour la complétude du répertoire M8, **sans
    effet fonctionnel** (le voter `Terminal` ne consulte pas cette table, contrairement au voter humain).
- **Contrôle de portée applicatif** (equipementId/lot ∈ portée du `Terminal`) : **pas** un voter
  (portée dynamique dépendante du corps de requête, pas d'un sujet statique `module.action`) — géré en
  service dédié `App\Acces\Security\TerminalPorteeChecker::verifierEquipement(TerminalUtilisateur,
  Uuid): Equipement` (403 `AccessDeniedHttpException` si hors portée), appelé par
  `TerminalPassageProcessor`/`TerminalSynchroProcessor` **avant** tout appel au moteur — conforme à
  « refus avant exécution, pas de fuite d'info sur un équipement hors périmètre » (§4.2 spec).

### 3.3 Cloisonnement multi-entités

- `Terminal`/`JetonTerminal`/`JournalReconciliation` portent tous `etablissement` et sont ajoutés à la
  table `PerimetreAccesExtension::CHEMINS` (**une ligne par entité ajoutée**, extension additive du
  tableau existant — la classe elle-même n'est pas restructurée) pour que les écrans d'administration
  (`GET /acces/terminaux`) respectent RG-SOCLE-05 pour les **agents humains**.
- Cette extension **ne s'applique pas** aux nouvelles opérations `/terminal/*` : ce sont des `Post`/
  `GetCollection` sur des processors/providers dédiés (pas des requêtes Doctrine génériques passant par
  les extensions API Platform), la portée y est vérifiée **manuellement** par
  `TerminalPorteeChecker`/le filtre du provider snapshot (§2.3/2.4) — cohérent avec le fait que
  `PerimetreAccesExtension::restreindre()` ignore déjà silencieusement tout `getUser()` qui n'est pas
  `instanceof Utilisateur` (vérifié dans le code existant) : un `Terminal` ne doit **jamais** transiter
  par cette extension, d'où le choix des endpoints dédiés plutôt que de réutiliser tel quel
  `GetCollection` sur `Passage`/`Support`.

### 3.4 Permissions requises — récapitulatif

| Acteur | Permission | Portée du contrôle |
|---|---|---|
| `Terminal` | `PERM_TERMINAL` → `acces.ingestion` | `/terminal/passages`, `/terminal/passages/lot` |
| `Terminal` | `PERM_TERMINAL` → `acces.snapshot` | `/terminal/snapshot` |
| `Utilisateur` (admin) | `PERM` → `acces.gerer` | enrôlement/rotation/révocation `Terminal` |
| `Utilisateur` (superviseur) | `PERM` → `acces.superviser` ou `acces.gerer` | lecture état `Terminal` (A-03) |
| `Utilisateur`/`Controleur` (legacy) | `PERM` → `acces.ingestion` | `/acces/passages`, `/acces/synchro` **inchangés** |

---

## 4. Sécurité — changements minimaux au moteur existant

### 4.1 Extension de `EvenementPassageDto` (additive, aucun paramètre existant retiré)

```php
public function __construct(
    public readonly Uuid $equipementId,
    public readonly ?string $identifiantSupport,
    public readonly ?SensPassage $sens,
    public readonly \DateTimeImmutable $horodatage,
    public readonly Uuid $cleIdempotence,
    public readonly bool $origineHorsLigne = false,
    public readonly bool $ignorerRevocationSiPosterieure = false,
    // --- nouveaux, défaut = comportement actuel inchangé ---
    public readonly ?\DateTimeImmutable $horodatageBorne = null,        // conservé tel quel (§4.4 spec, skew)
    public readonly bool $autoriserCreditNegatifSiHorsLigne = false,     // cf. §4.2 ci-dessous
)
```
Tous les appelants existants (`PassageIngestionProcessor`, `SynchroPassageHandler` tel quel) compilent
et se comportent **à l'identique** sans modification (paramètres nommés avec défauts).

### 4.2 `ValidationPassageHandler` — réconciliation gracieuse du crédit épuisé hors-ligne (CA-8)

- **État actuel (inchangé pour le flux en ligne)** — dans la transaction, si
  `UPDATE acces_droit_acces SET credit_restant = credit_restant - 1 WHERE id = :id AND credit_restant > 0`
  n'affecte aucune ligne → `throw new PassageRefuseException(CodeMotifRefus::CreditEpuise, ...)` →
  passage `refuse`. **Ce comportement reste strictement celui-ci quand `autoriserCreditNegatifSiHorsLigne
  = false`** (défaut, valeur utilisée par `PassageIngestionProcessor`/`TerminalPassageProcessor`
  c.-à-d. **tout flux en ligne**) : **zéro régression observable** sur `/acces/passages` et
  `/terminal/passages`.
- **Changement minimal, actif uniquement si `$evt->autoriserCreditNegatifSiHorsLigne === true`**
  (positionné **uniquement** par `TerminalSynchroProcessor`/le futur `SynchroPassageHandler::synchroniser()`
  étendu, jamais par le chemin en ligne) :
```php
if ($droit->getSourceType() === TypeDroitAcces::CarteQuota) {
    $plancher = $evt->autoriserCreditNegatifSiHorsLigne ? $this->plancherCreditNegatif : 0; // ex. -1, config
    $affectees = (int) $this->connection->executeStatement(
        'UPDATE acces_droit_acces SET credit_restant = credit_restant - 1
         WHERE id = UNHEX(:hex) AND credit_restant > :plancher',
        ['hex' => bin2hex($droit->getId()->toBinary()), 'plancher' => $plancher],
    );
    if ($affectees === 0) {
        throw new PassageRefuseException(CodeMotifRefus::CreditEpuise, 'Carte épuisée.');
    }
    $droit->setCreditRestant(($droit->getCreditRestant() ?? 1) - 1);
    if ($evt->autoriserCreditNegatifSiHorsLigne && ($droit->getCreditRestant() ?? 0) < 0) {
        $enConflitCredit = true; // → passage.enConflit = true, codeMotif = CreditEpuiseHorsLigneLitige (accepté)
    }
}
```
  Le passage reste `Valide` (physiquement survenu) mais `enConflit = true` et
  `codeMotif = CodeMotifRefus::CreditEpuiseHorsLigneLitige` *(nouveau case enum, additif)* — dérogation
  documentée : `Passage.codeMotif` est en temps normal réservé aux refus (`refuser()`), ici positionné
  sur un passage **accepté**, exception intentionnelle tracée par un commentaire dans le code et par ce
  plan (§7 Risque R-6, à valider explicitement par IT Cotation avant implémentation — c'est l'écart
  explicitement signalé comme non tranché par la spec, §8 pt.4/CA-8).
- **`JournalReconciliation`** créé (persist + flush, même transaction) quand `enConflitCredit === true` :
  `passage`, `droit`, `ecart = creditRestant` (négatif), `statut = ouvert`.
- **`$plancherCreditNegatif`** — paramètre de service `App\Acces\Service\ValidationPassageHandler`,
  injecté via `#[Autowire(env: 'ACCES_PLANCHER_CREDIT_NEGATIF')]` (défaut `-1`, entier ≤ 0) —
  **global MVP**, pas encore par établissement (§8 spec pt.5, non chiffré) ; évolutif sans breaking
  change vers une colonne `Etablissement.plancherCreditNegatifAcces` plus tard (hors périmètre socle
  Organisation ici, cf. contrainte « ne pas modifier le socle au-delà du strict nécessaire »).

### 4.3 `SynchroPassageHandler::synchroniser()` — généralisation `Controleur` → `Terminal`

- **Signature actuelle** : `synchroniser(Controleur $controleur, array $lot): array` — recale la jauge
  FMI d'un **seul** espace en fin de rejeu (celui du `Controleur` passé en paramètre).
- **Changement** : nouvelle méthode **additive** `synchroniserPourTerminal(array $lot, bool
  $origineTerminal = true): array` qui :
  1. Réutilise le **même corps** de boucle (tri chronologique, vérif. `cleIdempotence` avant rejeu →
     idempotence déjà garantie, **inchangée**, §4.4 ci-dessous) ;
  2. Positionne `ignorerRevocationSiPosterieure: true` (déjà le cas) **et**
     `autoriserCreditNegatifSiHorsLigne: true` sur chaque `EvenementPassageDto` construit (c'est
     précisément le point d'activation de la réconciliation gracieuse §4.2, **uniquement** pour ce
     chemin hors-ligne) ;
  3. Après rejeu, **recale la jauge FMI de chaque espace distinct touché** par le lot (au lieu d'un
     seul espace) : `$espaces = array_unique(array_map(fn($p) => $p->getEspace()?->getId()->toString(),
     $passagesRejoues))`, puis `RecalageFmiHandler::recalerApresSynchro()` par espace.
  4. `synchroniser(Controleur $controleur, array $lot)` (signature `/acces/synchro` actuelle) **devient
     un simple wrapper** : `return $this->synchroniserPourTerminal($lot);` en ignorant l'unique-espace
     (ou en le conservant tel quel si un seul contrôleur est concerné — comportement observable
     **strictement identique** puisqu'un lot mono-contrôleur ne touche qu'un espace). Aucun changement
     de signature publique côté `/acces/synchro`.
- **Idempotence — confirmation qu'elle est déjà acquise, et ce qui est réellement nouveau.** Le
  re-jeu du **même lot** (même `cleIdempotence`) est **déjà** sans effet aujourd'hui : la boucle
  vérifie `findOneBy(['cleIdempotence' => $cle])` **avant** d'appeler `ValidationPassageHandler::valider()`
  et court-circuite en `doublon` — ceci **n'est pas modifié**. Ce que la spec pointe comme « le code
  actuel refuse au rejeu » concerne un scénario **différent** : **deux passages distincts** (deux
  `cleIdempotence` différentes, un par borne) sur le **même** `DroitAcces`, où le second, faute de
  crédit, est aujourd'hui **refusé définitivement** (`PassageRefuseException`) au lieu d'être **accepté
  avec dépassement tracé** (CA-8). C'est ce second scénario, et lui seul, que couvre le changement §4.2 —
  la vraie idempotence technique (retransmission réseau) n'est **pas touchée**.

### 4.4 Nouveau case enum (additif, aucune migration de schéma)

```php
// App\Acces\Enum\CodeMotifRefus — ajouts
case HorsPortee = 'hors_portee';                              // §2.4 — equipementId hors portée du Terminal
case CreditEpuiseHorsLigneLitige = 'credit_epuise_hors_ligne_litige'; // §4.2 — accepté malgré dépassement (enConflit=true)
```
`Passage.codeMotif`/`ListeRevocation` etc. sont des colonnes `VARCHAR` sans `CHECK` en base (vérifié sur
les migrations existantes) : ajouter un `case` à un enum PHP backé ne requiert **aucune migration**.

---

## 5. Migrations

Une seule migration Doctrine (réversible, `up`/`down` symétriques) :

1. **`CREATE TABLE acces_terminal`** — colonnes §1.1, index `idx_terminal_itbox_ref (itbox_ref)`,
   `idx_terminal_etablissement (etablissement_id)`.
2. **`CREATE TABLE acces_jeton_terminal`** — colonnes §1.1, `UNIQUE KEY uniq_jeton_secret_hash
   (secret_hash)`, index `idx_jeton_terminal (terminal_id)`.
3. **`CREATE TABLE acces_journal_reconciliation`** — colonnes §1.2, index `idx_journal_passage
   (passage_id)`, `idx_journal_droit (droit_id)`, `idx_journal_statut (statut)`.
4. **`ALTER TABLE acces_support ADD COLUMN version_maj BIGINT NOT NULL`** + `CREATE INDEX
   idx_support_version_maj ON acces_support (version_maj)` — valeur initiale = backfill via une seule
   valeur de séquence par lot ou `0` (tout est `> depuis=0` de toute façon lors du premier snapshot
   complet, donc un backfill à `0` uniforme est correct : le premier appel `GET /terminal/snapshot`
   sans `depuis` renvoie **tout** indépendamment de `version_maj`).
5. **`CREATE SEQUENCE acces_snapshot_seq START WITH 1 INCREMENT BY 1`** (MariaDB natif).
6. **Insertion fixture/donnée** : `Permission(module='acces', action='snapshot')` dans `sec_permission`
   (idempotente — `INSERT ... ON DUPLICATE KEY UPDATE id = id` ou vérif. préalable) ; `Permission(module=
   'acces', action='ingestion')` **vérifiée présente**, insérée seulement si absente.
7. **Réversibilité (`down`)** : `DROP TABLE acces_journal_reconciliation`,
   `DROP TABLE acces_jeton_terminal`, `DROP TABLE acces_terminal`,
   `ALTER TABLE acces_support DROP COLUMN version_maj`, `DROP SEQUENCE acces_snapshot_seq`, suppression
   de la ligne `Permission(acces, snapshot)` insérée. Aucune table/colonne existante supprimée ou
   modifiée de façon non réversible (§ contrainte « pas de migration destructrice », constitution §7).

Aucune migration n'est nécessaire pour les deux nouveaux `case` `CodeMotifRefus` (§4.4) ni pour
l'extension de `EvenementPassageDto`/`SynchroPassageHandler` (code applicatif seul).

---

## 6. Tests (PHPUnit)

| Test | Type | Couvre |
|---|---|---|
| `TerminalAuthenticatorTest::testJetonInconnuRefuse401` | fonctionnel API | CA-1, CA-11 — jeton absent/inconnu → 401 générique sur les 3 endpoints `/terminal/*` |
| `TerminalAuthenticatorTest::testJetonRevoqueRefuse401` | fonctionnel API | CA-11 — révocation immédiate, aucune donnée transmise |
| `EnrolerTerminalProcessorTest::testSecretAfficheUneFoisSeulement` | fonctionnel API | CA-1 — secret en clair dans la réponse de création, absent des lectures suivantes |
| `TerminalPassageProcessorTest::testValidationEnLigneMoinsDUneSeconde` | fonctionnel API (mesure durée) | CA-2, RG-ACC-01 — `resultat=valide`, `message.codeMessage=BONNE_SEANCE`, `affichage` renseigné |
| `TerminalPassageProcessorTest::testSignatureInvalideMessageGenerique` | fonctionnel API | CA-3, RG-ACC-07 — code forgé → `codeMotif=signature_invalide`, `message.codeMessage=CODE_INVALIDE`, `affichage=null` |
| `TerminalPassageProcessorTest::testCarteEpuisee` | fonctionnel API | CA-4, RG-ACC-02 — `credit_epuise`, `CARTE_EPUISEE`, pas de décompte |
| `TerminalPassageProcessorTest::testEquipementHorsPorteeRefus403AvantMoteur` | fonctionnel API | §4.1/4.2 spec — équipement hors `itboxRef` → 403, aucune trace de passage créée |
| `SnapshotTerminalProviderTest::testSnapshotCompletPuisDeltaVide` | fonctionnel API | CA-5 — `GET /terminal/snapshot` (sans `depuis`) = ensemble complet + `versionCourante` ; rappel avec `depuis=versionCourante` = liste vide |
| `SnapshotTerminalProviderTest::testTombstoneSupportBloque` | fonctionnel API | CA-6 — blocage post-snapshot → entrée `revoque=true` au delta suivant |
| `SnapshotTerminalProviderTest::testPaginationSnapshotComplet` | fonctionnel API | §4.3 spec — `page`/`taille`, `jusqua` stable entre pages |
| `TerminalSynchroProcessorTest::testLotDecrementeCredit` | fonctionnel API | CA-7 (1ʳᵉ moitié) — lot hors-ligne décrémente le crédit, journalise `origineHorsLigne=true` |
| `TerminalSynchroProcessorTest::testRejeuMemeLotIdempotent` | fonctionnel API | CA-7 (2ᵉ moitié) — repost du même lot (mêmes `cleIdempotence`) → `statut=doublon` partout, **aucun** second décompte, aucun second `Passage` créé |
| `TerminalSynchroProcessorTest::testEquipementHorsPorteeRejeteSansBloquerLeLot` | fonctionnel API | §2.4 — une entrée hors portée → `statut=rejete`, les autres entrées du lot traitées normalement |
| `ValidationPassageHandlerTest::testCreditNegatifBorneEnHorsLigneUniquement` | unitaire | CA-8 — deux `EvenementPassageDto` (même droit, crédit=1), 1ᵉʳ avec `autoriserCreditNegatifSiHorsLigne=false` décrémente normalement 1→0 ; 2ᵉ avec `=true` accepté, `creditRestant` négatif borné, `enConflit=true`, `JournalReconciliation` créé |
| `ValidationPassageHandlerTest::testCreditEpuiseEnLigneRefuseSansRegression` | unitaire | non-régression — `autoriserCreditNegatifSiHorsLigne=false` (défaut, flux en ligne) sur crédit épuisé → toujours `PassageRefuseException(CreditEpuise)`, comportement 100% identique à avant ce plan |
| `SynchroPassageHandlerTest::testRecalageFmiMultiEspaces` | unitaire | CA-9 — lot touchant 2 espaces distincts (2 contrôleurs d'un même `itboxRef`) → recalage FMI appliqué aux deux |
| `TerminalSynchroProcessorTest::testEcartHorlogeSuspectSignaleSansBloquer` | fonctionnel API | CA-10 — `horodatageBorne` décalé de > 5 min → passage journalisé quand même, `ecartHorlogeSuspect=true` |
| `TerminalPermissionVoterTest::testPermissionsFixesUniquement` | unitaire | §3.2 — un `TerminalUtilisateur` n'obtient jamais `acces.gerer`/`acces.superviser`, seulement `ingestion`/`snapshot` |
| `PermissionVoterNonRegressionTest::testTerminalNeDebloquePasPermUtilisateur` | unitaire | non-régression — un token `Terminal` présenté à `PermissionVoter`/attribut `PERM` classique est toujours refusé (types disjoints) |
| `AccesPassagesSynchroNonRegressionTest` (étend `tests/Acces/Api/...` existant) | fonctionnel API | non-régression — `/acces/passages` et `/acces/synchro` produisent des réponses **bit-à-bit identiques** à l'existant sur les scénarios déjà couverts par `plan-acces.md` |

---

## 7. Tâches (voir `tasks-acces-terminal.md`)

Ordonnancement en **lots**, chaque lot livrable/testable indépendamment :

- **Lot A — Identité machine** : T1 entités `Terminal`/`JetonTerminal` + migration · T2
  `TerminalAuthenticator` + firewall `terminal` + `TerminalUtilisateur` · T3
  `TerminalPermissionVoter` (`PERM_TERMINAL`) · T4 endpoints admin `/acces/terminaux*`
  (enrôlement/rotation/révocation) + tests CA-1/CA-11.
- **Lot B — Validation en ligne enrichie** : T5 `CodeMessageAffichage` + `CatalogueMessageAffichage`
  · T6 `AffichagePorteurResolver` · T7 `TerminalPorteeChecker` · T8 `TerminalPassageProcessor` +
  opération `/terminal/passages` · T9 tests CA-2/CA-3/CA-4.
- **Lot C — Snapshot** : T10 colonne `Support.versionMaj` + `acces_snapshot_seq` + points de mise à
  jour (§1.4) · T11 `SnapshotTerminalProvider` + `EntreeSnapshotDto` · T12 tests CA-5/CA-6 +
  pagination.
- **Lot D — Remontée hors-ligne & réconciliation** *(dépend de A, B, C pour la portée/le catalogue)* :
  T13 extension `EvenementPassageDto` (additive) · T14 branche crédit négatif borné dans
  `ValidationPassageHandler` (flag off par défaut) + `JournalReconciliation` · T15 généralisation
  `SynchroPassageHandler::synchroniser()` → `synchroniserPourTerminal()` (multi-espaces) · T16
  `TerminalSynchroProcessor` + opération `/terminal/passages/lot` · T17 tests CA-7/CA-8/CA-9/CA-10 +
  non-régression `/acces/passages`/`/acces/synchro`.
- **Lot E — Durcissement** : T18 throttling `dernierAppel` · T19 fixtures démo (`AccesFixtures`
  étendues avec 1-2 `Terminal` de démonstration) · T20 revue perf (< 1 s en ligne avec le nouveau
  chemin d'authentification + résolution portée) · T21 documentation OpenAPI générée (vérif. schémas
  `message`/`affichage`).

---

## 8. Risques / à valider

- **R-1 (technique, décision assumée)** — `JetonTerminal.secretHash` utilise **SHA-256 déterministe**
  (recherche indexée en `O(1)`) et **non** bcrypt/argon2 comme les mots de passe `Utilisateur`
  (`RG-SOCLE-06`) : un hash déterministe est **nécessaire** pour retrouver le jeton par égalité en base
  sans itérer sur toute la table (bcrypt est volontairement non déterministe). C'est le même compromis
  que les jetons API de type clé secrète (GitHub PAT, Stripe) — **acceptable** car le secret a une forte
  entropie native (généré côté serveur, jamais un mot de passe choisi par un humain) mais **à valider
  explicitement** avec IT Cotation/sécurité avant mise en production (mention explicite dans la revue
  de sécurité du lot A).
- **R-2 (spec §8 pt.3, non tranché)** — pas de **période de grâce** à la rotation d'un `JetonTerminal` :
  l'ancien est révoqué immédiatement à l'émission du nouveau. Un déploiement matériel qui ne bascule
  pas instantanément (ex. mise à jour firmware différée) subira un 401 transitoire. **À confirmer** avec
  IT Cotation selon la procédure réelle de déploiement (§8 spec pt.3).
- **R-3 (donnée manquante)** — `App\Vente\Entity\BilletSupport` (code lu, vérifié dans ce plan) ne porte
  **aucun champ `nomPorteur`** aujourd'hui : `affichage.nomPorteur` sera **systématiquement `null`**
  tant que M2/CRM n'expose pas cette donnée (rattachement nominatif d'un billet, hors périmètre de ce
  document). CA-2 reste satisfait (le champ est **optionnel**, « si connu ») mais l'exemple `§4.2 spec`
  (« J. Dupont ») ne sera pas reproductible en l'état — **à signaler explicitement à IT Cotation**, et
  cohérent avec le cas limite RGPD déjà soulevé (§7 spec, minimisation du nom sur écran public) : ce
  plan **ne bloque pas** sur ce point, il **documente** l'absence de source.
- **R-4 (volumétrie, spec §8 pt.5)** — taille de page snapshot (défaut 500, proposé ici) et taille de
  paquet de lot hors-ligne (pas de limite dure imposée dans ce plan, le lot est traité en une
  transaction logique par entrée, donc naturellement incrémental) **non validées par IT Cotation** —
  configurables via env, à ajuster après tests de charge réels sur matériel ITBOX.
- **R-5 (simplification assumée)** — `portesEligibles` dans le snapshot est calculé **statiquement**
  (sous-réseau/fédération) et ne reflète **pas** les marges horaires par équipement (`margeAvance`/
  `margeRetard`) ni les fenêtres horaires détaillées (`fenetresHoraires`, déjà marqué « non détaillé »
  par la spec elle-même, §5). La validation locale « hors-ligne » de la borne sera donc **moins précise**
  que le moteur serveur sur ces critères — cohérent avec la limite déjà actée §4.3 spec (anti-passback
  local moins fiable), mais **à re-confirmer** que ce degré de fidélité est suffisant pour IT Cotation.
- **R-6 (écart de comportement métier, LE point le plus sensible du plan, CA-8)** — la réconciliation
  gracieuse (crédit négatif borné + `enConflit` + `JournalReconciliation`) **change le résultat
  fonctionnel** d'un passage hors-ligne en cas de double-consommation (aujourd'hui : refusé
  définitivement ; proposé : accepté avec litige tracé). Le plan l'implémente **derrière un flag
  inactif par défaut** pour ne rien casser en ligne, mais **le comportement du flux hors-ligne
  lui-même change** dès l'implémentation du Lot D — **ne pas livrer le Lot D sans validation explicite
  d'IT Cotation** sur ce point précis (§8 spec pt.4, non actée). Alternative de repli si non validé :
  Lot D livré avec `autoriserCreditNegatifSiHorsLigne` **toujours `false`** (= comportement actuel
  conservé, passage refusé au rejeu comme aujourd'hui) — le reste du plan (idempotence, multi-espaces,
  portée, skew horloge) reste valide indépendamment de cette décision.
- **R-7 (hors périmètre confirmé)** — `App\Acces\Adapter\ItboxAdapter` reste un squelette qui lève une
  exception (protocole ITBOX non cadré, cf. code lu) : ce plan **ne dépend pas** de cet adaptateur (il
  spécifie le contrat HTTP/JSON backend ↔ matériel, pas le pilotage bas niveau) mais rappelle que
  l'**intégration réelle** avec le concentrateur ITBOX reste un point ouvert n°1 de `spec-acces.md`,
  identique pour ce document (§8 spec).
- **R-8 (convergence M8 différée)** — `Terminal` tel que défini ici est un objet **minimal anticipé**
  (nom, portée `itboxRef`, statut) ; la convergence avec l'inventaire matériel M8 (L7, non construit)
  n'est **pas traitée** par ce plan — à reprendre explicitement au plan M8/L7 (§8 spec pt.7).
