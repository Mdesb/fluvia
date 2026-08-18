\
# Spec — Paramétrage global (`App\Parametrage`)

- **Lot / module :** transverse (dépend de L0, consommé par M8/L7 et par tous les modules verticaux)
- **Stories couvertes :** US-PARAM-01 à US-PARAM-08 (⚠ **HORS backlog** — `backlog.html` ne contient
  aucune story dédiée au catalogue de paramètres transverses ; ces US sont proposées par cette spec et
  doivent être ajoutées au backlog si le chantier est repris)
- **Règles de gestion :** RG-PARAM-01 à RG-PARAM-12 (proposées par cette spec, alignées sur
  `cahier-detaille.html` §M8-04 « Paramètres généraux & matériel » et l'objet **Paramètre** de
  §M8-4 « Objets de données » : *« Clé, valeur, portée, moyen de paiement, seuil — rattaché à une
  Entité ; hérité par niveau »*) ; réutilise RG-SOCLE-01, RG-SOCLE-05, RG-SOCLE-07, RG-M8-04, RG-M8-09
  (`cahier-detaille.html`) et la constitution §4.2 (multi-entités), §4.4 (config, pas de `if` codés en dur).
- **Statut :** brouillon — ⚠ le code du module existe **partiellement** (voir §9 État de l'implémentation) :
  seules les entités Doctrine (`DefinitionParametre`, `ValeurParametre`, `HistoriqueParametre`) et les
  énumérés (`TypeParametre`, `NiveauParametre`) sont écrits ; la couche service (résolution, écriture),
  les processors API et la migration de schéma/données restent à faire. Cette spec cadre ce qui doit
  être terminé.

## 1. Objectif
Offrir un **catalogue central** de réglages transverses simples (clé → valeur typée, ex. libellés,
seuils, options d'affichage, message d'accueil borne) définissables au niveau **Groupe** (le plus
général — « global/éditeur ») et **surchargeables** aux niveaux **Région** puis **Établissement** (le
plus fin), avec **héritage en cascade** : la valeur effective est celle du niveau le plus fin où elle a
été définie, sinon elle remonte jusqu'au défaut du catalogue. But : arrêter l'éparpriment des réglages
et donner un point d'entrée unique, sans dupliquer les configurations déjà portées par les modules
métier.

## 2. Périmètre

### 2.1 Inclus
- Le **catalogue** des paramètres transverses paramétrables (`DefinitionParametre`) : clé, section
  d'affichage, libellé, aide, type, valeur par défaut, bornes/options, niveau minimum de surcharge,
  sensibilité, ordre d'affichage, actif/inactif.
- La **valeur** donnée à un paramètre à une portée précise de la hiérarchie (`ValeurParametre`) :
  Groupe, Région **ou** Établissement (jamais deux à la fois).
- La **résolution de la valeur effective** d'une clé pour un établissement donné : cascade
  Établissement → Région → Groupe → valeur par défaut du catalogue.
- L'**historisation append-only** de chaque écriture/retrait de surcharge (`HistoriqueParametre`) :
  auteur, horodatage, ancienne/nouvelle valeur, motif optionnel.
- La **validation par type** (booléen, entier, décimal, texte, texte long, couleur, durée, liste fermée,
  JSON) et la **normalisation** de la valeur stockée.
- Les **droits d'écriture par niveau** (`parametrage.definir_groupe` / `definir_region` /
  `definir_etablissement`) et le masquage des paramètres **sensibles**.

### 2.2 Exclu — cartographie anti-redondance (ce qui existe déjà et n'est PAS remplacé)
Le risque principal de ce module est de dupliquer un paramétrage qui vit déjà, correctement, ailleurs.
Le Paramétrage global **référence** ces modules, ne les **réabsorbe pas** :

