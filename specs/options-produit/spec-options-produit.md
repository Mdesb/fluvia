# Spec — Options sur les produits (`App\OptionProduit`, extension de M1)

- **Lot / module :** extension transverse de **M1 Offre & Tarification** (rattachée au lot L1, consommée par L2)
- **Stories couvertes :** `US-OPT-01` à `US-OPT-06` — **numérotées par cette spec, HORS backlog** (aucune US dédiée « options produit » dans `backlog.html` ; seule une mention générique « promotions & options » figure au cahier, voir §4)
- **Règles de gestion :** `RG-OPT-01` à `RG-OPT-12` — **créées par cette spec** (aucune `RG-Mx` dédiée aux options structurées dans `cahier-detaille.html`)
- **Statut :** brouillon

## 0. Étude de cohérence (préalable obligatoire)

Lu avant rédaction : `specs/L1-offre/spec-offre.md`, `specs/L2-vente/spec-vente.md`, `app/src/Offre/Entity/*` (`Produit`, `TypeProduit`, `GrilleTarifaire`, `Stock`, `Categorie`), `app/src/Vente/Entity/LigneVente.php` + `Service/AjoutLigneHandler.php` + `Service/PanierCalculateur.php`, `app/src/Stock/Entity/ArticleStock.php` + `Service/RattacherArticleAuProduitHandler.php`.

