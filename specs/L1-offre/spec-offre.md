# Spec — Offre & Tarification (`M1` / lot `L1`)

- **Lot / module :** L1 · M1 Offre & Tarification
- **Stories couvertes :** US-L1-01 à US-L1-11
- **Règles de gestion :** RG-M1-01 à RG-M1-13
- **Statut :** brouillon

## 1. Objectif
Permettre au métier (gestionnaire d'offre, comptable) de créer et faire évoluer **tout ce qui est vendable et son prix** — produits, grilles tarifaires, formules d'abonnement, cartes multi-entrées, promotions, catégories — sans intervention d'un développeur, via un modèle de produit générique unique couvrant les quatre métiers.

## 2. Périmètre
- **Inclus :**
  - Catalogue de produits de tous types (liste, recherche, filtres, actions de masse) — US-L1-01.
  - Fiche produit pilotée par le type : onglets/champs affichés ou masqués selon le type — US-L1-02, RG-M1-02.
  - Grille tarifaire (produit × type de tarif × saison) et quotient familial — US-L1-03/04, RG-M1-01.
  - Formule d'abonnement à droits d'accès + services inclus à quota — US-L1-05, RG-M1-03/12.
  - Carte multi-entrées à bonus (stock de compostages) — US-L1-06, RG-M1-04/13.
  - Référentiels centraux : types de tarifs, saisons, promotions — US-L1-07, RG-M1-06/07.
  - Plan de catégories multi-axes (marketing / comptable / rayon) — US-L1-08, RG-M1-05.
  - Cycle de vie brouillon → publié → archivé avec conditions de publication — US-L1-09, RG-M1-09.
  - Conversion assistée de type et duplication — US-L1-10/11, RG-M1-11.
- **Exclu (pour l'instant), pris en charge par d'autres modules que M1 *référence* seulement :**
  - L'acte de vente / encaissement → M2 (lot L2).
  - La réservation / consommation effective des séances et des quotas → M5.
  - La reconnaissance comptable (PCA), les taux de TVA appliqués, le plan de comptes → M6 ; M1 se contente de porter la valeur et la règle (RG-M1-08).
  - Le contrôle d'accès physique (compostage réel au tourniquet) → module Accès (lot L3) ; M1 ne fait que **paramétrer** le nombre de compostages et les marges.
  - L'UI (front) elle-même ; l'infrastructure d'authentification, de droits et d'audit → socle L0 (réutilisée, non redéfinie ici).

## 3. Acteurs & droits
Les permissions réutilisent le modèle du socle (`RG-SOCLE-02/03/04/05`) : couple `module × action` sur le module **`offre`**, portées par l'établissement actif. L'UI **masque** ce qui n'est pas autorisé (RG-M8, `RG-SOCLE-04`).

| Acteur | Peut | Permission (module × action) |
|---|---|---|
| Gestionnaire d'offre | Créer / éditer / dupliquer / publier / dépublier / archiver des produits ; gérer les référentiels tarifaires de son périmètre | `offre × creer`, `offre × modifier`, `offre × publier`, `offre × archiver`, `offre × lire` |
| Administrateur | Tout ce qui précède + gérer les axes de catégories, la visibilité réseau, déléguer les droits | `offre × gerer` (surensemble), `securite × gerer` (délégation, socle) |
| Comptable | Consulter l'offre ; définir compte comptable / TVA / règle PCA d'un produit (onglet Compta) ; gérer/rattacher les catégories (dont l'axe comptable) | `offre × lire`, `offre × modifier_compta` ⚠ HYPOTHÈSE (permission fine dédiée à l'onglet Compta, à confirmer avec M6/M8) |
| Agent d'accueil / caisse | Consulter l'offre (lecture), voir prix & stock ; ne peut pas créer/modifier | `offre × lire` |

- ⚠ HYPOTHÈSE : le découpage fin `offre × modifier_compta` (comptable) vs `offre × modifier` (gestionnaire) sur la même fiche produit n'est pas explicitement nommé dans les sources ; le cahier décrit la séparation des responsabilités (« le comptable ne modifie pas la partie commerciale/tarifaire ; le gestionnaire ne modifie pas le plan de comptes »). Nom de permission à arbitrer avec M8.

## 4. Comportements & règles
Chaque comportement trace une RG-M1 (source : `cahier-detaille.html`, panel `p-m1`) et/ou une US-L1 (source : `backlog.html`, panel `p-l1`).

- **RG-M1-01** — Un **prix** est déterminé par le triplet **produit × type de tarif × saison** (+ tranche de quotient familial éventuelle). Aucun prix « libre » hors grille, sauf droit dédié. Une case de grille vide vaut « **non commercialisé** » (et non « gratuit ») (US-L1-03).
- **RG-M1-02** — Le **type de produit** détermine les facettes (stock, consommateur, billet, carnet…) et donc les **onglets/champs visibles**. Un onglet masqué n'expose ni ne conserve de saisie orpheline (US-L1-02).
- **RG-M1-03** — Une **formule** d'abonnement porte des **droits d'accès** ET des **services inclus à quota**, décomptés à la consommation (par M5) (US-L1-05).
- **RG-M1-04** — Une **carte multi-entrées** est un **stock de N compostages** ; une promo « 10=12 » ajoute des compostages bonus, assortis d'une date de validité (US-L1-06).
- **RG-M1-05** — Tout produit porte une valeur sur l'**axe comptable** (obligatoire) ; les autres axes (marketing, rayon) sont optionnels. La publication est bloquée tant que la catégorie comptable n'est pas renseignée (US-L1-08).
- **RG-M1-06** — En cas de **chevauchement de saisons**, la saison de **priorité supérieure** l'emporte pour la date donnée (US-L1-07).
- **RG-M1-07** — La **visibilité par canal** d'un type de tarif restreint son apparition (ex. tarif réservé au guichet, non affiché en ligne).
- **RG-M1-08** — Un produit vendu d'avance (abonnement, carte) déclenche la logique **PCA** (M6) : le revenu n'est pas reconnu à la vente. M1 porte la **règle PCA** (étalement / consommation) sans l'exécuter.
- **RG-M1-09** — **Publication conditionnée** : un produit ne peut passer en « Publié » que s'il possède un libellé, ≥ 1 site, ≥ 1 canal, au moins un prix valide et une catégorie comptable. Sinon il reste en « Brouillon » ; la tentative de publication liste précisément ce qui manque (US-L1-09).
- **RG-M1-10** — Un **stock partagé** (pool) se décrémente pour tous les produits qui y sont rattachés.
- **RG-M1-11** — Le **type est modifiable après création** uniquement via une **conversion assistée** entre types compatibles : l'assistant affiche le mapping des champs conservés / ajoutés / abandonnés et exige une confirmation explicite listant les données qui seront perdues ; la conversion est journalisée (ancien type, nouveau type, utilisateur, date) (US-L1-10). L'édition directe du champ « Type » reste **verrouillée** après le premier enregistrement.
- **RG-M1-12** — Le **quota d'un service inclus** se décompte en **semaine calendaire** (remise à zéro le lundi, fenêtre lundi→dimanche), **sans report** des non-utilisés — décision actée (US-L1-05).
- **RG-M1-13** — Le **bonus d'une carte multi-entrées** (« 10=12 ») est un **stock de compostages** (12 passages réels), valable jusqu'à l'**expiration de la carte** — décision actée (US-L1-06).
- **Historisation des prix** — Un prix modifié conserve sa valeur passée pour l'audit ; une modification de prix n'est **pas rétroactive** sur les commandes déjà passées ; sur un abonnement en cours, elle s'applique au prochain renouvellement si la formule est en « prix évolutif » (US-L1-03, cahier §7).
- **Non-suppression des référentiels utilisés** — Un type de tarif ou une saison utilisé par un produit publié ne peut être **supprimé**, seulement **archivé/désactivé** ; les grilles historiques restent valides (US-L1-07, cahier §7).
- **Divulgation progressive (règle de simplicité F-1, constitution §2)** — Le type pré-remplit des défauts (marges de compostage, TVA, compte comptable hérités de la catégorie/structure) et réduit la saisie au minimum (ex. « Billet daté » ≈ 4 champs).

## 5. Objets de données
Les types PHP indiqués sont indicatifs (spec = comportement observable). Tout objet est rattaché à un **Établissement** via le socle (`RG-SOCLE-01`) ; identifiants = **UUID** (constitution §3).

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| **Produit** | id | uuid | PK | — |
| | libellé | string (i18n) | requis, 2–120 car. | multilingue (US-L1-02) |
| | type | ref TypeProduit | requis | verrouillé après création ; modifiable via conversion assistée (RG-M1-11) |
| | statut | enum {brouillon, publié, archivé} | défaut = brouillon | RG-M1-09 |
| | sites | ref Etablissement[] | ≥ 1 pour publier | socle L0 (RG-SOCLE-01) |
| | canaux | set {guichet, en_ligne, borne} | ≥ 1 pour publier | RG-M1-09 |
| | durée de validité | duration | optionnel | défaut hérité du type (ex. « jusqu'à 1 an ») |
| | code produit | string | unique par périmètre | régénéré à la duplication (US-L1-11) |
| | catégories | ref Categorie[] (1 par axe) | axe comptable requis pour publier | RG-M1-05 |
| | note interne, description(i18n), médias, produits associés, couleur caisse, champsPerso[], documents[] | divers | selon onglets visibles | RG-M1-02 |
| **TypeProduit** | code | string | unique | ~22 types (entrée simple, billet daté, abonnement, adhésion, carte, entraînement, location, séjour, boutique…) |
| | facettes | set {stock, consommateur, visibilité, carnet, billet, formule, accès…} | — | pilote les onglets visibles (RG-M1-02) |
| **TypeTarif** | id, nom(i18n) | uuid, string | nom requis | référencé par GrilleTarifaire |
| | visibilité canal | set {guichet, en_ligne, borne} | — | RG-M1-07 |
| | ordre d'affichage, actif | int, bool | — | désactivable, non supprimable si utilisé |
| **Saison** | id, nom | uuid, string | nom requis | — |
| | dateDébut, dateFin | date, date | début ≤ fin | RG-M1-06 |
| | priorité | int | requis | départage un chevauchement (RG-M1-06) |
| | récurrence annuelle | bool | — | ⚠ HYPOTHÈSE : mécanique de projection annuelle non détaillée dans les sources |
| **GrilleTarifaire** | (produit, typeTarif, saison) | tuple FK×3 | clé unique | RG-M1-01 |
| | prix | decimal(≥0) | ≥ 0 ; null = non commercialisé | US-L1-03 |
| | prixHistorique[] | (valeur, dateEffet, auteur) | append-only | historisation pour audit (US-L1-03) |
| **TrancheQuotientFamilial** | id, min, max | uuid, decimal, decimal | bornes contiguës, sans trou ni chevauchement | US-L1-04 |
| | typeTarif | ref TypeTarif | requis | une valeur de QF → une et une seule tranche (US-L1-04) |
| **Formule** (facette du Produit abonnement/adhésion) | droitAccès | enum {illimité, quota_passages(n), plage_horaire} | — | RG-M1-03 |
| | servicesInclus | ServiceInclus[] | — | RG-M1-03/12 |
| | périodicité | enum {mensuel, annuel, personnalisé} | requise | base du prélèvement |
| | sepa | bool + jourPrélèvement | mandat requis (M4/M6) si activé | — |
| | renouvellement | {auto:bool, prix:fixe/évolutif, nbRenouvellements:int?, emailNotif} | nbRenouv. vide = reconduction tacite | cahier M1-03 |
| | engagement | {duréeMin, conditionsPause, conditionsRésiliation} | — | affiché au client (CGV) |
| | dates début/fin, durée validité | date, date, duration | — | US-L1-05 |
| **ServiceInclus** | activité | ref Activité (M5) | requis | RG-M1-03 |
| | quota | int > 0 | — | RG-M1-12 |
| | période | enum, défaut = semaine calendaire | remise à zéro lundi, sans report | RG-M1-12 |
| **CarteMultiEntrées** (facette carnet du Produit) | nbPayé | int > 0 | entier > 0 | US-L1-06 |
| | nbCrédité (total) | int > 0 | ≥ nbPayé | RG-M1-13, US-L1-06 |
| | stockCompostages | int | init = nbCrédité, décrémenté à chaque passage | RG-M1-04/13 |
| | validité | {durée, dateButoir} | paramétrable | bonus valable jusqu'à expiration carte (RG-M1-13) |
| **Promotion** | id, type | uuid, enum {pourcentage, montant, offre_groupée, bonus_10=12} | — | RG-M1-04 |
| | valeur, conditions, période | decimal, texte, (début/fin) | — | US-L1-07 |
| | cumul / exclusivité | enum {cumulable, exclusif} | — | — |
| | canaux, éligibilité | set, critère (ex. adhérent) | — | — |
| **Categorie** | id, axe | uuid, enum {marketing, comptable, rayon} | — | 3 axes indépendants (RG-M1-05) |
| | chemin (arbre), libellé | tree path, string | arborescence par axe | un produit ↔ 1 valeur / axe |
| **Stock** | type | enum {dédié, partagé} | — | RG-M1-10 |
| | disponibilité | int | — | — |
| | pool | ref Pool? | si partagé | décrément mutualisé (RG-M1-10) |

## 6. Critères d'acceptation

- **CA-1 (US-L1-01)** — *Étant donné* un catalogue volumineux, *quand* je saisis un terme (libellé ou code produit) et combine des filtres (type, statut, catégorie, saison), *alors* la liste se filtre sous 1 s, les filtres sont cumulables, persistants entre deux visites, et les colonnes (libellé, prix, statut, date de modif.) sont triables.
- **CA-2 (US-L1-01)** — *Étant donné* une sélection multiple de lignes, *quand* je lance une action de masse (archiver / publier / changer de catégorie), *alors* elle ne s'applique qu'aux lignes sélectionnées et demande confirmation si irréversible (archivage).
- **CA-3 (US-L1-02, RG-M1-02)** — *Étant donné* la création d'un produit, *quand* je choisis son type, *alors* les onglets pertinents s'affichent/masquent immédiatement avec valeurs par défaut, et un onglet masqué ne conserve aucune saisie orpheline.
- **CA-4 (US-L1-02, RG-M1-11)** — *Étant donné* un produit enregistré, *quand* je tente de changer son type directement dans la fiche, *alors* le champ Type est verrouillé et seule la conversion assistée (CA-13) est proposée.
- **CA-5 (US-L1-03, RG-M1-01)** — *Étant donné* une grille tarif × saison, *quand* je saisis les prix, *alors* une case vide vaut « non commercialisé » (≠ gratuit), tout montant est ≥ 0, un chevauchement de saisons est refusé, et un prix modifié conserve sa valeur passée pour l'audit.
- **CA-6 (US-L1-04)** — *Étant donné* des tranches de quotient familial bornées (min–max), *quand* je les enregistre, *alors* le système refuse tout trou ou chevauchement, chaque tranche pointe vers un type de tarif de la grille, et une valeur de QF donnée résout toujours vers **une et une seule** tranche.
- **CA-7 (US-L1-05, RG-M1-12)** — *Étant donné* une formule d'abonnement, *quand* j'ajoute un service inclus avec un quota, *alors* le quota se réinitialise sur la **semaine calendaire** (lundi–dimanche, pas de fenêtre glissante), sans report, et une simulation affiche les droits d'une semaine type avant publication.
- **CA-8 (US-L1-06, RG-M1-13)** — *Étant donné* une carte « 10=12 », *quand* je saisis 10 payées / 12 créditées, *alors* le stock de compostages initial vaut 12 (décrémenté à chaque passage), le total crédité doit être ≥ total payé, les valeurs entières > 0, et une règle de validité (durée / date butoir) est paramétrable.
- **CA-9 (US-L1-07)** — *Étant donné* les référentiels (types de tarifs, saisons, promotions), *quand* je les gère, *alors* le CRUD impose un libellé unique, une saison ne chevauche pas une autre, et un référentiel utilisé par un produit publié ne peut être supprimé (désactivation seule).
- **CA-10 (US-L1-08, RG-M1-05)** — *Étant donné* les trois axes (marketing, comptable, rayon), *quand* je tente de publier un produit sans catégorie comptable, *alors* la publication est bloquée ; les axes marketing et rayon restent facultatifs.
- **CA-11 (US-L1-09, RG-M1-09)** — *Étant donné* un produit en brouillon, *quand* je le publie, *alors* la transition n'aboutit que si libellé + ≥ 1 site + ≥ 1 canal + ≥ 1 prix valide + catégorie comptable sont présents, sinon la liste des blocages est affichée ; les transitions autorisées sont brouillon→publié et publié→archivé ; un produit archivé n'est plus vendable mais reste consultable et historisé.
- **CA-12 (RG-M1-06)** — *Étant donné* deux saisons qui se chevauchent pour une date donnée, *quand* le prix est résolu, *alors* c'est la saison de priorité supérieure qui s'applique.
- **CA-13 (US-L1-10, RG-M1-11)** — *Étant donné* un produit d'un type A, *quand* je lance la conversion assistée vers un type compatible B, *alors* l'assistant affiche le mapping (champs conservés / ajoutés / abandonnés), exige une confirmation explicite listant les données perdues, et journalise l'opération (ancien type, nouveau type, utilisateur, date).
- **CA-14 (US-L1-11)** — *Étant donné* un produit publié, *quand* je le duplique, *alors* la copie reprend type, tarifs et catégories, régénère un code produit unique, naît au statut **brouillon** quel que soit le statut de l'original, et son libellé est suffixé « – copie » et immédiatement modifiable.
- **CA-15 (RG-M1-07)** — *Étant donné* un type de tarif à visibilité « guichet uniquement », *quand* un produit est consulté sur le canal en ligne, *alors* ce tarif n'apparaît pas.

## 7. Cas limites
- **Publication incomplète** — Tentative de publication d'un produit sans l'un des prérequis (RG-M1-09) : refusée, message listant précisément chaque élément manquant ; le produit reste en Brouillon.
- **Modification de prix en cours de vie** — Non rétroactive sur les commandes passées ; sur un abonnement actif « prix évolutif », appliquée au prochain renouvellement ; « prix fixe » → inchangée pour l'engagement en cours (cahier §7).
- **Suppression d'un type de tarif / saison utilisé** — Interdite ; archivage seul ; les grilles historiques restent valides (US-L1-07).
- **Case de grille vide** — Interprétée comme « non commercialisé » et jamais comme « gratuit » (US-L1-03).
- **Quota de service inclus** — Non-report d'une semaine sur l'autre ; remise à zéro le lundi même si la semaine précédente n'a pas été consommée (RG-M1-12).
- **Carte multi-entrées expirée** — Les compostages restants (bonus compris) deviennent inutilisables à la date butoir de la carte (RG-M1-13). ⚠ HYPOTHÈSE : le traitement comptable/PCA du solde perdu à l'expiration relève de M6 et n'est pas spécifié ici.
- **Conversion vers un type incompatible** — Non proposée par l'assistant (seuls les types compatibles sont offerts) ; RG-M1-11 ne définit pas la matrice de compatibilité → ⚠ HYPOTHÈSE : matrice « types compatibles » à définir avec le métier avant implémentation.
- **Stock partagé (pool)** — La vente d'un produit rattaché à un pool décrémente le pool pour tous les produits liés (RG-M1-10) ; risque de rupture simultanée à arbitrer avec M2.
- **Utilisateur sans affectation sur l'établissement du produit** — Aucun accès (hérité du socle, `RG-SOCLE-05`).

## 8. Dépendances
- **Dépend de : socle L0** (`specs/L0-socle/spec-socle.md`) — hiérarchie Groupe/Région/Établissement/Espace (`RG-SOCLE-01`), permissions `module × action` réutilisées sur le module `offre` (`RG-SOCLE-02/03/04`), cadrage par établissement actif (`RG-SOCLE-05`), journal d'audit append-only pour les modifications sensibles et les conversions (`RG-SOCLE-07`).
- **Référencé par / interagit avec (hors périmètre L1) :**
  - **M2 · Vente & Caisse** (L2) — consomme prix, canaux, stock ; décrémente stocks/compostages à la vente.
  - **M5 · Planning & Réservation** — décompte réel des services inclus et des passages (quotas RG-M1-03/12).
  - **M6 · Compta & Régie** — PCA (RG-M1-08), taux de TVA, plan de comptes, axe comptable (RG-M1-05).
  - **Module Accès** (L3) — compostage physique, appairage RFID/wallet ; M1 en paramètre le nombre et les marges.
  - **M7 · Reporting** — exploite les catégories multi-axes (RG-M1-05).
  - **M8 · Admin & Droits** — UI adaptative masquant les actions non autorisées.

---

## Points ouverts / hypothèses (récapitulatif)
1. **⚠ HYPOTHÈSE — Permission fine `offre × modifier_compta`** : séparation gestionnaire/comptable sur la même fiche produit décrite fonctionnellement mais non nommée ; à arbitrer avec M8.
2. **⚠ HYPOTHÈSE — Récurrence annuelle des saisons** : projection d'une saison d'une année sur l'autre mentionnée sans mécanique ; à préciser.
3. **⚠ HYPOTHÈSE — Matrice des types compatibles pour la conversion assistée** (RG-M1-11) : non fournie ; à définir avec le métier.
4. **⚠ HYPOTHÈSE — Traitement du solde de compostages perdu à l'expiration d'une carte** : relève de la PCA/M6, non spécifié en L1.
5. **DISCORDANCE À TRANCHER — Transition publié→brouillon** : le cahier détaillé (états & cycle de vie) décrit « Publié → dépublier → Brouillon » et une action de masse « Dépublier », alors que US-L1-09 stipule « pas de retour direct publié→brouillon ». La spec retient la version US-L1-09 pour les transitions du produit (brouillon→publié, publié→archivé, archivé→brouillon pour réactivation), tout en conservant la permission `offre × publier`/dépublier. **Décision métier requise** sur l'existence du « dépublier » direct.
