# Plan technique — Contrôle d'accès (`Accès` / lot `L3`)

- **Spec source :** specs/L3-acces/spec-acces.md
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Dépend de :** socle L0 (specs/L0-socle/plan-socle.md), M1 offre (specs/L1-offre/plan-offre.md), M2 vente (specs/L2-vente/plan-vente.md) — **réutilisés, non redéfinis**
- **Couvre :** US-L3-01 à US-L3-12 · RG-ACC-01 à RG-ACC-07 · CA-1 à CA-14

> **Réutilisation socle L0 (à ne pas dupliquer)** — hiérarchie `App\Organisation\Entity\{Groupe,Region,Etablissement,Espace}` (RG-SOCLE-01) ; `PermissionVoter` (attribut `PERM`, sujet `"module.action"`, RG-SOCLE-04) ; service `ContexteEtablissement` (en-tête `X-Etablissement`, RG-SOCLE-05) ; identité des agents (`Utilisateur`, RG-SOCLE-06) ; `App\Audit\Entity\EntreeAudit` append-only (RG-SOCLE-07) sur lequel s'adosse le **journal des passages en lecture seule**. Toutes les entités racines L3 portent un `ManyToOne` vers `Etablissement`/`Espace` **du socle** ; aucune ré-implémentation des droits, du contexte ou de l'audit ici.
>
> **Réutilisation M1 offre (à ne pas redéfinir)** — `App\Offre\Entity\{Produit, Formule, CarteMultiEntrees}` portent les **droits d'accès** (RG-M1-03), le **stock de compostages** (carte multi-entrées = RG-M1-04/13), les **marges par défaut** et la facette **sous-réseau**. L3 **consomme et décompte** ces droits ; il en fait une **projection locale** (`DroitAcces`, §1.4) pour le cache hors-ligne, la source de vérité restant M1/M2.
>
> **Réutilisation M2 vente (à ne pas redéfinir)** — `App\Vente\Entity\BilletSupport` (identifiant QR/RFID/wallet, `statutAppairage`, `nbCompostages`) est **émis et appairé à la vente** (RG-M2-04, US-L2-08). L3 **prolonge** l'appairage (borne autonome, ré-appairage après révocation) et **consomme** les **dévalidations** émises par M2 (`Avoir.supportInvalide`, RG-M2-07/US-L2-09). Le **pattern de synchro hors-ligne idempotent** (clé d'idempotence + rejeu chronologique + file locale, M2 §4) est **réutilisé** pour la file de passages.

---

## 1. Entités & schéma