**Constats de recouvrement (à ne pas dupliquer) :**
- `cahier-detaille.html` §M2-03 mentionne « **promotions & options** (promo groupe %, promotion libre en %/€) » — dans ce contexte, « options » désigne en réalité des **remises libres de caisse**, déjà couvertes par `LigneVente.remiseLigne`/`remiseType` (M2) et par `Promotion` (M1, `RG-M1-04`, `TypePromotion`). **Ce n'est pas la même notion** que les « options produit » (suppléments/variantes) demandées ici : aucun recoupement de règle, seulement une homonymie à signaler pour éviter la confusion en revue.
- `Produit.champsPerso` (JSON libre, M1) permet de stocker des paires clé/valeur arbitraires mais **ne porte aucune structure** de choix (obligatoire/facultatif, unique/multiple), aucun impact prix calculable, aucun lien stock — impropre à porter des options ; **non réutilisé**, un modèle dédié est justifié.
- `Produit.produitsAssocies` (M-N self, M1) est une liste de **suggestions croisées** (vente additionnelle libre, sans structure de sélection ni impact prix automatique sur la ligne) — **notion différente**, non réutilisée, pas de recouvrement de règle.
- Pratique actuelle de contournement : dupliquer un produit par variante (`US-L1-11`, ex. « Entrée + serviette » comme produit à part entière). Cette spec **ne remplace pas** cet usage pour les cas complexes (variantes structurantes du prix affichées en rayon) ; elle vise le cas **simple et fréquent** — un supplément/choix ajouté au moment de la vente sur un produit existant, sans multiplier le catalogue.
- `Vente\Entity\LigneVente` porte déjà un précédent directement réutilisable : `promotionsAppliquees` (array JSON figé à l'ajout, cf. `AjoutLigneHandler::promotionsAuto`). Le choix retenu ici (§5) **reprend ce même patron** pour `optionsSelectionnees`, plutôt que de créer une entité de liaison `LigneVente ↔ ValeurOption` séparée — solution la plus simple, cohérente avec l'existant.
- `Stock\Entity\ArticleStock` est une entité **autonome** (rattachable 0..1 à un `Produit`, `RG-STOCK-01`) : une `ValeurOption` peut s'y référencer directement sans passer par un `Produit` satellite. Le décrément réel à la vente (`Vente\Service\DecrementStockHandler`) ne connaît aujourd'hui que le `Stock` (M1) porté par `Produit` ; **étendre ce décrément aux options est une dépendance de cette spec sur M2/Stock, pas un acquis** (§8).

## 1. Objectif
Permettre à un gestionnaire d'offre d'ajouter à un produit des **options simples** (suppléments, variantes) — obligatoires ou facultatives, à choix unique ou multiple, avec un **impact tarifaire** (montant fixe ou %) — afin qu'un caissier les sélectionne en une étape au moment de la vente et que le **prix s'ajuste automatiquement**, sans complexité de configuration.

## 2. Périmètre
- **Inclus :**
  - Groupes d'options réutilisables entre produits (`GroupeOption`), à choix **unique** ou **multiple**.
  - Valeurs d'option (`ValeurOption`) avec impact tarifaire **montant fixe** ou **pourcentage** (signé, +/−).
  - Rattachement d'un groupe à un produit (`OptionProduit`), avec caractère **obligatoire/facultatif propre au produit** (le même groupe référentiel peut être obligatoire sur un produit, facultatif sur un autre).
  - Lien optionnel d'une `ValeurOption` à un **article de stock** (`Stock\ArticleStock`) — décrément à la vente.
  - Disponibilité d'une option restreinte à un **sous-ensemble d'établissements** du produit.
  - Report de la sélection et de l'impact prix figé sur la `LigneVente` (M2), dans le respect NF525/inaltérabilité.
- **Exclu (pour l'instant), signalé explicitement :**
  - **Configurateur avancé** : pas de dépendances conditionnelles entre options (ex. « option B visible seulement si A cochée »), pas de matrices de compatibilité, pas de bundles/kits à règles multiples.
  - **Quantité indépendante par option** (ex. « + 2 serviettes » sur une ligne de quantité 1) : une option sélectionnée s'applique à **toute la quantité de la ligne**, pas de sous-quantité par option (`RG-OPT-06`).
  - **Tarification d'option par grille (type de tarif × saison)** : l'impact d'une option est une valeur unique (montant/%), pas une grille multi-dimensionnelle comme `GrilleTarifaire` (M1) — hors périmètre, à réévaluer si le besoin apparaît.
  - **Vente en ligne / boutique (M3)** : cette spec couvre le contrat de données et le comportement en caisse (M2) ; l'exposition des options dans un tunnel e-commerce est hors périmètre, référencée seulement.
  - **Compte comptable / TVA propres à une option** : une option hérite du régime comptable du produit parent (`Produit.reglePca`/`compteComptable`/`tauxTva`, M1) ; pas de paramétrage compta dédié à l'option dans cette version.

## 3. Acteurs & droits
Réutilise le modèle du socle (`RG-SOCLE-02/03/04/05`) : permission = couple `module × action`, portée par l'établissement actif ; l'UI masque ce qui n'est pas autorisé. Deux modules concernés : **`offre`** (paramétrage catalogue, déjà utilisé par M1) et **`vente`** (sélection en caisse, déjà utilisé par M2) — **aucun nouveau module de droits** n'est introduit.

| Acteur | Peut | Permission (module × action) |
|---|---|---|
| Gestionnaire d'offre | Créer/éditer les `GroupeOption` et `ValeurOption`, les rattacher à un produit (`OptionProduit`), fixer obligatoire/facultatif et la disponibilité par établissement | `offre × modifier` (réutilisée, cf. décision ci-dessous), `offre × lire` |
| Administrateur | Idem + gérer le référentiel global des groupes/valeurs, désactiver un référentiel utilisé | `offre × gerer` (réutilisée, surensemble M1) |
| Agent de caisse | Sélectionner les options (obligatoires/facultatives) à l'ajout d'un produit au panier ; voir le prix ajusté | `vente × creer` (réutilisée M2), `offre × lire` |
| Comptable | Lecture seule des options ; aucun paramétrage dédié (l'option hérite du régime compta du produit, §2) | `offre × lire` |

- **Décision (simplicité)** — Pas de permission fine dédiée `offre × gerer_options` : le paramétrage des options est traité comme une **modification de la fiche produit** (même geste que gérer une `Formule` ou une `CarteMultiEntrees` satellite en M1), donc couvert par `offre × modifier`/`offre × gerer` déjà existantes. ⚠ HYPOTHÈSE : à confirmer avec M8 si un cloisonnement plus fin s'avère nécessaire en usage réel.

## 4. Comportements & règles
Aucune `RG-Mx` du cahier ne couvre les options structurées (seule l'homonymie §0 existe) ; les règles ci-dessous sont **proposées par cette spec**, en cohérence avec les patrons déjà actés sur M1/M2.

- **RG-OPT-01** — Un `GroupeOption` est un **référentiel réutilisable** entre produits (comme `TypeTarif`/`Saison` en M1) ; son mode de sélection est **unique** (0 ou 1 valeur, ex. taille) ou **multiple** (0..n valeurs, ex. extras).
- **RG-OPT-02** — Le rattachement d'un `GroupeOption` à un `Produit` se fait via une liaison `OptionProduit`, qui porte le caractère **obligatoire/facultatif** et l'**ordre d'affichage propres à ce produit** : un même groupe référentiel peut être obligatoire sur un produit A et facultatif sur un produit B.
- **RG-OPT-03** — Un groupe **obligatoire** impose au moins une valeur sélectionnée avant l'ajout de la ligne au panier (unique : exactement 1 ; multiple obligatoire : ≥ 1) ; sinon l'ajout est **refusé** avec un message listant l'option manquante.
- **RG-OPT-04** — Chaque `ValeurOption` porte un **impact tarifaire** signé : montant fixe (€) ou pourcentage (%) du **prix de base résolu par la grille M1** (`RG-M1-01`). L'impact s'applique **par unité de la ligne**. En cas de cumul de plusieurs valeurs sur une même ligne : impact unitaire total = Σ(montants fixes) + prix de base × Σ(pourcentages) — les pourcentages s'additionnent sur le prix de base, **sans effet cumulatif entre eux** (pas de compound), pour rester simple et prévisible.
- **RG-OPT-05** — Dans un groupe à **choix unique**, une seule `ValeurOption` est retenue par ligne ; une tentative d'envoi de plusieurs valeurs pour le même groupe « unique » est **rejetée** (422), plutôt que silencieusement tronquée.
- **RG-OPT-06** — Une `ValeurOption` peut être liée à un **article de stock** (`Stock\ArticleStock`) ; sa sélection décrémente cet article **à la validation de la vente**, à hauteur de la **quantité de la ligne** (pas de quantité indépendante par option — simplicité, cf. §2 exclusions).
- **RG-OPT-07** — La **disponibilité** d'un `GroupeOption`/`ValeurOption` peut être restreinte à un sous-ensemble des **établissements** du produit (ex. « casier » seulement en piscine A) ; par défaut, une option est disponible partout où le produit est commercialisé (réutilise le principe multi-entités du socle, `RG-SOCLE-01`).
- **RG-OPT-08** — Un `GroupeOption`/`ValeurOption` **désactivé** n'est plus proposable pour une nouvelle vente, mais reste **consultable/historisé** sur les lignes de vente qui le portent déjà (non-suppression des référentiels utilisés, cohérent avec `RG-M1-07`/US-L1-07 sur les référentiels M1).
- **RG-OPT-09** — La sélection d'options sur une `LigneVente` est **figée** (snapshot libellé + impact) au moment de l'ajout au panier, sur le même patron que `promotionsAppliquees` (`AjoutLigneHandler`, US-L2-03) : une évolution ultérieure du référentiel `GroupeOption`/`ValeurOption` n'affecte **pas rétroactivement** les lignes déjà composées/vendues (cohérent avec l'historisation des prix M1).
- **RG-OPT-10** — Une fois la vente **scellée** (NF525, `RG-M2-07`), les options d'une ligne sont **inaltérables** comme le reste de la ligne ; toute correction passe par **contre-passation/avoir**, jamais par modification directe (réutilise `RG-M2-07`).
- **RG-OPT-11** — Une `ValeurOption` dont l'`ArticleStock` lié est en **rupture** bloque l'ajout de la ligne au panier (« stock épuisé »), au même titre qu'un produit géré en stock à 0 (réutilise `RG-M2-04`/CA-6 de M2).
- **RG-OPT-12** — ⚠ HYPOTHÈSE (simplification assumée) : dans cette version minimale, **tout produit publié** peut porter des groupes d'options ; aucune nouvelle facette dédiée n'est ajoutée à `TypeProduit` (`RG-M1-02`) pour restreindre les options à certains types. À revoir si un type (ex. Formule d'abonnement, Carte multi-entrées) doit explicitement interdire ou contraindre les options.

## 5. Objets de données
Types indicatifs (spec = comportement observable). UUID (constitution §3). `GroupeOption`/`ValeurOption`/`OptionProduit` vivent dans le namespace `App\OptionProduit`.

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| **GroupeOption** | id | uuid | PK | référentiel réutilisable entre produits |
| | libellé | string | requis | ⚠ HYPOTHÈSE : string simple (non i18n) dans cette version ; à revoir si exposé en boutique M3 |
| | modeSelection | enum {unique, multiple} | requis | RG-OPT-01 |
| | actif | bool | défaut = true | désactivable, non supprimable si utilisé (RG-OPT-08) |
| **ValeurOption** | id | uuid | PK | — |
| | groupeOption | ref GroupeOption | requis | — |
| | libellé | string | requis | ex. « Serviette », « Taille M » |
| | impactType | enum {montant, pourcentage} | requis | RG-OPT-04 |
| | impactValeur | decimal (signé) | requis | + ou − (ex. supplément +2,00€, variante « sans » −1,00€) |
| | articleStock | ref ArticleStock (Stock) | optionnel | RG-OPT-06 ; 0..1 |
| | ordreAffichage | int | — | — |
| | actif | bool | défaut = true | RG-OPT-08 |
| **OptionProduit** (liaison) | id | uuid | PK | pivot Produit ↔ GroupeOption |
| | produit | ref Produit (M1) | requis | RG-OPT-02 |
| | groupeOption | ref GroupeOption | requis | RG-OPT-02 |
| | obligatoire | bool | défaut = false | propre à ce produit (RG-OPT-02/03) |
| | ordreAffichage | int | — | ordre d'affichage sur la fiche produit |
| | etablissementsRestriction | ref Etablissement[] | optionnel | vide = tous les sites du produit (RG-OPT-07) |
| | actif | bool | défaut = true | — |
| | *contrainte* | (produit, groupeOption) | unique | un groupe rattaché une seule fois par produit |
| **LigneVente** (M2, champs ajoutés — *extension*, pas de redéfinition) | optionsSelectionnees | array JSON, nullable | figé à l'ajout | `[{groupeOptionId, valeurOptionId, libelle, impactType, impactValeur, montantUnitaireApplique}]`, RG-OPT-09, patron `promotionsAppliquees` |
| | impactOptionsUnitaire | decimal(10,2) | défaut = 0.00 | Σ des `montantUnitaireApplique`, ajouté à `prixUnitaire` dans le calcul de `montantLigne` (RG-OPT-04) |

## 6. Critères d'acceptation

- **CA-1 (US-OPT-01, RG-OPT-01/02)** — *Étant donné* un `GroupeOption` « Taille » existant, *quand* le gestionnaire d'offre le rattache au produit A en le marquant obligatoire, *alors* le groupe et ses valeurs apparaissent sur la fiche du produit A sans qu'il ait été nécessaire de recréer le groupe.
- **CA-2 (US-OPT-01, RG-OPT-02)** — *Étant donné* le même `GroupeOption` « Extras » rattaché au produit A (obligatoire) et au produit B (facultatif), *quand* on consulte chaque produit, *alors* le caractère obligatoire/facultatif diffère par produit sans dupliquer le référentiel.
- **CA-3 (US-OPT-02, RG-OPT-04)** — *Étant donné* une `ValeurOption` à impact « +2,00 € » et une autre à impact « +10% », toutes deux sélectionnées sur une ligne dont le prix grille est 20,00 €, *quand* la ligne est calculée, *alors* le prix unitaire effectif = 20,00 + 2,00 + (20,00 × 10%) = **24,00 €**.
- **CA-4 (US-OPT-03, RG-OPT-03)** — *Étant donné* un `GroupeOption` obligatoire à choix unique (« Taille » S/M/L) sur un produit, *quand* le caissier tente d'ajouter la ligne sans avoir choisi de valeur, *alors* l'ajout est **refusé**, message listant l'option manquante.
- **CA-5 (US-OPT-02, RG-OPT-05)** — *Étant donné* un `GroupeOption` à choix unique, *quand* le caissier envoie deux valeurs pour ce même groupe, *alors* la requête est **rejetée (422)**.
- **CA-6 (US-OPT-02, RG-OPT-09)** — *Étant donné* une ligne de vente avec options sélectionnées, *quand* elle est ajoutée au panier, *alors* libellé et impact de chaque option sont **figés** sur la ligne ; une modification ultérieure du référentiel `GroupeOption`/`ValeurOption` **ne modifie pas rétroactivement** cette ligne.
- **CA-7 (US-OPT-04, RG-OPT-06/11)** — *Étant donné* une `ValeurOption` « Casier » liée à un `ArticleStock` en stock suffisant, *quand* elle est sélectionnée sur une ligne de quantité 2, *alors* le stock de l'article décrémente de 2 à la validation de la vente ; *quand* le stock est insuffisant, *alors* l'ajout est refusé (« stock épuisé »).
- **CA-8 (RG-OPT-07)** — *Étant donné* une `ValeurOption` restreinte à l'établissement A, *quand* le produit est vendu à l'établissement B, *alors* cette valeur **n'apparaît pas** dans les choix proposés.
- **CA-9 (RG-OPT-08)** — *Étant donné* une `ValeurOption` désactivée après avoir été vendue, *quand* on consulte la ligne de vente historique, *alors* elle reste **visible/historisée** ; *quand* on tente de composer une nouvelle ligne, *alors* elle n'est **plus proposable**.
- **CA-10 (US-OPT-06, RG-OPT-10)** — *Étant donné* une vente **scellée** (NF525) portant une ligne avec options, *quand* un responsable habilité effectue un remboursement/avoir sur cette ligne, *alors* l'avoir reprend le **montant total incluant l'impact des options**, aucune ligne d'origine n'est modifiée (contre-passation seule, réutilise `RG-M2-07`).

## 7. Cas limites
- **Option obligatoire non renseignée** — Bloque l'ajout de la ligne au panier (RG-OPT-03, CA-4) ; le produit lui-même reste vendable dès qu'une valeur valide est choisie.
- **Option indisponible** (désactivée ou hors établissement) — Non proposée à l'écran ; une tentative de sélection forcée via l'API est rejetée (422) (RG-OPT-07/08).
- **Cumul d'options** — Plusieurs groupes différents se cumulent librement sur une même ligne (ex. Taille + Extras) ; au sein d'un même groupe « unique », un seul choix retenu, sinon rejet (RG-OPT-05).
- **Remboursement d'une ligne avec options** — L'avoir porte sur la **ligne entière** (montant incluant les options) ; pas de remboursement partiel d'une option isolée sans annuler/contre-passer la ligne (simplicité assumée). ⚠ HYPOTHÈSE : un besoin de remboursement partiel d'une seule option n'est pas couvert par cette version, à confirmer si le métier l'exige.
- **Suppression d'un `GroupeOption`/`ValeurOption` utilisé** — Interdite (référentiel utilisé par un produit publié ou par des lignes de vente historiques) ; seul l'archivage/désactivation est possible, cohérent avec le patron « non-suppression des référentiels utilisés » de M1 (US-L1-07).
- **Conversion de type de produit (`RG-M1-11`)** — ⚠ HYPOTHÈSE : les liaisons `OptionProduit` existantes sont conservées telles quelles après une conversion assistée, faute de facette dédiée (RG-OPT-12) ; à revalider si une matrice de compatibilité type↔options est introduite.
- **Duplication d'un produit (US-L1-11)** — ⚠ HYPOTHÈSE : les liaisons `OptionProduit` (groupes rattachés, obligatoire/facultatif, restriction d'établissement) sont dupliquées à l'identique sur la copie, par cohérence avec la duplication des tarifs/catégories (CA-14, `spec-offre.md`) ; à confirmer.
- **Stock d'option en rupture au moment de la validation (concurrence inter-caisses)** — ⚠ HYPOTHÈSE : le décrément atomique de l'`ArticleStock` lié à une option n'est pas aujourd'hui exécuté par `DecrementStockHandler` (M2), qui ne connaît que le `Stock` (M1) porté par `Produit` ; **extension requise côté M2** avant mise en production (§8), risque de survente entre-temps signalé.
- **Vente hors-ligne (mode dégradé, `RG-M2-08`)** — Les options sélectionnées doivent rester résolues localement (référentiel synchronisé sur le poste) ; non détaillé ici, dépend du mécanisme offline générique de M2.
- **Utilisateur sans affectation sur l'établissement du produit** — Aucun accès (hérité du socle, `RG-SOCLE-05`).

## 8. Dépendances
- **Dépend de M1 · Offre & Tarification** (`specs/L1-offre/spec-offre.md`) — `Produit` (rattachement des `OptionProduit`), résolution du **prix de base** par la grille (`RG-M1-01`) sur laquelle s'applique l'impact des options, `Etablissement` (socle) pour la restriction de disponibilité (RG-OPT-07).
- **Dépend de M2 · Vente & Caisse** (`specs/L2-vente/spec-vente.md`) — extension de `LigneVente` (`optionsSelectionnees`, `impactOptionsUnitaire`) et de `AjoutLigneHandler`/`PanierCalculateur` pour intégrer l'impact des options dans le prix unitaire effectif et `montantLigne` ; réutilise le blocage stock (`RG-M2-04`) et le régime NF525/contre-passation (`RG-M2-07`, `RG-SOCLE-07`). **Cette extension de code M2 n'est pas déjà acquise** : c'est une dépendance d'implémentation de cette spec, pas un existant réutilisé tel quel.
- **Dépend (optionnel) de Stock** (`app/src/Stock/`) — si une `ValeurOption` est liée à un `ArticleStock` ; nécessite d'étendre `DecrementStockHandler` (M2) pour décrémenter aussi les articles liés aux options sélectionnées, en plus du `Stock` M1 du produit (cas limite §7).
- **Dépend du socle L0** (`specs/L0-socle/spec-socle.md`) — permissions `module × action` réutilisées sur `offre`/`vente` (`RG-SOCLE-02/03/04`), cadrage par établissement actif (`RG-SOCLE-05`), UUID (constitution §3), journal d'audit append-only (`RG-SOCLE-07`).

---

## Points ouverts / hypothèses (récapitulatif)
1. **⚠ HYPOTHÈSE — Extension du décrément de stock aux options** (`RG-OPT-06`, cas limite §7) : `DecrementStockHandler` (M2) ne connaît aujourd'hui que le `Stock` M1 du `Produit` ; le décrément d'un `ArticleStock` lié à une `ValeurOption` nécessite une évolution de M2, non couverte par le code existant.
2. **⚠ HYPOTHÈSE — Permission dédiée `offre × gerer_options`** : cette spec réutilise `offre × modifier`/`offre × gerer` par simplicité (§3) ; à confirmer avec M8 si un cloisonnement plus fin est requis en usage réel.
3. **⚠ HYPOTHÈSE — Libellé de `GroupeOption`/`ValeurOption` non i18n** dans cette version (contrairement à `Produit.libelle`) ; à revoir si les options sont exposées en boutique multilingue (M3).
4. **⚠ HYPOTHÈSE — Sort des `OptionProduit` à la conversion de type et à la duplication d'un produit** (RG-M1-10/11, US-L1-10/11) : conservation/duplication à l'identique proposée par défaut, non tranchée par le cahier (aucune règle source n'existe, ce module étant net-new).
5. **⚠ HYPOTHÈSE — Aucune facette `TypeProduit` dédiée aux options** (`RG-OPT-12`) : simplification assumée pour rester minimal ; à revoir si certains types doivent explicitement exclure les options.
6. **⚠ HYPOTHÈSE — Remboursement partiel d'une option isolée** non couvert (cas limite §7) : l'avoir porte sur la ligne entière, cohérent avec le modèle de contre-passation M2 (`RG-M2-07`) qui ne connaît pas de granularité infra-ligne.