| Déjà existant (reste tel quel) | Où | Pourquoi ce n'est PAS dans `App\Parametrage` |
|---|---|---|
| **Capacités/fonctionnalités activables par établissement** (feature flags, on/off + JSON libre, preset par métier) | `App\Fonctionnalite` (`FonctionnaliteEtablissement`, `Fonctionnalites`, `CatalogueCapacites`, `/etablissements/{id}/fonctionnalites`) | Objet différent : « quelle capacité est activée » (booléen + config libre par établissement, **sans héritage Groupe/Région**), pas « quelle est la valeur de ce réglage ». Le Paramétrage global ne touche pas à `FonctionnaliteEtablissement` ; il pourrait à terme s'appuyer sur `Fonctionnalites::estActive()` comme garde, mais ce n'est pas son rôle de le remplacer. |
| **Référentiels M1** (TVA, catégories, types de tarif, saisons, types produit…) | `App\Offre` (`TypeTarif`, `Categorie`, `Saison`, grilles tarifaires) | Ce sont des **entités métier structurées** avec leurs propres règles de gestion (RG-M1-*), pas des couples clé/valeur génériques. Le Paramétrage global ne référence même pas ces objets. |
| **Config caisse / point de vente / moyens de paiement** (M2) | `App\Caisse\Entity\PointDeVente` (imprimante, TPE, favoris, `seuilImpression`, `seuilAlerteRetrait`, `moyensAutorises`) | Configuration **structurée et rattachée à un point de vente précis**, pas un réglage transverse simple. Reste dans M2. |
| **Config créancier SEPA** (ICS, IBAN, BIC, mentions régie/DSP) | `App\Sepa\Entity\ConfigCreancierSepa` | Données bancaires sensibles avec chiffrement dédié (tokenisation + coffre libsodium), un objet par établissement. Le Paramétrage global ne gère **que les préfixes de nommage** (`MndtId`/`EndToEndId`/`InstrId`, formatage pur repris de l'écran AwoO « Document de Mandat SEPA ») — jamais l'identité bancaire. |
| **Mentions légales émetteur de facture, conditions de règlement, pénalités, Chorus Pro** | `App\Facturation\Entity\ParametreFacturationEtablissement` | Déjà couvert par une entité dédiée et structurée (`mentionsLegalesEmetteur`, `conditionsReglementDefaut`, `tauxPenaliteRetard`…). Le Paramétrage global ne gère **que les libellés/titres personnalisables des types de documents** (Devis, Bon de commande, Facture d'acompte…), pas les mentions légales elles-mêmes. |
| **Config stock par établissement** (méthode de valorisation par défaut, stock négatif, seuils d'écart) | `App\Stock\Entity\ParametrageStock` | Config métier structurée propre au module Stock ; illustre le même patron (une entité dédiée par établissement) que le Paramétrage global ne vient pas remplacer. |
| **Config verticale Padel / Musée** (`ParametragePadel`, `ParametreMuseeEtablissement`) | `App\Padel`, `App\Musee` | Chaque verticale garde ses réglages spécifiques et typés dans son propre module (constitution §5 : « la verticale ne réinvente rien, elle paramètre le socle »). Le Paramétrage global ne se substitue pas à ces entités. |
| **Droits, rôles, permissions** (M8/L7) | `App\Securite` (`Permission`, `Role`, `Affectation`) | Le Paramétrage global **consomme** le même modèle `module × action` (`parametrage.lire`, `parametrage.administrer`…) mais ne gère ni rôles ni affectations. |
| **Journal d'audit générique** | `App\Audit\Entity\EntreeAudit` / `JournalAudit` | Le Paramétrage global a son **propre** historique dénormalisé (`HistoriqueParametre`) car il doit rester lisible même si la définition du catalogue change (clé et portée recopiées) — voir §4, RG-PARAM-07. Il ne remplace pas le journal d'audit générique ; une entrée `EntreeAudit` peut en complément tracer l'action au niveau socle si besoin (⚠ HYPOTHÈSE, non trigée par cette spec). |

**Ce que le Paramétrage global ajoute réellement** : un mécanisme **unique et générique**
(catalogue + valeur par portée + cascade d'héritage + historique) pour les réglages transverses qui
n'ont **pas** de home métier naturel — repris de l'écran « Paramètres → AwoO » du Club Manager AWOO
(sections *Général*, *Facturation* [titres de documents uniquement], *MarketPlace* [branding, mobile
obligatoire], *Document de Mandat SEPA* [préfixes uniquement], *Modules → Billetterie* [QR codes,
marges avance/retard], *Promotions* [sélection auto], *Annulation par le client*, *Partage du fichier
client*). Toute nouvelle demande de réglage doit d'abord vérifier qu'elle n'a pas déjà un home dans le
tableau ci-dessus avant d'être ajoutée au catalogue `DefinitionParametre`.

### 2.3 Exclu (pour l'instant, autres raisons)
- Interface d'administration (front) — cette spec couvre le comportement observable de l'API/service.
- Import/export en masse du catalogue.
- Historique des changements de **catalogue** (`DefinitionParametre`) lui-même (seules les valeurs sont
  historisées) — ⚠ HYPOTHÈSE : le catalogue étant amorcé par migration et rarement modifié, son propre
  historique n'est pas jugé prioritaire ; à revoir si l'écriture de catalogue est ouverte à des non-devs.

## 3. Acteurs & droits
Module de permission : `parametrage`.

| Acteur | Peut | Permission (module × action) |
|---|---|---|
| Administrateur groupe (admin global) | Lire tout le catalogue et toutes les valeurs (y compris sensibles) ; gérer le catalogue (`DefinitionParametre`) ; définir/retirer une surcharge à **n'importe quel** niveau (Groupe, Région, Établissement) | `parametrage.lire`, `parametrage.administrer`, `parametrage.definir_groupe`, `parametrage.definir_region`, `parametrage.definir_etablissement` |
| Administrateur d'établissement | Lire le catalogue et les valeurs résolues **de son périmètre** (paramètres sensibles masqués) ; définir/retirer une surcharge **au niveau Établissement uniquement**, dans son périmètre (RG-M8-09) | `parametrage.lire`, `parametrage.definir_etablissement` |
| Utilisateur métier (agent) | Lire les valeurs résolues utiles à son écran (via les modules consommateurs, pas nécessairement l'API de catalogue) | selon permission du module consommateur |
| Responsable sécurité | Consulter l'historique des changements (`/parametres/historique`) | `parametrage.lire` |

- Un administrateur d'établissement ne peut jamais écrire au niveau Groupe/Région, ni sur un paramètre
  marqué `sensible = true` (accès en lecture masqué) ou `surchargeable = false` (figé au Groupe) — voir
  RG-PARAM-08/09.
- Cloisonnement RG-SOCLE-05 : les opérations au niveau Établissement portent l'établissement ciblé
  explicitement (chemin ou filtre) et sont vérifiées contre le périmètre affecté à l'utilisateur, pas
  seulement contre l'établissement actif transmis par `X-Etablissement`.

## 4. Comportements & règles

- **RG-PARAM-01** — Le **catalogue** (`DefinitionParametre`) décrit *ce qui* est paramétrable, jamais une
  valeur retenue par un client. Il est amorcé par migration de données et n'est modifiable qu'avec
  `parametrage.administrer`. Une clé est un slug pointé unique en snake_case (ex.
  `billetterie.marge_avance_minutes`).
- **RG-PARAM-02** — Chaque paramètre a un **type** strict (Booléen, Entier, Décimal, Texte, Texte long,
  Couleur, Durée, Liste fermée, JSON) qui gouverne la validation en écriture, la conversion en lecture
  et la normalisation de la forme stockée (ex. couleur toujours `#RRGGBB` majuscule, booléen toujours
  `1`/`0`).
- **RG-PARAM-03** — La **portée** d'une valeur (`ValeurParametre`) est **exactement une** des trois :
  Groupe, Région, Établissement — jamais deux, jamais aucune. C'est la même hiérarchie que RG-SOCLE-01
  (Groupe › Région › Établissement).
- **RG-PARAM-04** — Le catalogue fixe, par paramètre, le **niveau minimum** auquel il peut être redéfini
  (`niveauMinimum` : Groupe / Région / Établissement) et s'il est `surchargeable` du tout. Un paramètre
  non surchargeable (ex. réglage de réseau comme le partage du fichier client) ne se définit qu'au
  niveau Groupe.
- **RG-PARAM-05** — **Résolution de la valeur effective** d'une clé pour un établissement donné : on
  cherche une `ValeurParametre` au niveau Établissement, sinon au niveau Région (celle de
  l'établissement), sinon au niveau Groupe, sinon on retombe sur `valeurDefaut` du catalogue.
  Interroger une clé absente du catalogue lève une erreur explicite (`CleParametreInconnueException`) —
  **jamais** de valeur silencieuse par défaut pour une clé inconnue.
- **RG-PARAM-06** — Écrire une surcharge = upsert de la ligne `ValeurParametre` de la portée ciblée,
  **et** création d'une entrée `HistoriqueParametre` (ancienne valeur, nouvelle valeur, auteur,
  horodatage, motif optionnel) dans la **même transaction**. L'historique est dénormalisé (clé et
  portée recopiées) pour rester lisible même si le catalogue évolue ensuite.
- **RG-PARAM-07** — Retirer une surcharge = suppression de la ligne `ValeurParametre` de la portée
  ciblée, avec une entrée d'historique dont `nouvelleValeur = null` (signifie « retour à l'hérité »). La
  clé retombe alors sur le niveau parent (ou le défaut du catalogue) au prochain calcul.
- **RG-PARAM-08** — Le droit d'écriture est vérifié **par niveau** :
  `parametrage.definir_groupe`/`definir_region`/`definir_etablissement`
  (`NiveauParametre::permissionEcriture()`). Un administrateur d'établissement n'obtient jamais
  `definir_groupe`/`definir_region` (RG-M8-09).
- **RG-PARAM-09** — Un paramètre marqué `sensible = true` est **masqué en lecture** (catalogue et
  valeurs) pour qui n'a pas `parametrage.administrer`.
- **RG-PARAM-10** — L'**historique** est en écriture seule (append-only) : aucune opération de
  modification ni suppression n'est exposée par l'API, dans le même esprit que le journal d'audit
  générique (RG-SOCLE-07).
- **RG-PARAM-11** — Un `DefinitionParametre` déjà utilisé (au moins une `ValeurParametre` existante) ne
  peut pas être supprimé, seulement désactivé (`actif = false`) — même règle que les référentiels M8
  (« un référentiel ou moyen de paiement déjà utilisé ne peut être supprimé mais seulement désactivé »,
  cahier-detaille.html M8-04).
- **RG-PARAM-12** — L'unicité d'une portée par paramètre (un seul Groupe **ou** une seule Région **ou**
  un seul Établissement par `DefinitionParametre`) est doublement garantie : validation applicative
  (`Assert\Callback` + `UniqueEntity(ignoreNull: false)`) **et** logique d'upsert côté service
  d'écriture — ⚠ MariaDB traite deux `NULL` comme distincts dans un index `UNIQUE`, l'index composite
  seul ne suffit donc pas (voir §7 cas limites).
- ⚠ HYPOTHÈSE — La cascade de résolution s'arrête à la hiérarchie standard (Groupe/Région/Établissement)
  et ne descend pas au niveau Espace : aucun besoin identifié de paramètre transverse par Espace à ce
  stade (les réglages par Espace existent déjà, structurés, dans les modules verticaux — ex. seuil FMI
  par espace en Accès/Piscine). À confirmer si un besoin apparaît.

## 5. Objets de données

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| **DefinitionParametre** (catalogue) | `id` | UUID | PK | |
| | `cle` | string(120) | unique, regex slug pointé snake_case | ex. `billetterie.marge_avance_minutes` |
| | `section` | string(80) | requis | section d'affichage reprise AwoO (général, facturation, marketplace, sepa, billetterie…) |
| | `libelle` | string(180) | requis | |
| | `description` | text | optionnel | texte d'aide |
| | `type` | enum `TypeParametre` | requis | booleen, entier, decimal, texte, texte_long, couleur, duree, liste, json |
| | `valeurDefaut` | text (stocké en texte) | optionnel | convertie selon `type` en lecture |
| | `optionsListe` | json (liste de strings) | requis si `type = liste` | valeurs admissibles |
| | `minimum` / `maximum` | decimal(18,6) | optionnels | bornes pour types numériques (`estNumerique()`) |
| | `unite` | string(16) | optionnel | ex. « min » |
| | `niveauMinimum` | enum `NiveauParametre` | défaut `etablissement` | niveau le plus fin où le paramètre peut être redéfini |
| | `surchargeable` | bool | défaut `true` | `false` = figé au Groupe uniquement |
| | `sensible` | bool | défaut `false` | masqué en lecture sans `parametrage.administrer` |
| | `ordre` | smallint | défaut `0` | tri d'affichage dans la section |
| | `actif` | bool | défaut `true` | désactivation au lieu de suppression (RG-PARAM-11) |
| **ValeurParametre** (surcharge à une portée) | `id` | UUID | PK | |
| | `definition` | FK → DefinitionParametre | requis | |
| | `groupe` / `region` / `etablissement` | FK nullable (×3) | **exactement une** renseignée | RG-PARAM-03 |
| | `valeur` | text | requis | forme canonique normalisée (`TypeParametre::normaliser()`) |
| | `auteur` | FK → Utilisateur | optionnel | |
| | `modifieLe` | datetime immutable | requis | |
| | `commentaire` | string(255) | optionnel | |
| | contrainte | unique(`definition`, `groupe`, `region`, `etablissement`) | — | doublée d'une validation applicative (RG-PARAM-12) |
| **HistoriqueParametre** (append-only) | `id` | UUID | PK | |
| | `definition` | FK → DefinitionParametre | requis | |
| | `cle` | string(120) | dénormalisée | lisible même si le catalogue change |
| | `niveau` | enum `NiveauParametre` | requis | |
| | `groupe` / `region` / `etablissement` | FK nullable (×3) | cohérent avec `niveau` | |
| | `ancienneValeur` / `nouvelleValeur` | text nullable | — | `nouvelleValeur = null` ⇒ retrait de surcharge (RG-PARAM-07) |
| | `auteur` | FK → Utilisateur | optionnel | |
| | `horodatage` | datetime immutable | requis | |
| | `motif` | string(255) | optionnel | |
| **ResolutionParametre** (DTO de lecture, non persisté) | `cle` | string | — | |
| | `valeurTypee` | mixed (selon `type`) | — | résultat de la cascade RG-PARAM-05 |
| | `niveau` | enum `NiveauParametre`\|null | — | `null` = valeur par défaut du catalogue |
| | `origine` | string | — | `groupe`\|`region`\|`etablissement`\|`defaut` (`NiveauParametre::ORIGINE_DEFAUT`) |

Réutilise sans les dupliquer : `App\Organisation\Entity\{Groupe,Region,Etablissement}`,
`App\Securite\Entity\Utilisateur`, le modèle de permission `App\Securite\Entity\Permission`
(`module × action`).

## 6. Critères d'acceptation

- **US-PARAM-01 — Consulter le catalogue.**
  **CA-1** — *Étant donné* un utilisateur avec `parametrage.lire`, *quand* il liste
  `GET /parametres/definitions`, *alors* il reçoit les paramètres actifs, triés par section/ordre/clé,
  avec les paramètres `sensible = true` **exclus** s'il n'a pas `parametrage.administrer`.

- **US-PARAM-02 — Résoudre la valeur effective (héritage).**
  **CA-2** — *Étant donné* un paramètre `billetterie.marge_avance_minutes` défini uniquement au niveau
  Groupe (valeur 15), *quand* on résout sa valeur effective pour un établissement de ce groupe sans
  surcharge propre, *alors* la valeur retournée est 15, avec `origine = groupe`.

- **US-PARAM-03 — Surcharger au niveau établissement.**
  **CA-3** — *Étant donné* le même paramètre hérité à 15, *quand* un administrateur d'établissement
  définit une surcharge à 30 pour son établissement (`parametrage.definir_etablissement`), *alors* la
  valeur effective pour cet établissement devient 30 (`origine = etablissement`), celle des autres
  établissements du même groupe reste 15, et une entrée `HistoriqueParametre` est créée
  (`ancienneValeur = null` car première surcharge à ce niveau, `nouvelleValeur = "30"`).

- **US-PARAM-04 — Revenir à la valeur héritée.**
  **CA-4** — *Étant donné* la surcharge établissement à 30 du CA-3, *quand* elle est retirée
  (`DELETE /parametres/valeurs/{id}`), *alors* la ligne `ValeurParametre` est supprimée, une entrée
  d'historique est créée avec `nouvelleValeur = null`, et la valeur effective retombe à 15 (héritée du
  Groupe).

- **US-PARAM-05 — Résolution en cascade à trois niveaux.**
  **CA-5** — *Étant donné* un paramètre défini à la fois au Groupe (A), à la Région de l'établissement
  (B) et non défini à l'Établissement, *quand* on résout sa valeur effective pour cet établissement,
  *alors* la valeur retournée est B (`origine = region`), la valeur A du Groupe étant masquée par la
  surcharge plus fine.

- **US-PARAM-06 — Cloisonnement des droits d'écriture par niveau.**
  **CA-6** — *Étant donné* un administrateur d'établissement (sans `parametrage.definir_groupe`),
  *quand* il tente de définir une valeur au niveau Groupe, *alors* la requête est refusée (403) et
  aucune ligne n'est créée ni historisée.

- **US-PARAM-07 — Validation stricte par type.**
  **CA-7** — *Étant donné* un paramètre de type Entier avec `minimum = 0` et `maximum = 120`, *quand* on
  tente d'y écrire la valeur `"abc"` ou `"999"`, *alors* l'écriture est refusée (422) avec un message
  explicite, et aucune `ValeurParametre` ni `HistoriqueParametre` n'est créée.

- **US-PARAM-08 — Paramètre sensible masqué.**
  **CA-8** — *Étant donné* un paramètre `sensible = true`, *quand* un utilisateur sans
  `parametrage.administrer` liste le catalogue ou consulte ses valeurs, *alors* ce paramètre
  n'apparaît pas dans la réponse (ni sa définition, ni ses valeurs, ni son historique).

- **US-PARAM-09 — Clé inconnue.**
  **CA-9** — *Étant donné* aucune `DefinitionParametre` pour la clé `xxx.inexistante`, *quand* un
  module consommateur demande la résolution de cette clé, *alors* une erreur explicite est levée
  (`CleParametreInconnueException`) — pas de valeur par défaut silencieuse.

- **US-PARAM-10 — Historique consultable, jamais modifiable.**
  **CA-10** — *Étant donné* l'historique produit par CA-3 et CA-4, *quand* un utilisateur avec
  `parametrage.lire` consulte `GET /parametres/historique?cle=billetterie.marge_avance_minutes`,
  *alors* il voit les deux entrées, triées par `horodatage` décroissant ; aucune route API ne permet de
  les modifier ou de les supprimer.

## 7. Cas limites
- **Paramètre non défini à aucun niveau et sans `valeurDefaut` au catalogue** — la conversion renvoie la
  valeur neutre du type (`false`, `0`, `0.0`, `null`…) plutôt qu'une erreur. ⚠ HYPOTHÈSE : ce choix
  suppose que le catalogue est toujours amorcé avec un `valeurDefaut` explicite en pratique ; le
  comportement « valeur neutre silencieuse » est un filet de sécurité, pas la voie nominale — à
  documenter clairement pour qui amorce le catalogue.
- **Type invalide en écriture** (ex. lettre pour un Entier, JSON malformé, couleur hors format) — rejeté
  à la validation (RG-PARAM-02), aucune écriture partielle.
- **Surcharge puis suppression** — voir CA-4 : la suppression de la ligne `ValeurParametre` ne supprime
  jamais rétroactivement l'historique déjà produit ; l'historique reste la seule trace que la
  surcharge a existé.
- **Tentative de surcharge à un niveau plus fin que `niveauMinimum`** (ex. paramètre figé au Groupe,
  tentative de définition à l'Établissement) — refusée, quel que soit le niveau de droit de
  l'utilisateur (`DefinitionParametre::accepteNiveau()`).
- **Deux `NULL` distincts dans l'index unique MariaDB** — l'index composite `(definition, groupe,
  region, etablissement)` ne suffit pas à lui seul à empêcher deux lignes pour la même portée
  établissement (deux lignes avec `groupe=NULL, region=NULL, etablissement=X` ne violent pas l'index
  MariaDB). La garantie réelle vient de la validation applicative `UniqueEntity(ignoreNull: false)`
  **et** du service d'écriture qui doit chercher la ligne existante et la mettre à jour plutôt que d'en
  créer une seconde (RG-PARAM-12) — point d'attention pour l'implémentation du service `EcritureParametre`.
- **Écritures concurrentes sur la même portée** (deux administrateurs modifient la même clé au même
  niveau simultanément) — ⚠ HYPOTHÈSE : la dernière écriture gagne (dernier `flush` committé),
  l'historique conserve les deux entrées et permet de reconstituer la séquence ; pas de verrou optimiste
  explicite prévu à ce stade — à confirmer si le besoin de détection de conflit apparaît.
- **`DefinitionParametre` désactivé (`actif = false`) mais valeurs existantes** — les `ValeurParametre`
  restent en base (RG-PARAM-11) mais le paramètre n'apparaît plus dans le catalogue actif ; la
  résolution d'une clé désactivée doit être traitée comme la résolution d'une clé encore valide côté
  service (les modules qui la consomment encore continuent de fonctionner) — ⚠ HYPOTHÈSE à trancher à
  l'implémentation du résolveur : bloquer ou tolérer la lecture d'une clé désactivée.
- **Établissement sans Région ou Groupe cohérent** — l'intégrité référentielle de la hiérarchie
  (RG-SOCLE-01, FK non nulles sur `Etablissement.region` et `Region.groupe`) garantit qu'un
  établissement remonte toujours jusqu'à un Groupe ; la cascade de résolution n'a donc pas de cas
  « orphelin » à gérer en pratique.

## 8. Dépendances
- Dépend de **L0 socle** : hiérarchie `Groupe/Région/Établissement` (`App\Organisation`), modèle de
  permission `module × action` et `Affectation` (`App\Securite`), `ContexteEtablissement`
  (résolution de l'établissement actif via `X-Etablissement`), conventions d'audit (`App\Audit`).
- Consommé par **M8/L7 back-office** (écran d'administration des paramètres, cf. `cahier-detaille.html`
  M8-04) — cette spec ne couvre pas l'UI.
- Référencé sans duplication par les modules cités en §2.2 (Fonctionnalite, Offre, Caisse, Sepa,
  Facturation, Stock, verticales Padel/Musée).

## 9. État de l'implémentation (constat, pour qui reprend ce chantier)
- **Écrit** : `App\Parametrage\Entity\{DefinitionParametre,ValeurParametre,HistoriqueParametre}`,
  `App\Parametrage\Enum\{TypeParametre,NiveauParametre}`,
  `App\Parametrage\Exception\CleParametreInconnueException`. `HistoriqueParametre` et
  `DefinitionParametre` sont déjà exposés en API (`/parametres/historique`, `/parametres/definitions`).
- **Restant à écrire** (chantier interrompu, cf. commentaire dans `api_platform.yaml` et dans
  `ValeurParametre`) :
  - Service `ResolveurParametre` (cascade RG-PARAM-05, US-PARAM-02/05) et `EcritureParametre`
    (upsert + historisation transactionnelle, RG-PARAM-06/07/12).
  - DTO `ApiResource\SaisieValeurParametre` + `State\DefinirValeurProcessor` /
    `State\RetirerValeurProcessor`, puis réactivation du bloc `#[ApiResource]` sur `ValeurParametre`
    (actuellement retiré, entité mappée par Doctrine mais non exposée).
  - Migration de schéma (`parametrage_definition`, `parametrage_valeur`, `parametrage_historique`) et
    migration de données amorçant le catalogue (référencée en commentaire comme
    `Version20260818091410`, non trouvée dans `app/migrations/` à ce jour).
  - Migration de données ajoutant les permissions `parametrage.{lire,administrer,definir_groupe,
    definir_region,definir_etablissement}` dans `sec_permission` (même patron que
    `Version20260818090410` pour le module Avis).
  - Tests PHPUnit (aucun test `app/tests/Parametrage/` trouvé à ce jour).

## Points ouverts
- Confirmer la liste exacte des premières clés à amorcer dans le catalogue (reprise des sections AwoO
  citées en §2.2) — à valider avec le client avant d'écrire la migration de données.
- Trancher les deux ⚠ HYPOTHÈSE du §7 (comportement sur clé désactivée, gestion de la concurrence
  d'écriture) avant de figer `EcritureParametre`.
- Ajouter les US-PARAM-01 à 10 au backlog si ce chantier est priorisé (actuellement hors backlog).