Namespace : **`App\Acces\Entity\*`**. `id` = UUID (`Symfony\Component\Uid\Uuid`, type Doctrine `uuid`, idéalement **UUIDv7** pour l'ordre chronologique des passages hors-ligne). `declare(strict_types=1)` partout (constitution §3). Noms métier en français (constitution §7). Toute entité racine porte un `ManyToOne` vers `Etablissement` **du socle** (RG-SOCLE-01) et suit le cloisonnement `ContexteEtablissement` (RG-SOCLE-05).

### 1.1 Topologie — EspaceAcces › Controleur › Equipement (US-L3-01, écran A-01)

| Entité (`App\Acces\Entity\*`) | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **EspaceAcces** | id | `uuid` | non | PK | — |
| | libelle | `string(120)` | non | requis | — |
| | espaceSocle | — | non | FK `nullable:false` | `ManyToOne` → `Espace` (socle, RG-SOCLE-01) |
| | seuilFmi | `integer` | non | `≥ 0` (check) | seuil de présence simultanée par espace (RG-ACC-04) |
| | modeSeuil | `string(12)` enum `ModeSeuil` {blocage, alerte} | non | défaut paramétré par espace | décision actée (§4.5) |
| | preAlertePct | `smallint` | oui | `0..100` | pré-alerte à X % (hook piscine L6, §4.5) |
| | antiPassbackActif | `boolean` | non | défaut true | réglage **espace** (surchargeable équipement) |
| | antiPassbackDelai | `integer` (secondes) | non | `> 0`, **défaut 300** (~5 min, décision actée) | US-L3-01 |
| | recalageOuverture | `string(16)` enum `ModeRecalage` {remise_a_zero, report_residuel} | non | **défaut `remise_a_zero`** (arbitrage §4.5) | ⚠ à confirmer sécurité ERP |
| | sousReseau | — | oui | FK | `ManyToOne` → `SousReseau` (§1.7) |
| **Controleur** | id | `uuid` | non | PK | — |
| | libelle | `string(120)` | non | requis | — |
| | espace | — | non | FK `nullable:false` | `ManyToOne` → `EspaceAcces` (1 espace) |
| | itboxRef | `string(128)` | non | identifiant du concentrateur ITBOX | référence logique matériel (pas de FK dure) |
| | etat | `string(16)` enum `EtatControleur` {en_ligne, hors_ligne, hors_service} | non | défaut `en_ligne` | cycle §4.6 |
| | dernierHeartbeat | `datetime_immutable` | oui | — | supervision réseau (§4.6, RG-ACC-05) |
| | versionRevocation | `integer` | non | défaut 0 | version de liste embarquée (§4.6/4.7) |
| **Equipement** | id | `uuid` | non | PK | — |
| | libelle | `string(120)` | non | requis | — |
| | controleur | — | non | FK `nullable:false` | `ManyToOne` → `Controleur` (pas d'orphelin, CA-1) |
| | type | `string(16)` enum `TypeEquipement` {tourniquet, tripode, lecteur} | non | requis | iDTRONIC / QR / RFID |
| | sens | `string(16)` enum `SensPassage` {entree, sortie, bidirectionnel} | non | **requis** (sens manquant = enregistrement bloqué, CA-1) | impact FMI ±1 (RG-ACC-04) |
| | antiPassbackActif | `boolean` | oui | `null` = hérite de l'espace | surcharge équipement (US-L3-01) |
| | antiPassbackDelai | `integer` (secondes) | oui | `null` = hérite ; `> 0` sinon | surcharge équipement |
| | margeAvance | `integer` (minutes) | non | `≥ 0`, défaut 0 | tolérance locale (A-01) ⚠ combinaison M1 §4.3 |
| | margeRetard | `integer` (minutes) | non | `≥ 0`, défaut 0 | tolérance locale (A-01) |

> **Cohérence bloquante (CA-1)** — validateur d'ensemble `TopologieCoherente` (service) refusant à l'enregistrement (422) : équipement sans contrôleur, contrôleur sans `itboxRef`, `sens` manquant, `EspaceAcces.seuilFmi` absent. La cohérence n'est pas seulement une contrainte SQL (FK `nullable:false`) mais une **garde applicative** listant chaque incohérence.

### 1.2 Support & Appairage (US-L3-02, écran A-02)

| Entité (`App\Acces\Entity\*`) | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **Support** | id | `uuid` | non | PK | — |
| | identifiant | `string(128)` | non | **unique** (identifiant lu par lecteur) | miroir de `BilletSupport.identifiantSupport` (M2) |
| | type | `string(12)` enum `TypeSupport` {QR, RFID, wallet} | non | requis | RFID = bracelet étanche piscine |
| | statut | `string(12)` enum `StatutSupport` {actif, bloque} | non | défaut `actif` | `bloque` si perte/vol (RG-ACC-07) |
| | etablissement | — | non | FK | `ManyToOne` → `Etablissement` (socle) |
| **Appairage** | id | `uuid` | non | PK | — |
| | support | — | non | FK | `ManyToOne` → `Support` |
| | droit | — | non | FK | `ManyToOne` → `DroitAcces` |
| | mode | `string(12)` enum `ModeAppairage` {caisse, autonome} | non | requis (`autonome` = borne libre-service) | US-L3-02, A-02 |
| | actif | `boolean` | non | **1 seul actif par support** (index unique partiel, §7) | unicité (US-L3-02) |
| | dateAppairage | `datetime_immutable` | non | — | — |
| | agent | — | oui | FK | `ManyToOne` → `Utilisateur` (socle) ; null si borne autonome |

> **Unicité (CA-2)** — index unique partiel « un seul appairage actif par support » (colonne générée `support_actif` = `support_id` si `actif=true`, sinon NULL + index unique, même technique que M2 §7). Un appairage sur support **déjà appairé actif** ou **`bloque`** est refusé (409/422) avec **message explicite**. Le ré-appairage exige la **révocation préalable** de l'appairage actif.

### 1.3 DroitAcces — projection locale (RG-ACC-01/02, point ouvert n°9)

| Entité (`App\Acces\Entity\*`) | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **DroitAcces** | id | `uuid` | non | PK | **projection** d'un droit vendu M1/M2 |
| | sourceType | `string(24)` enum {billet, abonnement, carte_quota} | non | pilote le décompte | RG-M1-03/04 |
| | billetSupportRef | `uuid` | oui | ref logique `BilletSupport` (M2), pas de FK dure | traçabilité vers la vente |
| | produitRef | `uuid` | oui | ref logique `Produit` (M1) | droits/marges par défaut |
| | fenetreDebut / fenetreFin | `datetime_immutable` | oui | fenêtre de validité métier | marges appliquées (RG-ACC-01) |
| | creditRestant | `integer` | oui | `≥ 0` (check) ; requis si `carte_quota` | **cache** du stock de compostages M1 (RG-M1-04/13) |
| | margeAvanceDefaut / margeRetardDefaut | `integer` (min) | oui | `≥ 0` | marges **portées par le droit** (M1) — intersection avec équipement §4.3 |
| | sousReseau | — | oui | FK | `ManyToOne` → `SousReseau` ; hérité du produit M1 |
| | statutProjection | `string(12)` enum {valide, devalide} | non | défaut `valide` | `devalide` = consomme la dévalidation M2 (`Avoir.supportInvalide`) |
| | etablissement | — | non | FK | `ManyToOne` → `Etablissement` (socle) |
| | synchroniseLe | `datetime_immutable` | oui | — | fraîcheur de la projection |

> **DÉCISION — projection locale (point ouvert n°9)** — `DroitAcces` est une **copie de travail** du droit vendu (M1/M2) : elle cache `fenetreValidité`, `creditRestant` et les marges pour que le contrôleur **valide < 1 s** (US-L3-03) **y compris hors-ligne**. La **source de vérité reste M1/M2** ; la réconciliation `creditRestant` (décompte tourniquet) ↔ stock de compostages M1 se fait à la synchro via clé d'idempotence (§4.6, cf. parallèle M2 §4). Alimentée par un port `ProjectionDroitInterface` (implémenté par un consommateur d'événements M1/M2 ; **stub** de projection en L3 pour les tests). ⚠ Mécanique de réconciliation crédit/compostages **à préciser à l'intégration L4** (Risque n°8).

### 1.4 Passage — source unique (RG-ACC-06, écran A-05)

| Entité (`App\Acces\Entity\*`) | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **Passage** | id | `uuid` | non | PK (UUIDv7 conseillé) | source unique (RG-ACC-06) |
| | horodatage | `datetime_immutable` | non | **précision seconde**, requis | A-05 |
| | espace / controleur / equipement | — | non/oui/oui | FK | point de franchissement (equipement null si mobile A-04) |
| | support | — | oui | FK | vide si sans support (RG-ACC-03) |
| | droit | — | oui | FK | vide si non nominatif (RG-ACC-03) |
| | sens | `string(12)` enum `SensPassage` {entree, sortie} | non | requis | impact FMI ±1 |
| | resultat | `string(12)` enum `ResultatPassage` {valide, refuse, compte} | non | requis | §4.3/4.4 |
| | motif | `string(255)` | oui | **requis si** `refuse`/manuel/non nominatif | US-L3-04/10, A-03 |
| | codeMotif | `string(32)` enum `CodeMotifRefus` {hors_marge, anti_passback, credit_epuise, support_bloque, seuil_fmi, droit_invalide, sens_interdit, non_nominatif, ouverture_manuelle} | oui | motif structuré (filtrage journal) | §4.3, CA-11/12 |
| | origineHorsLigne | `boolean` | non | défaut false | validé sans réseau (§4.6) |
| | cleIdempotence | `uuid` | non | **unique** (générée au point de franchissement) | anti-doublon à la synchro (CA-9/12) |
| | agent | — | oui | FK | `ManyToOne` → `Utilisateur` (ouverture manuelle / +1) |
| | etablissement | — | non | FK | `ManyToOne` → `Etablissement` (socle) |

> **Append-only / lecture seule (CA-14)** — `Passage` est protégé par un listener Doctrine `preUpdate`/`preRemove` → `PassageInalterableException` (même pattern que M2 §2) ; **aucune** opération `PATCH`/`DELETE` d'API. La création via l'ingestion (§3) journalise en plus dans `EntreeAudit` du socle (RG-SOCLE-07). C'est la **source unique** alimentant M6 (compta) et M7 (reporting) — L3 ne valorise ni n'analyse (RG-ACC-06).

### 1.5 JaugeFmi — présence simultanée (RG-ACC-04)

| Entité (`App\Acces\Entity\*`) | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **JaugeFmi** | id | `uuid` | non | PK | — |
| | espace | — | non | **OneToOne** unique | `OneToOne` → `EspaceAcces` (1 jauge par espace) |
| | valeurCourante | `integer` | non | `≥ 0` (check) | présents = Σ(entrées) − Σ(sorties) |
| | seuil | `integer` | non | `≥ 0` ; miroir de `EspaceAcces.seuilFmi` | RG-ACC-04 |
| | mode | `string(12)` enum `ModeSeuil` {blocage, alerte} | non | miroir `EspaceAcces.modeSeuil` | décision actée |
| | cumulJour | `integer` | non | `≥ 0` ; **compteur distinct** (n'augmente que) | RG-ACC-04 : FMI ≠ cumul |
| | dateReference | `date_immutable` | non | jour courant (recalage à l'ouverture) | §4.5 |

> **Concurrence** — l'incrément/décrément se fait par **UPDATE conditionnel atomique** (cf. M2 §6) : au **blocage**, l'entrée n'est acceptée que si `valeurCourante < seuil` — `UPDATE acces_jauge_fmi SET valeur_courante = valeur_courante + 1 WHERE espace_id = :id AND valeur_courante < :seuil` ; 0 ligne ⇒ seuil atteint ⇒ refus (`codeMotif=seuil_fmi`). En **alerte**, l'incrément est inconditionnel et un incident de supervision est émis. `cumulJour` est incrémenté séparément à chaque entrée validée/comptée.

### 1.6 Liste de révocation & file hors-ligne (US-L3-07/08/09)

| Entité (`App\Acces\Entity\*`) | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **ListeRevocation** | id | `uuid` | non | PK | RG-ACC-07 |
| | controleur | — | non | FK | `ManyToOne` → `Controleur` (embarquée par contrôleur) |
| | version | `integer` | non | croissante ; `(controleur, version)` | propagée à la synchro (US-L3-09) |
| | supportsBloques | `json` | non | liste d'`identifiant` de supports bloqués | embarquée (US-L3-07) |
| | genereLe | `datetime_immutable` | non | — | fraîcheur |
| **DeclarationPerteVol** | id | `uuid` | non | PK | US-L3-09 |
| | support | — | non | FK | `ManyToOne` → `Support` |
| | motif | `string(255)` | non | requis | tracé (RG-ACC-07) |
| | agent | — | non | FK | `ManyToOne` → `Utilisateur` (socle) |
| | horodatage | `datetime_immutable` | non | — | tracé |
| | annulee | `boolean` | non | défaut false ; **réversible** par rôle habilité | US-L3-09 |
| | annuleePar / annuleeLe | FK / `datetime_immutable` | oui | — | réversibilité tracée |

> **File de passages hors-ligne** — modélisée par le **flag `Passage.origineHorsLigne` + `cleIdempotence`** (pas de table dédiée : la file physique vit **côté contrôleur/ITBOX**, hors périmètre API). L'endpoint `POST /acces/synchro` (§3) remonte le lot ordonné par `horodatage`, rejoué de façon idempotente (§4.6). Cohérent avec M2 §4 (la file locale est côté poste, l'API expose la remontée).

### 1.7 Sous-réseau & fédération (US-L3-12)

| Entité (`App\Acces\Entity\*`) | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **SousReseau** | id | `uuid` | non | PK | US-L3-12 |
| | libelle | `string(120)` | non | requis | — |
| | actif | `boolean` | non | défaut false | activable/désactivable (décision actée) |
| | espaces | — | — | `≥ 1` | `ManyToMany` → `EspaceAcces` (périmètre partagé) |
| | droitsEligiblesRef | `json` | oui | UUID de `Produit`/type de droit (M1) | reconnaissance mutuelle |
| | seuilFmiAgrege | `integer` | oui | `≥ 0` | ⚠ FMI sous-réseau vs espace §4.11 (Risque n°7) |
| | antiPassbackDelai | `integer` (sec) | oui | `> 0` | anti-passback partagé du sous-réseau (US-L3-12) |

> **Accords de sous-réseau** — la facette « sous-réseau » du droit est **paramétrée en amont par M1** (`spec-offre.md`) ; L3 porte la **topologie fédérée** (quels espaces, quels droits éligibles) et **applique** la jauge/anti-passback du sous-réseau au passage fédéré. Fédération **désactivée** ⇒ franchissement inter-entités refusé (CA-13). ⚠ Articulation FMI sous-réseau **en plus** vs **au lieu de** la FMI d'espace non tranchée (Risque n°7).

---

## 2. Intégration matériel enfichable — Ports & adaptateurs (CLÉ)

Objectif : **aucun protocole matériel codé en dur dans le domaine**. Le domaine Accès raisonne sur des **ports** (interfaces) ; les protocoles réels (OSDP lecteurs, API REST/temps réel ITBOX/SmartAccess) vivent dans des **adaptateurs** interchangeables, exactement comme M2 isole NF525 derrière `SignataireOperation`. Cela permet de **développer et tester sans matériel** (adaptateur simulateur) et d'absorber le contrat d'échange iDTRONIC/ITBOX **quand il sera cadré**, sans toucher au métier.

### 2.1 Port principal — `App\Acces\Port\PiloteAcces`

Interface (contrat métier, agnostique du transport) :

- `ouvrir(Equipement $eq, OuvertureContexte $ctx): ResultatCommande` — commande l'ouverture physique d'un équipement (franchissement autorisé ou ouverture manuelle tracée) ; `$ctx` porte l'agent/motif pour l'ouverture manuelle.
- `recevoirEvenement(EvenementPassageDto $evt): void` — **ingestion** d'un événement de passage remonté par le matériel (scan au lecteur) → délègue au `ValidationPassageHandler` (§4.3). *(Côté entrant : le matériel/ITBOX appelle `POST /acces/passages` §3, qui traduit en `EvenementPassageDto`.)*
- `heartbeat(Controleur $c): EtatControleurDto` — état/vivacité d'un contrôleur (online/offline, horodatage) alimentant la supervision (§4.6).
- `pousserListeRevocation(Controleur $c, ListeRevocation $liste): ResultatCommande` — pousse la liste de révocation embarquée à jour vers le contrôleur (propagation §4.7).

> Types d'échange (`OuvertureContexte`, `EvenementPassageDto`, `EtatControleurDto`, `ResultatCommande`) sont des **DTO du domaine** — **aucun** champ propre à OSDP/REST n'y transparaît ; la traduction protocolaire est confinée à l'adaptateur.

### 2.2 Adaptateurs (implémentations enfichables)

| Adaptateur (`App\Acces\Adapter\*`) | Rôle | Protocole | Statut |
|---|---|---|---|
| **SimulateurAccesAdapter** | Simulateur logiciel (ouvre/refuse en mémoire, génère événements, faux heartbeat) | aucun (in-process) | **livré en L3** — support des tests automatisés (simulateur matériel, CA-3/4/8) |
| **ItboxAdapter** | Dialogue avec le concentrateur **ITBOX** (commande d'ouverture, remontée d'événements, heartbeat, push révocation) | **API REST/temps réel — À CONFIRMER** | squelette + config ; contrat à cadrer IT Cotation |
| **SmartAccessAdapter** | Intégration au logiciel **SmartAccess** / lecteurs **iDTRONIC** | **OSDP et/ou API — À CONFIRMER** | squelette + config ; contrat à cadrer IT Cotation |

- **Sélection de l'adaptateur** : injection Symfony par **alias configurable** (`config/services.yaml` : `App\Acces\Port\PiloteAcces: '@app.acces.pilote'`, `app.acces.pilote` pointant vers le simulateur en `dev`/`test`, vers `ItboxAdapter` en `prod`). Aucun `if` protocolaire dans le domaine ; le choix est une **ligne de config**.
- **Résilience latence < 1 s (US-L3-03)** : la **validation métier** (§4.3) est **synchrone et locale** (projection `DroitAcces` + jauge en base), **indépendante** de la disponibilité de l'adaptateur ; l'appel `ouvrir()` est le seul aller-retour matériel, borné par timeout de l'adaptateur. En cas d'indisponibilité, le contrôleur bascule hors-ligne (§4.6).

> ⚠ **À CADRER AVEC IT COTATION (ne bloque pas le développement)** — le **contrat d'échange** iDTRONIC/ITBOX/SmartAccess (protocole OSDP vs API REST/temps réel, format de la commande d'ouverture, schéma de remontée d'événement, cadence de heartbeat, **format de la liste de révocation embarquée**, latence garantie) n'est **pas spécifié** (point ouvert n°1, priorité haute). Le design **ports & adaptateurs** garantit que ces choix se traduisent par une **implémentation d'adaptateur**, **sans modifier** `PiloteAcces` ni le domaine. Le **SimulateurAccesAdapter** débloque tout le développement et les tests en attendant.

---

## 3. API (API Platform)

Toutes ressources : `#[ApiResource]`, `security` via le **`PermissionVoter` du socle** → `is_granted('PERM', 'acces.<action>')`. Lecture cadrée par `ContexteEtablissement` (extension Doctrine du socle étendue à `App\Acces`). Les mutations à invariant fort (validation de passage, appairage, ouverture manuelle, synchro) sont des **opérations métier custom** (State Processors / contrôleurs fins délégant à un handler testable), pas du CRUD Doctrine brut.

| Ressource | Opérations | `security:` | Groupes | Type |
|---|---|---|---|---|
| **EspaceAcces** | GET coll/item ; POST ; PATCH | `acces.lire` (GET) / `acces.gerer` (écriture) | `espace:read/write` | config arbre — garde `TopologieCoherente` (CA-1) |
| **Controleur** | GET, POST, PATCH | `acces.lire` / `acces.gerer` | `ctrl:read/write` | config |
| **Equipement** | GET, POST, PATCH | `acces.lire` / `acces.gerer` | `equip:read/write` | config — `sens` requis (CA-1) |
| **Appairage** | GET coll ; `POST /acces/appairages` | `acces.lire` / `acces.appairer` | `appairage:read/write` | **custom** — unicité + refus bloqué/déjà appairé (CA-2) |
| | `POST /acces/appairages/{id}/revoquer` | `acces.appairer` | — | **custom** — libère le support pour ré-appairage |
| **Passage** | GET coll/item | `acces.lire` | `passage:read`, `passage:list` | **lecture seule** (journal, CA-12) |
| | `POST /acces/passages` | `acces.ingestion` *(acteur Système/ITBOX)* | `passage:ingestion` | **custom — ingestion** depuis matériel/ITBOX → `ValidationPassageHandler` (§4.3, CA-3) |
| | `POST /acces/passages/manuel` | `acces.ouvrir_manuel` | `passage:manuel` | **custom** — ouverture manuelle tracée (agent+motif requis, CA-7) |
| | `POST /acces/passages/non-nominatif` | `acces.superviser` ou `acces.controler` | `passage:compte` | **custom** — « +1 » bébé/accompagnant, motif requis (CA-5) |
| | `GET /acces/passages/export` | `acces.lire` | — | **custom** — export filtré (CA-12) |
| **JaugeFmi** | GET coll/item | `acces.superviser` | `jauge:read` | temps réel (présents vs seuil + cumul) |
| **Supervision** | `GET /acces/supervision` | `acces.superviser` | `supervision:read` | **custom** — vue live agrégée : jauges, flux passages, incidents, statut réseau (CA-7) |
| **DeclarationPerteVol** | GET coll ; `POST /acces/supports/{id}/bloquer` | `acces.lire` / `acces.bloquer_support` | `pertevol:read/write` | **custom** — blocage serveur immédiat + révocation (CA-10) |
| | `POST /acces/declarations/{id}/annuler` | `acces.bloquer_support` | — | **custom** — réversible par rôle habilité (CA-10) |
| **Synchro** | `POST /acces/synchro` | `acces.ingestion` | `sync:write` | **custom** — rejeu chronologique idempotent + recalage FMI/crédits + conflits (§4.6, CA-8/9) |
| | `GET /acces/synchro/etat` | `acces.superviser` | — | en ligne / hors-ligne / synchro en cours (CA-8) |
| **SousReseau** | GET, POST, PATCH | `acces.lire` / `acces.gerer` | `sousreseau:read/write` | fédération activable (CA-13) |

- **`POST /acces/passages` (ingestion)** est l'endpoint pivot : le matériel/ITBOX (via `ItboxAdapter`) y remonte chaque scan ; le handler exécute l'algorithme §4.3 et **répond `{resultat, codeMotif}`** en < 1 s, puis (si validé) déclenche `PiloteAcces::ouvrir()`. Idempotent via `Passage.cleIdempotence`.
- **Groupes de sérialisation** : UUID exposé ; `passage:read` expose horodatage/rattachements/résultat/motif en **lecture seule** ; les champs `creditRestant`/liste de révocation ne sont jamais inscriptibles via l'API de config.
- **Custom vs CRUD** : seule la **config topologie** (Espace/Controleur/Equipement/SousReseau) et les **lectures** sont proches du CRUD ; validation, appairage, blocage, synchro, ouverture manuelle sont des **opérations métier** (invariants : atomicité crédit/jauge, unicité appairage, immuabilité passage).

---

## 4. Algorithmes structurants

### 4.1 Résolution anti-passback (hiérarchie défaut → espace → équipement)

**DÉCISION appliquée (arbitrage point ouvert n°4)** — le délai/état effectif est résolu par **spécificité croissante, le plus spécifique gagnant** :
1. **défaut système** ~5 min (300 s) ;
2. surchargé par **`EspaceAcces.antiPassbackActif/Delai`** ;
3. surchargé par **`Equipement.antiPassbackActif/Delai`** si non `null`.

Service `ResolveurAntiPassback::resoudre(Equipement): {actif:bool, delai:int}`. Concilie la décision actée (« surchargeable par espace ») et US-L3-01 (« espace + équipement »). ⚠ à confirmer M8/métier (Risque n°3).

### 4.2 Résolution des marges (intersection droit ∩ équipement)

**DÉCISION appliquée (arbitrage point ouvert n°3)** — la **marge effective = intersection** de la fenêtre du droit élargie de ses marges par défaut (M1) et de la tolérance locale de l'équipement :
`fenetreEffective = [max(fenetreDebit − margeAvanceDroit, fenetreDebut − margeAvanceEquip)…]` → concrètement, l'équipement **borne** une tolérance locale, le droit porte la **fenêtre métier** ; un passage est dans les marges s'il satisfait **les deux**. Service `ResolveurMarges::estDansMarges(DroitAcces, Equipement, DateTimeImmutable): bool`. ⚠ à arbitrer M1 (Risque n°2).

### 4.3 Validation d'un passage (US-L3-03, RG-ACC-01/02 — CA-3/4)

`ValidationPassageHandler::valider(EvenementPassageDto): ResultatPassage` — **synchrone, local, < 1 s**, exécuté sur ingestion `POST /acces/passages` **et** en local hors-ligne (§4.6). Séquence (court-circuit au premier refus, chaque refus = `codeMotif` structuré) :

1. **Résolution support/droit** — retrouver `Support` par `identifiant` + son `Appairage` actif → `DroitAcces`. Support inconnu/sans appairage actif ⇒ `refuse / droit_invalide`.
2. **Support bloqué** — `Support.statut = bloque` **ou** présent en liste de révocation embarquée ⇒ `refuse / support_bloque` (vaut **online et hors-ligne**, RG-ACC-07).
3. **Droit valide** — `statutProjection = valide` (non dévalidé M2) et existence de la fenêtre ⇒ sinon `refuse / droit_invalide`.
4. **Sens** — `equipement.sens` compatible avec le sens du passage (un équipement `entree` refuse une sortie) ⇒ sinon `refuse / sens_interdit`.
5. **Marges** — `ResolveurMarges` (§4.2) : hors fenêtre effective ⇒ `refuse / hors_marge`.
6. **Anti-passback** — dernier passage validé du même support sur le périmètre (équipement, sinon espace, sinon sous-réseau) plus récent que `delai` résolu (§4.1) ⇒ `refuse / anti_passback`.
7. **Crédit (si `carte_quota`)** — `creditRestant = 0` ⇒ `refuse / credit_epuise` (+ proposition de recharge, §4.8) ; sinon **décompte atomique** `UPDATE acces_droit_acces SET credit_restant = credit_restant − 1 WHERE id = :id AND credit_restant > 0` — 0 ligne ⇒ course perdue ⇒ `refuse / credit_epuise`.
8. **FMI (si `sens = entree`)** — incrément atomique conditionnel de `JaugeFmi` (§1.5) : mode `blocage` et seuil atteint ⇒ **rollback du décompte crédit** (étape 7) et `refuse / seuil_fmi` ; mode `alerte` ⇒ incrément + incident. Si `sens = sortie` ⇒ décrément (min 0).
9. **Enregistrement** — création `Passage` (`resultat`, `codeMotif`, horodatage seconde, rattachements, `cleIdempotence`) **dans la même transaction** que les étapes 7-8 (atomicité crédit+jauge+journal). Append-only (§1.4).
10. **Effet matériel** — si `valide` : `PiloteAcces::ouvrir(equipement, ctx)`. Réponse `{resultat, codeMotif}` renvoyée au contrôleur.

> **Atomicité (CA-3)** — étapes 7-8-9 dans **une transaction** ; le décompte crédit et l'incrément jauge sont des **UPDATE conditionnels** (pas de read-modify-write), garantissant l'absence de double décompte et de dépassement de seuil sous concurrence (même technique que M2 §6). Un refus tardif (étape 8) **annule** le décompte de l'étape 7 (rollback transactionnel).

### 4.4 Comptage non nominatif (US-L3-04, RG-ACC-03 — CA-5)

`POST /acces/passages/non-nominatif` → `Passage` avec `support = null`, `droit = null`, `resultat = compte`, `motif` requis (accompagnant/bébé/exonéré), `codeMotif = non_nominatif`. **Incrémente `JaugeFmi.valeurCourante` et `cumulJour`** (présence physique réelle, décision actée piscine) **sans décompte de crédit**. Apparaît **distinctement** au journal et en supervision.

### 4.5 Stratégie FMI (US-L3-05, RG-ACC-04 — CA-6)

- **FMI ≠ cumul** — `JaugeFmi.valeurCourante` (présents = entrées − sorties) est **strictement distinct** de `cumulJour` (ne fait qu'augmenter). Deux compteurs exposés en supervision.
- **Entrée = +1, sortie = −1** — piloté par `Equipement.sens` ; décrément borné à 0.
- **Seuil : blocage ou alerte** — `modeSeuil` **paramétrable par espace** (décision actée) : `blocage` = refus des entrées au seuil (`codeMotif=seuil_fmi`) ; `alerte` = incident de supervision sans blocage.
- **Recalage à l'ouverture** — **DÉCISION appliquée (arbitrage point ouvert n°5)** : **remise à zéro** à l'ouverture de journée (`valeurCourante = 0`, `cumulJour = 0`, `dateReference = jour`), la présence physique repartant de 0. Service `RecalageFmiHandler` (déclenché à l'ouverture de site ; hook exposé). ⚠ **impact sécurité ERP à confirmer** (report de présence résiduelle ?) — Risque n°4.
- **Recalage à la synchro** — après rejeu (§4.6), `valeurCourante` est **recalé sur l'état réel** (entrées − sorties rejouées).
- **Hook piscine (L6)** — `preAlertePct` (pré-alerte à X %) et la règle « **une sortie = une entrée** » sont **portées comme paramètres/hook** ; l'application effective (verticale piscine) relève de **L6** — L3 laisse le point d'extension sans coder la règle (constitution §4 : pas de logique verticale en dur).

### 4.6 Hors-ligne & resynchronisation (US-L3-07/08, RG-ACC-05 — CA-8/9)

Réutilise le **pattern M2 §4** (idempotence + rejeu chronologique + file locale côté poste).

- **Validation locale hors-ligne** — le contrôleur (via ITBOX) valide en local avec sa **projection `DroitAcces` cachée** + sa **liste de révocation embarquée** : un support **révoqué embarqué est refusé même sans réseau** (étape 2 de §4.3, RG-ACC-07). Passages mémorisés localement avec `origineHorsLigne = true` + `cleIdempotence`.
- **Basculement online/offline** — **automatique** via absence de heartbeat (`Controleur.etat`, `dernierHeartbeat`) ; **signalé en supervision** sans dégrader l'affichage des autres (CA-7).
- **Rejeu chronologique par paquets** — `POST /acces/synchro` remonte un lot ordonné par `horodatage` ; `SynchroPassageHandler` rejoue **dans l'ordre horodaté** (décision actée), **par paquets** pour les gros lots (décision actée ; ⚠ volumétrie max non fixée — Risque n°6). Chaque passage est inséré **une seule fois** (`cleIdempotence` unique + `INSERT … ON CONFLICT DO NOTHING`) ⇒ **anti double-décompte / sans doublon** au journal (CA-12).
- **Recalage crédits & FMI** — à l'issue du rejeu, `creditRestant` et `JaugeFmi` sont **recalés sur l'état réel** (décision actée). La réconciliation crédit tourniquet ↔ compostages M1 suit la clé d'idempotence (§1.3, ⚠ Risque n°8).
- **Détection de conflits** — deux conflits **détectés et tracés** :
  - *Double décompte* → absorbé par idempotence (clé rejouée = no-op).
  - *Révocation postérieure* (support validé hors-ligne, révoqué entre-temps) → passage **conservé** (déjà advenu physiquement) mais **incident tracé** (mise en quarantaine `en_conflit`, remontée en supervision). ⚠ **conduite de résolution** (annulation a posteriori ? blocage à la prochaine venue ?) et **délai max de propagation** non tranchés (Risque n°5) — hypothèse retenue : **blocage à la prochaine venue** + incident, jamais d'annulation rétroactive du passage (parallèle M2 : opération scellée non supprimable).
- **Propagation révocation** — un blocage serveur (§4.7) **incrémente `Controleur.versionRevocation`** ; `PiloteAcces::pousserListeRevocation()` propage la liste à jour à la prochaine synchro de chaque contrôleur.

### 4.7 Support perdu/volé (US-L3-09, RG-ACC-07 — CA-10)

`POST /acces/supports/{id}/bloquer` → `Support.statut = bloque` **immédiat** (refus à tout passage **online**), création `DeclarationPerteVol` (motif/agent/horodatage tracés), ajout à la **liste de révocation** (nouvelle `version`) propagée à la prochaine synchro (refus **hors-ligne** aussi). **Réversible** par rôle habilité (`POST /acces/declarations/{id}/annuler`).

### 4.8 Carte épuisée (US-L3-10, RG-ACC-02 — CA-11)

`creditRestant = 0` ⇒ `refuse / credit_epuise` **sans décompte** ; la réponse porte une **proposition de recharge** (orientation caisse/borne/app, décision actée) ; **rechargement effectif = M2** (hors périmètre). Journalisé comme refus pour crédit insuffisant.

---

## 5. Sécurité & droits

- **Permissions requises (module `acces`)** — `acces.gerer` (config topologie/sous-réseaux, surensemble admin), `acces.lire` (journal/supervision lecture seule), `acces.superviser` (vue live, +1 non nominatif), `acces.appairer`, `acces.ouvrir_manuel`, `acces.controler` (mobile coupe-file), `acces.bloquer_support`, `acces.ingestion` (**acteur technique Système/ITBOX** — ingestion passages/synchro).
- **Voter** — **aucun voter nouveau** : réutilise `PermissionVoter` du socle (attribut `PERM`, sujet `"acces.action"`, RG-SOCLE-04). `acces.gerer` couvre les actions de config (résolution dans le Voter, non dupliquée). Mapping Permission/Role par **migration de données** (§6).
- **Acteur Système / ITBOX** — l'ingestion (`POST /acces/passages`, `/acces/synchro`) est appelée par le **matériel** (compte technique dédié) porteur de `acces.ingestion`, non une permission humaine. Le contrôle d'autorisation d'un passage est **métier** (§4.3), pas une permission socle.
- **Cadrage établissement** — `ContexteEtablissement` (en-tête `X-Etablissement`) ; l'extension Doctrine du socle filtre `EspaceAcces`/`Passage`/… sur les établissements affectés (RG-SOCLE-05 ; cas limite « agent sans affectation → aucun accès »).
- ⚠ **HYPOTHÈSE (point ouvert n°2)** — les **noms** de permissions dérivent du tableau Acteurs & droits selon le modèle socle, non littéraux dans les sources ; **découpage fin à figer avec M8**.

---

## 6. Migrations

- **Migration structurelle** `VersionL3_acces` : tables `acces_espace_acces`, `acces_controleur`, `acces_equipement`, `acces_support`, `acces_appairage`, `acces_droit_acces`, `acces_passage`, `acces_jauge_fmi`, `acces_liste_revocation`, `acces_declaration_perte_vol`, `acces_sous_reseau` + jointure `sous_reseau_espace`.
  - **Index/contraintes** : unique `Support.identifiant`, `Passage.cleIdempotence`, `(Controleur, version)` sur `ListeRevocation` ; **un seul appairage actif par support** via colonne générée `support_actif` + index unique partiel (cf. M2 §7) ; `OneToOne` unique `JaugeFmi.espace` ; checks `seuilFmi ≥ 0`, `creditRestant ≥ 0`, `valeurCourante ≥ 0`, `cumulJour ≥ 0`, marges `≥ 0`, `antiPassbackDelai > 0`. FK vers `etablissement`/`espace`/`utilisateur` (socle) — **suppose migrations socle L0 + M1 + M2 jouées d'abord** (dépendance d'ordre).
- **Migration de données** `VersionL3_permissions` : insère `Permission(module='acces', action ∈ {gerer, lire, superviser, appairer, ouvrir_manuel, controler, bloquer_support, ingestion})`.
- Rejouables, versionnées Doctrine ; jamais de `schema:update --force` (constitution §7).

---

## 7. Tests (PHPUnit + ApiTestCase, + simulateur matériel)

| Test | Type | Couvre |
|---|---|---|
| Topologie : équipement porte un `sens` + rattaché contrôleur→espace ; anti-passback espace **et** équipement ; incohérence (orphelin, sens manquant) → 422 | API | CA-1, US-L3-01 |
| Appairage : lien + type enregistrés ; support déjà appairé actif → refus explicite ; blacklisté → refus ; **un seul actif** par support | API | CA-2, US-L3-02 |
| Validation : accepté seulement si droit valide + marges + anti-passback ; **décompte crédit atomique** à l'acceptation ; décision horodatée/motivée ; réponse < 1 s | API + Unit (`ValidationPassageHandler`) + **SimulateurAccesAdapter** | CA-3, RG-ACC-01/02 |
| Anti-passback : re-scan même support avant délai résolu → refus (`anti_passback`) ; surcharge espace/équipement | Unit (`ResolveurAntiPassback`) + **simulateur** | CA-4, US-L3-01 |
| +1 non nominatif : FMI **et** fréquentation +1 **sans** crédit ; motif tracé ; distinct au journal/supervision | API | CA-5, RG-ACC-03 |
| FMI : présents (entrées−sorties) ≠ cumul ; seuil `blocage` refuse / `alerte` signale ; décrément à la sortie ; recalage (remise à zéro) à l'ouverture | API + Unit (`RecalageFmiHandler`) | CA-6, RG-ACC-04 |
| Supervision : jauge/flux/incidents live ; ouverture manuelle tracée (agent/motif/horodatage) ; incident signalé | API | CA-7, US-L3-06 |
| Hors-ligne : validation locale sur révocation embarquée ; révoqué embarqué refusé sans réseau ; bascule online/offline auto signalée | Unit + **SimulateurAccesAdapter** (mode offline) | CA-8, RG-ACC-05/07 |
| Resynchro : rejeu chronologique par paquets ; crédits + FMI recalés ; conflits (double décompte, révocation postérieure) détectés/tracés ; **sans doublon** (idempotence) | API + Unit (`SynchroPassageHandler`) | CA-9, RG-ACC-05 |
| Perte/vol : blocage serveur immédiat (refus online) ; révocation ajoutée + propagée ; déclaration tracée/réversible ; refus hors-ligne | API | CA-10, RG-ACC-07 |
| Carte épuisée : crédit 0 → refus « carte épuisée » + proposition recharge **sans décompte** ; journalisé refus crédit | API | CA-11, RG-ACC-02 |
| Journal : chaque passage (accepté/refusé/non nominatif/manuel) horodaté+rattaché ; filtrable (période/espace/équipement/type) + exportable ; **lecture seule** ; hors-ligne apparaît après synchro **sans doublon** | API | CA-12, US-L3-11 |
| Sous-réseau : fédération activée + droits éligibles → franchissement fédéré ; jauge/anti-passback du sous-réseau appliqués ; désactivée → refus inter-entités | API | CA-13, US-L3-12 |
| Source unique inaltérable : passage alimente M6/M7 ; PATCH/DELETE d'une entrée journal impossible (append-only, `preUpdate`/`preRemove`) | API + Unit | CA-14, RG-SOCLE-07 |
| Concurrence : deux entrées simultanées sur espace à `seuil−1` en `blocage` → une passe, l'autre `seuil_fmi` ; deux passages sur carte à 1 crédit → un décompte, l'autre `credit_epuise` | API (concurrence) | §4.3 (atomicité crédit/FMI) |
| Consommation dévalidation M2 : support dévalidé (`Avoir.supportInvalide`) → `DroitAcces.statutProjection=devalide` → refus | API | RG-M2-07 (frontière M2) |
| Cloisonnement : agent sans affectation sur l'étab de l'espace → 403/absent | API | RG-SOCLE-05 (socle réutilisé) |
| Ports matériel : le domaine ne référence aucun protocole ; swap Simulateur↔Itbox par config sans changer le métier | Unit (architecture) | §2 (ports & adaptateurs) |

---

## 8. Tâches (voir tasks-acces.md)

T1 enums → T2 topologie (Espace/Controleur/Equipement) + garde cohérence → T3 Support/Appairage + unicité → T4 DroitAcces (projection) + port projection → T5 **ports & adaptateurs matériel** (PiloteAcces + Simulateur) → T6 résolveurs (anti-passback, marges) → T7 `ValidationPassageHandler` (algo passage + atomicité crédit/FMI) → T8 FMI (jauge + recalage) → T9 non nominatif + ouverture manuelle → T10 perte/vol + liste révocation → T11 hors-ligne/synchro (rejeu idempotent) → T12 sous-réseau/fédération → T13 API + sérialisation + droits → T14 migrations + permissions → T15 tests (dont simulateur). (ordonnées, cf. fichier tasks.)

---

## 9. Risques / à valider

1. **⚠ PROTOCOLES MATÉRIEL iDTRONIC / ITBOX / SmartAccess (priorité haute, point ouvert n°1)** — contrat d'échange (OSDP vs API REST/temps réel, commande d'ouverture, remontée d'événement, heartbeat, **format liste de révocation embarquée**, latence < 1 s garantie) **non spécifié**. Design **ports & adaptateurs** (§2) pour ne pas bloquer ; **SimulateurAccesAdapter** débloque dev/tests. **À cadrer avec IT Cotation** (fournisseur unique) avant intégration matérielle réelle ; se traduit par une implémentation d'adaptateur, sans toucher au domaine.
2. **⚠ Combinaison des marges droit (M1) ∩ équipement (point ouvert n°3)** — **intersection retenue** (§4.2) ; règle exacte **à arbitrer avec M1**.
3. **⚠ Anti-passback : niveau de surcharge (point ouvert n°4)** — **hiérarchie défaut→espace→équipement retenue** (§4.1) ; **à confirmer**.
4. **⚠ Recalage FMI à l'ouverture (point ouvert n°5)** — **remise à zéro retenue** (§4.5) ; report de présence résiduelle ? **impact sécurité ERP à confirmer**.
5. **⚠ Résolution des conflits de resynchro (point ouvert n°6)** — hypothèse : passage hors-ligne conservé + incident + **blocage à la prochaine venue** (jamais d'annulation rétroactive) ; **délai max de propagation** d'une révocation **non tranché**.
6. **⚠ Volumétrie max d'un lot différé** — rejeu par paquets (décision actée) ; **taille de paquet à fixer** sous charge.
7. **⚠ FMI de sous-réseau vs FMI d'espace (point ouvert n°7)** — `SousReseau.seuilFmiAgrege` porté en **plus** de la FMI d'espace ; articulation (agrégation, partage anti-passback inter-entités) **à cadrer avec M1 + sécurité ERP**.
8. **⚠ Réconciliation crédit tourniquet ↔ compostages M1 (point ouvert n°9)** — `DroitAcces` = projection avec cache crédit ; source de vérité M1/M2 ; mécanique de réconciliation à la synchro **à préciser à l'intégration L4** (parallèle M2 §4).
9. **⚠ Échec d'appairage (point ouvert n°8)** — conduite (ré-essai/support de secours/ticket seul) **non spécifiée** ; **à préciser conjointement M2/L3** (hérité L2 §4.6).
10. **⚠ Noms des permissions `acces.*` (point ouvert n°2)** — dérivés du modèle socle, non littéraux ; **à figer avec M8**.
11. **Frontière API vs poste/ITBOX** — file locale physique, bascule réseau et stockage embarqué relèvent du contrôleur/ITBOX (hors périmètre API strict) ; l'API expose ingestion, synchro et état. Frontière à confirmer à l'intégration matérielle.
