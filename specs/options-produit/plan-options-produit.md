# Plan technique — Options sur les produits (`App\OptionProduit`)

- **Spec source :** specs/options-produit/spec-options-produit.md
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Couvre :** US-OPT-01 à 06 · RG-OPT-01 à 12 · CA-1 à CA-10 (numérotées par la spec, hors backlog/cahier officiels — cf. spec §0)

> **Réutilisation stricte (à ne pas dupliquer)** — `App\Offre\Entity\Produit` (rattachement, jamais
> redéfini) ; `App\Offre\Service\ResolveurPrix` (prix de base sur lequel s'applique l'impact, RG-M1-01,
> inchangé) ; `App\Vente\Entity\LigneVente` + `App\Vente\Service\AjoutLigneHandler` +
> `App\Vente\Service\PanierCalculateur` — **patron `promotionsAppliquees` repris à l'identique** pour
> `optionsSelectionnees` (code réel lu, §0 de la spec) ; `App\Securite\Security\PermissionVoter`
> (`is_granted('PERM', 'module.action')`, RG-SOCLE-04) sur les permissions `offre.*`/`vente.creer`
> **déjà existantes**, aucune permission nouvelle créée (décision spec §3) ; `App\Securite\Service\
> ContexteEtablissement` (en-tête `X-Etablissement`, RG-SOCLE-05) pour le filtrage établissement RG-OPT-07 ;
> `App\Stock\Entity\ArticleStock` **référencé** (lien informationnel, §7 Risques — décrément réel non
> acquis, voir Risque n°1).
>
> **Non réutilisé intentionnellement** — pas d'entité de liaison `LigneVente ↔ ValeurOption` (le
> snapshot JSON suffit, cohérent avec `promotionsAppliquees`) ; pas de nouvelle extension Doctrine de
> cloisonnement établissement pour les référentiels `GroupeOption`/`ValeurOption`/`OptionProduit` —
> **même absence** que pour `App\Offre\Entity\GrilleTarifaire`/`Promotion` (code réel lu : ces liaisons
> à `Produit` n'ont pas de `PerimetreXxxExtension` dédiée, seul `Produit` lui-même est cloisonné) ; pas de
> modification de `App\Offre\State\{ConvertirProcessor,DupliquerProcessor}` (M1, inchangés — écart
> documenté au Risque n°7).

---

## 0. Décisions structurantes (résumé)

1. **Liaison Produit↔GroupeOption = entité pivot légère `OptionProduit`, pas une simple collection.**
   Une `ManyToMany` nue entre `Produit` et `GroupeOption` ne peut pas porter d'attributs propres à la
   relation (`obligatoire`, `ordreAffichage`, `etablissementsRestriction`, `actif`) — or RG-OPT-02 exige
   précisément qu'un même groupe référentiel soit obligatoire sur un produit et facultatif sur un autre.
   Une entité pivot est donc la solution **la plus simple qui satisfasse cette exigence**, exactement le
   même choix déjà fait par `App\Offre\Entity\GrilleTarifaire` (pivot Produit×TypeTarif×Saison portant un
   prix) — aucun nouveau patron introduit.
2. **Sélection sur la vente = snapshot JSON sur `LigneVente`, pas d'entité de liaison
   `LigneVente↔ValeurOption`.** Reprend à l'identique `LigneVente.promotionsAppliquees` /
   `AjoutLigneHandler::promotionsAuto()` (code réel) : figé à l'ajout, non rétroactif (RG-OPT-09),
   inaltérable une fois la vente scellée (RG-OPT-10, réutilise `RG-M2-07` sans code additionnel côté
   contre-passation — `ContrePassationHandler` n'est **pas modifié**, cf. §2.4).
3. **Aucune nouvelle permission.** `offre.lire`/`offre.modifier`/`offre.gerer` (paramétrage catalogue) et
   `vente.creer` (sélection en caisse) suffisent — décision actée par la spec §3, ⚠ hypothèse à confirmer
   avec M8 si un cloisonnement plus fin s'avère nécessaire en usage réel.
4. **Décrément de stock des options (RG-OPT-06/RG-OPT-11) : hors périmètre de ce plan.** `ValeurOption`
   porte un lien **informationnel** vers `Stock\Entity\ArticleStock`, mais ni le blocage de rupture à
   l'ajout au panier, ni le décrément à la validation ne sont implémentés dans ce lot — dépendance M2/Stock
   non acquise, déjà signalée par la spec elle-même (§0, §7, §8, hypothèse n°1). Détail et pistes de
   résolution : Risque n°1.

---

## 1. Entités & schéma

Namespace : **`App\OptionProduit\Entity\*`** (+ `App\OptionProduit\Enum\*`, `App\OptionProduit\Service\*`,
`App\OptionProduit\State\*`). `id` = UUID (`Symfony\Component\Uid\Uuid`, type Doctrine `uuid`).
`declare(strict_types=1)` partout. Noms métier en français.

**Enums** :
- `App\OptionProduit\Enum\ModeSelectionOption` {`Unique = 'unique'`, `Multiple = 'multiple'`} (RG-OPT-01)
- `App\OptionProduit\Enum\ImpactOptionType` {`Montant = 'montant'`, `Pourcentage = 'pourcentage'`}
  (RG-OPT-04) — enum dédié au module (pas de réutilisation de `App\Vente\Enum\RemiseType`, domaines
  distincts, cohérent avec la convention « namespaces par domaine » de la constitution §7)

| Entité (`App\OptionProduit\Entity\*`) | Champ | Type Doctrine | Null | Index/Contrainte | Relation |
|---|---|---|---|---|---|
| **GroupeOption** (`opt_groupe`) | id | uuid | non | PK | référentiel réutilisable entre produits (RG-OPT-01) |
| | libelle | string(120) | non | `NotBlank` | ⚠ non i18n (hypothèse spec §3/pt.3) |
| | modeSelection | string(8), `enumType: ModeSelectionOption` | non | — | RG-OPT-01 |
| | actif | bool | non, défaut `true` | — | RG-OPT-08 |
| | creeLe, modifieLe | datetime_immutable | non | — | même patron que `Produit`/`ArticleStock` |
| **ValeurOption** (`opt_valeur`) | id | uuid | non | PK | — |
| | groupeOption | `ManyToOne` → `GroupeOption` (inversedBy `valeurs`) | non | index, `NotNull` | — |
| | libelle | string(120) | non | `NotBlank` | ex. « Serviette », « Taille M » |
| | impactType | string(11), `enumType: ImpactOptionType` | non | — | RG-OPT-04 |
| | impactValeur | decimal(10,2) signé | non | — | + ou − ; validé par plage raisonnable applicative (pas de contrainte SQL de signe) |
| | articleStock | `ManyToOne` → `Stock\Entity\ArticleStock` (**réutilisé, référencé**) | oui | 0..1, index | RG-OPT-06 ; **lien informationnel seul**, cf. Risque n°1 |
| | ordreAffichage | smallint | non, défaut `0` | — | — |
| | actif | bool | non, défaut `true` | — | RG-OPT-08 |
| | creeLe, modifieLe | datetime_immutable | non | — | — |
| **OptionProduit** (`opt_option_produit`, pivot) | id | uuid | non | PK | pivot Produit↔GroupeOption (RG-OPT-02) |
| | produit | `ManyToOne` → `App\Offre\Entity\Produit` (**réutilisé, référencé**) | non | index, `NotNull` | — |
| | groupeOption | `ManyToOne` → `GroupeOption` | non | index, `NotNull` | — |
| | obligatoire | bool | non, défaut `false` | — | propre à ce produit (RG-OPT-02/03) |
| | ordreAffichage | smallint | non, défaut `0` | — | ordre sur la fiche produit |
| | etablissementsRestriction | `ManyToMany` → `App\Organisation\Entity\Etablissement` (join `opt_option_produit_etablissement`) | — | vide = tous les sites du produit | RG-OPT-07 |
| | actif | bool | non, défaut `true` | — | — |
| | creeLe, modifieLe | datetime_immutable | non | — | — |
| | *contrainte* | (produit_id, groupe_option_id) | — | **unique** | un groupe rattaché une seule fois par produit |

**`LigneVente` (M2, `App\Vente\Entity\LigneVente`) — champs ajoutés, additif pur, aucun champ existant
modifié/retiré** :

| Champ | Type Doctrine | Null | Notes |
|---|---|---|---|
| `optionsSelectionnees` | `#[ORM\Column(nullable: true)]` sur propriété `?array` (Doctrine infère `json`, **même déclaration exacte** que `promotionsAppliquees`) | oui | figé à l'ajout (RG-OPT-09) ; forme `list<array{groupeOptionId: string, valeurOptionId: string, libelle: string, impactType: string, impactValeur: string, montantUnitaireApplique: string}>` |
| `impactOptionsUnitaire` | `decimal(10,2)`, `options: ['default' => '0.00']` (même style que `montantLigne`) | non | Σ des `montantUnitaireApplique`, ajouté à `prixUnitaire` dans le calcul de `montantLigne` (RG-OPT-04, §2.3) |

> Objets référencés, non redéfinis (constitution §4) : `App\Offre\Entity\Produit` (M1), `App\Vente\Entity\
> {Vente,LigneVente}` (M2), `App\Organisation\Entity\Etablissement` (socle), `App\Stock\Entity\
> ArticleStock` (référencé, lien informationnel seul).

---

## 2. Sélection sur la vente — additif précis sur M2

### 2.1 Contrat de l'endpoint d'ajout au panier (existant, corps enrichi)

`POST /ventes/{id}/lignes` (inchangé, `App\Vente\State\AjoutLigneProcessor` → `AjoutLigneHandler::ajouter()`,
corps lu par `LecteurCorps::corps()`) — **un seul champ nouveau, optionnel**, dans le corps JSON existant :

```jsonc
{
  "produit": "/produits/<uuid>",
  "typeTarif": "/type_tarifs/<uuid>",
  "quantite": 1,
  // ... champs existants inchangés (beneficiaire, note, remiseLigne, remiseType, qf, prixForce...)
  "options": ["/valeur_options/<uuid-taille-m>", "/valeur_options/<uuid-serviette>"]
}
```

- `options` absent ou `[]` ⇒ comportement **strictement identique** à l'existant (non-régression garantie
  par construction : la nouvelle branche de code n'est exécutée que si la clé est présente et non vide).
- Chaque entrée : UUID ou IRI d'une `ValeurOption` (même convention de résolution que `produit`/`typeTarif`,
  réutilise le helper privé `AjoutLigneHandler::uuidOuNull()` déjà existant).
- Réponse : la `Vente` (comme aujourd'hui), avec la `LigneVente` créée exposant `optionsSelectionnees` et
  `impactOptionsUnitaire` dans le groupe `vente:read`.

### 2.2 Additif dans `App\Vente\Service\AjoutLigneHandler`

**Ce qui est ajouté** (nouvelle méthode privée `resoudreOptions()` + un appel dans `ajouter()`, juste après
la résolution du prix de base — donc après le bloc `if ($forcer) {...} else {...}` qui fixe
`$ligne->setPrixUnitaire(...)`, et avant `$ligne->setPromotionsAppliquees(...)`) :

```php
// Nouveau, après la résolution du prix de base :
[$snapshot, $impactCentimes] = $this->resoudreOptions($produit, $donnees['options'] ?? [], $ligne, $vente);
$ligne->setOptionsSelectionnees($snapshot);
$ligne->setImpactOptionsUnitaire($this->calculateur->decimal($impactCentimes));
```

Logique de `resoudreOptions(Produit $produit, array $refs, LigneVente $ligne, Vente $vente): array` :

1. **Options disponibles pour ce produit/cet établissement** — charge les `OptionProduit` actives
   rattachées au produit (`repository->findBy(['produit' => $produit, 'actif' => true])`), puis filtre
   celles dont `etablissementsRestriction` est vide **ou** contient `$vente->getEtablissement()`
   (RG-OPT-07).
2. **Résolution de chaque référence envoyée** — via `uuidOuNull()` puis `repository->find()` sur
   `ValeurOption` ; rejette (422 « option indisponible ou inconnue ») si : introuvable, `actif = false`
   (RG-OPT-08, cas limite §7), ou son `groupeOption` n'est dans **aucune** `OptionProduit` filtrée à
   l'étape 1 (option non proposée pour ce produit/cet établissement — cas limite §7, tentative forcée par
   API).
3. **Regroupement par `groupeOption`** des valeurs acceptées.
4. **RG-OPT-05 (choix unique)** — pour chaque groupe en mode `Unique` regroupant **plus d'une** valeur
   envoyée : 422 « un seul choix autorisé pour le groupe « <libellé> » » (CA-5).
5. **RG-OPT-03 (obligatoire)** — pour chaque `OptionProduit` filtrée à l'étape 1 avec `obligatoire = true` :
   vérifie qu'au moins une valeur de son `groupeOption` a été retenue ; sinon 422 « option obligatoire
   manquante : <libellé du groupe> » (CA-4), message listant l'option manquante comme l'exige RG-OPT-03.
6. **Calcul de l'impact** (RG-OPT-04, CA-3) — en centimes, même précision que `PanierCalculateur` (méthodes
   `centimes()`/`decimal()` déjà publiques, réutilisées telles quelles, aucune duplication d'arithmétique) :
   ```php
   $prixBaseCentimes = $this->calculateur->centimes($ligne->getPrixUnitaire());
   foreach ($valeursRetenues as $valeur) {
       $impactUnitaire = $valeur->getImpactType() === ImpactOptionType::Pourcentage
           ? intdiv($prixBaseCentimes * $this->calculateur->centimes($valeur->getImpactValeur()), 100 * 100)
           : $this->calculateur->centimes($valeur->getImpactValeur());
       $impactTotal += $impactUnitaire; // Σ(montants fixes) + prixBase × Σ(pourcentages), sans effet cumulatif entre eux (RG-OPT-04)
       $snapshot[] = [
           'groupeOptionId' => (string) $valeur->getGroupeOption()->getId(),
           'valeurOptionId' => (string) $valeur->getId(),
           'libelle' => $valeur->getLibelle(),
           'impactType' => $valeur->getImpactType()->value,
           'impactValeur' => $valeur->getImpactValeur(),
           'montantUnitaireApplique' => $this->calculateur->decimal($impactUnitaire),
       ];
   }
   ```
7. **Pas de vérification de stock/rupture ici** — décision §0.4 / Risque n°1 : `ValeurOption.articleStock`
   n'est **pas** interrogé par `resoudreOptions()` dans ce lot.

`AjoutLigneHandler` reçoit une seule nouvelle dépendance implicite : le repository `ValeurOption`/
`OptionProduit` via `$this->em->getRepository(...)` (même style que `Promotion` dans `promotionsAuto()`,
aucun nouveau service injecté nécessaire).

### 2.3 Additif dans `App\Vente\Service\PanierCalculateur`

Deux lignes modifiées (pas de nouvelle méthode) :

```php
// recalculerLigne() — brut intègre désormais l'impact unitaire des options :
$brut = ($this->centimes($ligne->getPrixUnitaire()) + $this->centimes($ligne->getImpactOptionsUnitaire())) * $ligne->getQuantite();

// recalculerVente() — même correction pour la cohérence de totalRemises :
$brut = ($this->centimes($ligne->getPrixUnitaire()) + $this->centimes($ligne->getImpactOptionsUnitaire())) * $ligne->getQuantite();
```

`getImpactOptionsUnitaire()` retourne `'0.00'` par défaut (colonne `options: ['default' => '0.00']`) : pour
toute ligne sans option, `centimes('0.00') === 0`, donc **le calcul est strictement identique à
aujourd'hui** — non-régression garantie pour `tests/Vente/Unit/PanierCalculateurTest.php` existants.

### 2.4 Validation scellée / contre-passation (RG-OPT-10, CA-10) — **aucun code modifié**

`Vente.getTotal()` agrège déjà `Σ LigneVente.montantLigne` (via `PanierCalculateur::recalculerVente`, §2.3
ci-dessus) ; `ContrePassationHandler::rembourser()`/`annuler()` reprennent ce total tel quel pour créer
l'`Avoir`. Une fois l'impact des options intégré dans `montantLigne` (§2.3), **la contre-passation couvre
automatiquement le montant incluant les options**, sans aucune modification de
`App\Vente\Service\ContrePassationHandler` ni de `App\Vente\Entity\Avoir`.

### 2.5 NF525 / inaltérabilité — aucun code modifié

`optionsSelectionnees`/`impactOptionsUnitaire` sont des colonnes de `LigneVente`, déjà couvertes par le
mécanisme d'immuabilité post-scellement existant (`App\Vente\Nf525\InalterabiliteListener`, non modifié) —
même garantie que `promotionsAppliquees` aujourd'hui.

---

## 3. API Platform

Toutes ressources `#[ApiResource]`, `security:` via `is_granted('PERM', 'offre.<action>')` — réutilise
exactement les permissions déjà existantes (aucune nouvelle permission, §0.3).

| Ressource | Opérations | `security:` | Groupes sérialisation | Filtres |
|---|---|---|---|---|
| **GroupeOption** | `GetCollection`, `Get` | `offre.lire` | `groupe_option:read` | `SearchFilter` `libelle` (partial), `actif` (exact) |
| | `Post` | `offre.modifier` | `groupe_option:write` | création référentiel (CA-1) |
| | `Patch` | `offre.modifier` | `groupe_option:write` | édition/désactivation (RG-OPT-08) — pas de `Delete` (non-suppression d'un référentiel, même patron que M1 `Promotion`/`TypeTarif`) |
| **ValeurOption** | `GetCollection`, `Get` | `offre.lire` | `valeur_option:read` | `SearchFilter` `groupeOption` (exact), `actif` (exact) |
| | `Post`, `Patch` | `offre.modifier` | `valeur_option:write` | pas de `Delete` (RG-OPT-08) |
| **OptionProduit** | `GetCollection`, `Get` | `offre.lire` | `option_produit:read` | `SearchFilter` `produit` (exact) — utilisé par la fiche produit **et** par l'endpoint caisse §3.1 |
| | `Post` | `offre.modifier` | `option_produit:write` | rattachement d'un groupe à un produit (RG-OPT-02, CA-1/2) ; refuse (422) si `(produit, groupeOption)` déjà rattaché |
| | `Patch` | `offre.modifier` | `option_produit:write` | `obligatoire`/`ordreAffichage`/`etablissementsRestriction`/`actif` |
| | `Delete` | `offre.modifier` | — | détache le groupe du produit — **sûr** : l'historique des ventes ne référence jamais l'id `OptionProduit` (seulement `groupeOptionId`/`valeurOptionId` dans le snapshot JSON, §1), donc aucune perte de traçabilité |
| **`GET /produits/{id}/options-disponibles`** (custom, Provider dédié `App\OptionProduit\State\OptionsDisponiblesProvider`) | lecture seule | `offre.lire` (couvre aussi l'agent de caisse, cf. spec §3 — il a déjà `offre.lire`) | `option_produit:read` imbriqué `groupe_option:read`/`valeur_option:read` | Filtre serveur : `OptionProduit.actif = true`, `GroupeOption.actif = true`, `ValeurOption.actif = true`, `etablissementsRestriction` vide ou contenant `ContexteEtablissement::etablissementActif()` (RG-OPT-07, CA-8) ; tri `ordreAffichage` |
| **`POST /ventes/{id}/lignes`** (M2, **inchangé en surface**) | corps enrichi `options: string[]` (§2.1) | `vente.creer` (déjà en place) | `vente:read` (inclut désormais `optionsSelectionnees`/`impactOptionsUnitaire`) | — |

- **Custom vs CRUD** — seul `GET /produits/{id}/options-disponibles` est un Provider dédié (logique de
  filtrage établissement/actif non exprimable simplement en `ApiFilter` déclaratif) ; tout le reste est du
  CRUD Doctrine standard (même logique que `Promotion`/`GrilleTarifaire`, pas de State Processor
  nécessaire pour `GroupeOption`/`ValeurOption`/`OptionProduit`).
- **Pas de cloisonnement établissement dédié** sur `GroupeOption`/`ValeurOption`/`OptionProduit` en lecture
  CRUD standard (décision §0, cohérente avec `GrilleTarifaire`/`Promotion` existants) — seul l'endpoint
  caisse `options-disponibles` filtre par établissement actif.

---

## 4. Sécurité & droits

- **Permissions réutilisées, aucune créée** : `offre.lire`, `offre.modifier` (couvre gestionnaire ET
  administrateur — pas de distinction `offre.gerer` séparée dans ce plan, cf. Risque n°2),
  `vente.creer` (caisse, déjà en place M2).
- **Voters** — aucun voter nouveau ; `PermissionVoter` (socle, `is_granted('PERM', 'module.action')`)
  suffit pour toutes les opérations.
- **Cadrage établissement** — porté uniquement par `OptionProduit.etablissementsRestriction` (donnée
  métier, RG-OPT-07), interprété au moment de l'ajout au panier (`AjoutLigneHandler::resoudreOptions()`,
  §2.2) et de la consultation caisse (`OptionsDisponiblesProvider`, §3) via `ContexteEtablissement`
  (socle, RG-SOCLE-05) — **pas** un mécanisme de cloisonnement Doctrine des ressources `App\OptionProduit`
  elles-mêmes (décision §0).

---

## 5. Migrations

Une migration Doctrine réversible :

- **Créée** : `opt_groupe` (id, libelle, mode_selection, actif, cree_le, modifie_le) ;
  `opt_valeur` (id, groupe_option_id FK→opt_groupe, libelle, impact_type, impact_valeur `NUMERIC(10,2)`,
  article_stock_id FK→`stk_article` nullable, ordre_affichage, actif, cree_le, modifie_le) ;
  `opt_option_produit` (id, produit_id FK→`off_produit`, groupe_option_id FK→opt_groupe, obligatoire,
  ordre_affichage, actif, cree_le, modifie_le, **UNIQUE** `(produit_id, groupe_option_id)`) ;
  `opt_option_produit_etablissement` (table de jointure `option_produit_id` FK→opt_option_produit,
  `etablissement_id` FK→`org_etablissement`, PK composite).
- **Index** : `opt_valeur.groupe_option_id`, `opt_valeur.article_stock_id`, `opt_option_produit.produit_id`,
  `opt_option_produit.groupe_option_id`.
- **Modifiée (additif)** : `vente_ligne` — ajout colonnes `options_selectionnees` (`JSON DEFAULT NULL`),
  `impact_options_unitaire` (`NUMERIC(10,2) DEFAULT '0.00' NOT NULL`). **Aucune colonne existante
  modifiée/supprimée.**
- **Aucune donnée de permission à insérer** (§0.3 — permissions déjà existantes).
- **`down()`** : suppression des 2 colonnes `vente_ligne`, suppression des FK puis des 4 tables, dans
  l'ordre inverse de création (même style que les migrations existantes, ex. `Version20260816040036.php`
  lu comme référence de style).

---

## 6. Tests

| Test | Type | Couvre |
|---|---|---|
| `app/tests/OptionProduit/Api/CatalogueOptionsTest.php::test_meme_groupe_reutilisable_sans_recreation` | Fonctionnel API | CA-1 |
| `...::test_obligatoire_differe_par_produit_sans_dupliquer_referentiel` | Fonctionnel API | CA-2, RG-OPT-02 |
| `...::test_creation_rattachement_refuse_si_deja_rattache` | Fonctionnel API | RG-OPT-02 (contrainte unique) |
| `...::test_option_desactivee_non_proposable_mais_reste_historisee` | Fonctionnel API | CA-9, RG-OPT-08 |
| `...::test_option_restreinte_etablissement_non_proposee_hors_site` | Fonctionnel API | CA-8, RG-OPT-07 |
| `app/tests/OptionProduit/Api/AjoutLigneOptionsTest.php::test_option_facultative_montant_fixe_ajuste_prix` | Fonctionnel API (panier) | RG-OPT-04 |
| `...::test_cumul_montant_fixe_et_pourcentage_24_euros` | Fonctionnel API | CA-3 |
| `...::test_option_obligatoire_manquante_refuse_ajout_422` | Fonctionnel API | CA-4, RG-OPT-03 |
| `...::test_choix_unique_deux_valeurs_meme_groupe_rejete_422` | Fonctionnel API | CA-5, RG-OPT-05 |
| `...::test_options_absentes_comportement_inchange` | Fonctionnel API | Non-régression (§2.1) |
| `...::test_option_hors_etablissement_rejetee_si_forcee_par_api` | Fonctionnel API | Cas limite §7, RG-OPT-07 |
| `...::test_option_desactivee_rejetee_a_nouvel_ajout` | Fonctionnel API | CA-9 (second volet) |
| `app/tests/OptionProduit/Api/SnapshotOptionsTest.php::test_snapshot_fige_non_retroactif_apres_evolution_referentiel` | Fonctionnel API | CA-6, RG-OPT-09 |
| `app/tests/OptionProduit/Api/ContrePassationOptionsTest.php::test_avoir_reprend_montant_incluant_options` | Fonctionnel API | CA-10, RG-OPT-10 |
| `app/tests/OptionProduit/Unit/PanierCalculateurOptionsTest.php::test_recalculer_ligne_integre_impact_options` | Unitaire | §2.3 |
| `...::test_recalculer_ligne_options_et_remise_cumulees` | Unitaire | §2.3 (interaction remise/options) |
| `...::test_impact_zero_par_defaut_calcul_identique_existant` | Unitaire | Non-régression |
| **CA-7 (stock, RG-OPT-06/11)** | — | **Non couvert dans ce lot** — cf. Risque n°1 ; à traiter par un plan complémentaire une fois le mécanisme de disponibilité des options tranché |

**Non-régression obligatoire (rejouer tel quel, aucune modification attendue)** :
`app/tests/Vente/Api/PanierTest.php`, `app/tests/Vente/Api/StockTest.php`,
`app/tests/Vente/Unit/PanierCalculateurTest.php`, `app/tests/Vente/Api/ContrePassationTest.php`,
`app/tests/Vente/Api/ImmuabiliteTest.php`, `app/tests/Offre/Api/CatalogueTest.php`,
`app/tests/Offre/Api/TarificationTest.php`.

---

## 7. Tâches (voir tasks-options-produit.md)

- **T1** — Enums `App\OptionProduit\Enum\{ModeSelectionOption,ImpactOptionType}`.
- **T2** — Entité `GroupeOption` + `ApiResource` (CRUD, `offre.lire`/`offre.modifier`), sans `Delete`.
- **T3** — Entité `ValeurOption` + `ApiResource` (CRUD, lien `articleStock` référencé en lecture seule).
- **T4** — Entité pivot `OptionProduit` + `ApiResource` (CRUD + contrainte unique produit/groupe).
- **T5** — Migration Doctrine (4 tables + 2 colonnes `vente_ligne`), réversible.
- **T6** — Provider `OptionsDisponiblesProvider` (`GET /produits/{id}/options-disponibles`, filtrage
  établissement/actif, RG-OPT-07/08).
- **T7** — Additif `LigneVente` (`optionsSelectionnees`, `impactOptionsUnitaire`).
- **T8** — Additif `AjoutLigneHandler::resoudreOptions()` + appel dans `ajouter()` (RG-OPT-03/04/05/07/08,
  CA-3/4/5).
- **T9** — Additif `PanierCalculateur::recalculerLigne()`/`recalculerVente()` (intégration
  `impactOptionsUnitaire`).
- **T10** — Tests fonctionnels API (`OptionProduit/Api/*`, cf. §6), dépend de T2-T9.
- **T11** — Tests unitaires `PanierCalculateur` options, dépend de T9.
- **T12** — Rejeu non-régression `tests/Vente`, `tests/Offre`, dépend de T7-T9.
- **T13 (optionnelle, non planifiée sans arbitrage)** — Décrément/blocage de stock des options
  (RG-OPT-06/11) : à cadrer avec le propriétaire M2/Stock (cf. Risque n°1) avant toute mise en tâche.

Ordre recommandé : T1 → T2/T3 (parallélisables) → T4 → T5 → T6 → T7 → T8 → T9 → T10/T11/T12
(parallélisables).

---

## 8. Risques / à valider

1. **Décrément de stock des options (RG-OPT-06/RG-OPT-11) — non implémenté dans ce lot (priorité haute
   si le besoin métier de blocage de rupture est confirmé).** `Stock\Entity\ArticleStock` ne porte lui-même
   aucun compteur de disponibilité observable (code réel lu, `app/src/Stock/Entity/ArticleStock.php`,
   confirmé par `specs/stock/plan-stock.md` §1.2 : « disponibilité... jamais stockée sur `ArticleStock` » —
   seule `Produit.stock.disponibiliteEffective()` (M1) est décrémentable par `DecrementStockHandler`, un
   mécanisme conçu pour un produit vendu directement). Deux pistes, à trancher avec M2/Stock avant mise en
   production :
   - **(a)** exiger que toute `ValeurOption.articleStock` référence un `ArticleStock` **rattaché** à un
     `Produit` M1 satellite dédié (porteur d'un `Stock` M1) — réutilisation stricte, **zéro code neuf**,
     mais crée un produit « fantôme » dans le catalogue pour chaque option stockée, ce qui contredit la
     règle d'or de simplicité (constitution §2) côté gestionnaire d'offre.
   - **(b)** étendre `ArticleStock` d'un compteur de disponibilité propre + étendre
     `DecrementStockHandler`/`AjoutLigneHandler` pour le décrémenter/vérifier — modèle de données plus
     propre, mais modifie un module tiers (`App\Stock`) au-delà du périmètre annoncé par ce plan.
   Ce plan ne tranche pas : `ValeurOption.articleStock` reste un lien **purement informationnel**, CA-7
   n'est **pas couvert**.
2. **`offre.modifier` réutilisée pour gestionnaire ET administrateur (§4)** — la spec §3 distingue
   « gestionnaire : créer/éditer/rattacher » vs. « administrateur : + désactiver un référentiel utilisé »,
   mais sans permission dédiée, cette nuance n'est **pas** appliquée techniquement (n'importe quel porteur
   de `offre.modifier` peut désactiver un `GroupeOption` utilisé). ⚠ Hypothèse explicite de la spec
   (§3, « à confirmer avec M8 ») — si le besoin se confirme, une permission `offre.gerer_options` dédiée
   devra être créée (migration de seed de permission requise, hors périmètre de ce plan).
3. **Aucun cloisonnement établissement dédié sur les référentiels `GroupeOption`/`ValeurOption`/
   `OptionProduit` en lecture CRUD** — cohérent avec l'existant M1 (`GrilleTarifaire`/`Promotion` n'en ont
   pas non plus), donc pas une régression introduite par ce plan, mais à confirmer que cette absence reste
   acceptable pour ce nouveau module (un utilisateur avec `offre.lire` sur un seul établissement voit tout
   de même la totalité du référentiel d'options, seule l'API caisse `options-disponibles` filtre par site).
4. **Arrondi des impacts cumulés (RG-OPT-04)** — chaque `montantUnitaireApplique` est arrondi
   individuellement en centimes avant sommation ; un écart de ±1 centime est possible par rapport à un
   calcul global non arrondi par étape si de nombreuses options en pourcentage se cumulent. Acceptable en
   euros pour ce cas d'usage (suppléments simples), à couvrir explicitement dans les tests (§6, CA-3).
5. **Base de calcul remise/promotion vs. impact options non explicitement tranchée par la spec** — ce
   plan retient `brut = (prixUnitaire + impactOptionsUnitaire) × quantité` comme base commune à la remise
   de ligne **et** aux promotions automatiques (extension minimale de `PanierCalculateur`, §2.3). À
   confirmer en revue métier si un calcul différent est attendu (ex. remise/promotion calculée sur le seul
   prix de base, options ajoutées après).
6. **Mode dégradé (caisse hors-ligne, RG-M2-08)** — la spec (§7) signale que la résolution des options doit
   rester possible localement ; ce plan ne détaille pas la synchronisation offline du référentiel
   `GroupeOption`/`ValeurOption` (dépend du mécanisme générique de resynchro M2, hors périmètre de ce plan).
7. **Conversion de type / duplication de produit (RG-M1-10/11)** — `App\Offre\State\
   {ConvertirProcessor,DupliquerProcessor}` (M1) ne sont **pas modifiés** par ce plan : les liaisons
   `OptionProduit` d'un produit ne sont donc **ni conservées automatiquement après conversion, ni dupliquées
   automatiquement** avec le produit, contrairement à l'hypothèse de conservation/duplication proposée par
   la spec (§7, hypothèse n°4). Écart à trancher : soit accepté tel quel (comportement observable : options
   perdues à la conversion/duplication, à documenter côté UI), soit une tâche complémentaire (hors T1-T13)
   étend ces deux processors.
8. **Libellés non i18n (`GroupeOption.libelle`, `ValeurOption.libelle`)** — cohérent avec l'hypothèse
   explicite de la spec (§3, pt. 3) ; à revoir si les options sont un jour exposées en boutique
   multilingue (M3, hors périmètre).
