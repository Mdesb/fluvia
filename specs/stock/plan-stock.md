# Plan technique — Stock & Inventaire boutique (`App\Stock`)

- **Spec source :** specs/stock/spec-stock.md
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Couvre :** US-STOCK-01 à 12 · RG-STOCK-01 à 18 · CA-1 à CA-14 (⚠ US/RG **définies par l'agent de
  spec**, non numérotées/chiffrées officiellement dans le backlog — même réserve que
  `spec-boutique.md`/`spec-musee.md`, rappelée dans les Risques §9)

> **Réutilisation socle L0 (à ne pas dupliquer)** — hiérarchie `App\Organisation\Entity\Etablissement`
> (RG-SOCLE-01) ; `PermissionVoter` (attribut `PERM`, sujet `"module.action"`,
> `app/src/Securite/Security/PermissionVoter.php`, RG-SOCLE-04) sur `Affectation`/`CalculateurDroits` ;
> `ContexteEtablissement` (RG-SOCLE-05) ; cloisonnement par extension Doctrine, même patron que
> `App\Offre\Doctrine\PerimetreProduitExtension` (code réel lu).
>
> **Réutilisation M1 Offre (code réel)** — `App\Offre\Entity\Produit` (facette `stock`,
> `TypeProduit::FACETTE_STOCK`, RG-M1-02) et son `App\Offre\Entity\Stock` **dédié**
> (`Produit::getStock()`/`setStock()`, `Stock::TYPE_DEDIE`/`TYPE_PARTAGE`, `disponibiliteEffective()`,
> table `off_stock`) — **point d'intégration central**, détaillé §3. **Aucun champ M1 dupliqué.**
>
> **Réutilisation M2 Vente (code réel)** — `App\Vente\Service\DecrementStockHandler` (décrément
> atomique de `off_stock`/`off_pool` à la validation, **déjà** la seule autorité qui écrit
> `Stock.disponibilité` à la vente) et `App\Vente\Service\ValiderVenteService::valider()` (appelant),
> **ni l'un ni l'autre modifiés** — `App\Stock` s'y raccroche par un **listener Doctrine additif**
> (`Events::onFlush`, même patron que `App\Audit\Doctrine\AuditWriteSubscriber` déjà présent dans ce
> dépôt), qui **n'écrit jamais** `Stock.disponibilité` côté vente (§3.2). `App\Vente\Entity\{Vente,
> LigneVente,Avoir}` **référencés**, non redéfinis.
>
> **Réutilisation M8/Audit (code réel)** — `App\Audit\Doctrine\AuditWriteSubscriber::CLASSES_SURVEILLEES`
> étendu (ajout additif, même patron que tous les plans précédents, T13) ; **nouveau** listener dédié
> `App\Stock\Doctrine\MouvementStockInalterabiliteListener` (append-only, RG-STOCK-15/RG-SOCLE-07) —
> **fichier neuf**, ne modifie pas `App\Vente\Nf525\InalterabiliteListener` (scope M2 uniquement).
>
> **Non réutilisé intentionnellement** — aucune écriture comptable (`App\Compta\Entity\
> EcritureComptable`) n'est générée par ce module (gap acté, §9 Risque n°1) ; le `Stock`/`Pool`
> **partagé** de M1 (`RG-M1-10`) n'est pas un point d'accroche pour `ArticleStock` dans cette version
> (§9 Risque n°2).

---

## 0. Décisions structurantes (résumé)

1. **`ArticleStock` ne se rattache qu'à un `Stock` M1 de type `dedie`.** À la liaison
   `ArticleStock.produit`, un service dédié (`App\Stock\Service\RattacherArticleAuProduitHandler`)
   vérifie : (a) `Produit.type.aFacette(TypeProduit::FACETTE_STOCK)` (RG-M1-02) ; (b) si
   `Produit.stock` est `null`, **crée** un `Stock(type: dedie, disponibilite: 0)` et l'assigne (même
   geste que la création d'une `Formule`/`CarteMultiEntrees` satellite en cascade, code réel
   `Produit::setStock()`) ; (c) si `Produit.stock.type === TYPE_PARTAGE`, **refuse** (422) — cas non
   couvert, cf. Risque n°2. Un `Produit` sans `ArticleStock` reste vendable sans jamais être bloqué par
   le stock (CA-2, réutilise `RG-M2-04` tel quel, code réel `DecrementStockHandler` : « produit non géré
   en stock : jamais bloqué »).
2. **Hypothèse structurante non explicite dans la spec, mais nécessaire à la cohérence de `RG-STOCK-13`
   (« chaque établissement a ses propres couches de coût ») avec le schéma M1 réel lu** :
   `App\Offre\Entity\Produit::$stock` est une relation `ManyToOne` **singulière** (un seul `Stock` par
   `Produit`), alors que `Produit::$etablissements` est une `ManyToMany` (un même `Produit` **peut** être
   commercialisé sur plusieurs sites). Ce plan retient donc la convention **déjà implicite** dans les
   modules boutique existants (`plan-boutique.md`, `plan-musee.md` : un article physique = un `Produit`
   par établissement) : **un article de stock par établissement suppose un `Produit` M1 distinct par
   établissement** (même EAN catalogué deux fois si vendu sur deux sites). Un `Produit` M1 **partagé**
   entre plusieurs établissements avec un stock physique **distinct par site** n'est **pas supportable**
   par le schéma M1 actuel (une seule relation `Stock`) — **hors périmètre**, à traiter comme évolution
   M1 si confirmé (Risque n°4, distinct du gap pool partagé n°2).
3. **Deux mécanismes d'écriture de `Stock.disponibilité`, jamais les deux à la fois sur un même
   événement** (RG-STOCK-01, « une seule valeur observable ») :
   - **Mouvements natifs `App\Stock`** (réception, ajustement, perte/casse, transfert, régularisation
     d'inventaire, retour fournisseur, réintégration retour client) — **`App\Stock` est le seul
     rédacteur** : il écrit directement sur `off_stock.disponibilite` via une requête DBAL conditionnelle
     atomique, **même patron exact** que `DecrementStockHandler::decrementer()` (incrément au lieu de
     décrément, cf. §3.1). Aucune modification de `App\Offre\Entity\Stock` (utilisation de son API
     publique `getId()` seulement, pas de nouveau champ).
   - **Sortie de vente** (`sortie_vente`) — **`App\Vente\Service\DecrementStockHandler` reste l'unique
     rédacteur** de `off_stock.disponibilite` (code réel, inchangé). `App\Stock` se contente d'observer
     la validation via un listener Doctrine `onFlush` et de **journaliser** le mouvement détaillé
     (coût FIFO/LIFO, lots imputés) **dans la même transaction**, sans jamais réécrire la disponibilité
     déjà décrémentée par M2 (§3.2). C'est le point d'intégration central demandé par la spec (RG-STOCK-01).
4. **Le moteur FIFO/LIFO est un service unique** (`App\Stock\Service\MoteurValorisationFifoLifo`),
   consommé par **tous** les types de sortie qui suivent la méthode active (vente, perte/casse,
   ajustement négatif, sortie de transfert), avec deux exceptions **spécifiques au patron métier** de la
   spec, implémentées comme des chemins de consommation distincts du même service : le **retour
   fournisseur** cible **prioritairement le lot d'origine** (RG-STOCK-06, repli FIFO/LIFO sinon) et la
   **régularisation d'inventaire** valorise à la **dernière couche active** (RG-STOCK-12), pas selon
   l'ordre FIFO/LIFO général — cf. §2.3.
5. **La réintégration de stock après avoir n'est jamais automatique.** Aucun listener n'observe la
   création d'un `Avoir` M2 : RG-M2-07/§4.7 spec exige une **confirmation explicite opérateur** du retour
   physique en bon état, distincte du remboursement financier. `App\Stock` expose donc une opération
   dédiée (`POST /stock/mouvements/reintegration-retour`, §4) invoquée **volontairement** après coup, qui
   référence l'`Avoir` (lecture seule, `App\Vente\Entity\Avoir` réutilisé) sans jamais s'y abonner
   automatiquement.
6. **Le journal des mouvements (`MouvementStock`) et les lignes d'imputation de lot
   (`ImputationLotStock`) sont append-only** (RG-STOCK-15/RG-SOCLE-07) : aucune opération `Patch`/`Delete`
   exposée côté API, et un listener ORM dédié (`MouvementStockInalterabiliteListener`, `preUpdate`/
   `preRemove`, même patron que `App\Vente\Nf525\InalterabiliteListener` mais fichier neuf, scope
   `App\Stock`) lève une exception applicative si l'ORM tente malgré tout une modification/suppression.

---

## 1. Entités & schéma

Namespace : **`App\Stock\Entity\*`** (+ `App\Stock\Enum\*`, `App\Stock\Service\*`, `App\Stock\Doctrine\*`,
`App\Stock\Validator\*`, `App\Stock\State\*`). `id` = UUID (`Symfony\Component\Uid\Uuid`, type Doctrine
`uuid`). `declare(strict_types=1)` partout. Noms métier en français. Sauf mention contraire, toute entité
racine porte un `ManyToOne` non nul vers `App\Organisation\Entity\Etablissement` (RG-SOCLE-01,
RG-STOCK-13), cloisonnée par `App\Stock\Doctrine\PerimetreStockExtension` (même patron Doctrine que
`PerimetreProduitExtension`/`PerimetreVenteExtension`/`PerimetreCautionExtension`, une seule classe
couvrant toutes les entités `App\Stock` via un `match` sur `$resourceClass`).

Conventions de précision numérique (cohérence FIFO/LIFO, cf. exemples chiffrés spec §4.5) :
quantités `decimal(12,3)` (kg/litre fractionnaires), coûts unitaires `decimal(12,4)`, montants totaux
`decimal(12,2)`, taux/pourcentages `decimal(5,2)`.

**Enums (`App\Stock\Enum\*`)** : `Unite` {Piece, Kg, Litre, Paquet, Autre}, `MethodeValorisation`
{Fifo, Lifo}, `StatutCommandeAchat` {Brouillon, Envoyee, Confirmee, PartiellementRecue, Recue, Cloturee,
Annulee}, `StatutReceptionAchat` {Brouillon, Validee}, `TypeMouvementStock` {EntreeAchat, SortieVente,
RetourFournisseur, AjustementPositif, AjustementNegatif, PerteCasse, SortieTransfert, EntreeTransfert,
RegularisationInventaire}, `OrigineLotStock` {Reception, RegularisationInventaire, EntreeTransfert},
`StatutTransfertStock` {Demande, Expedie, Recu}, `PerimetreInventaire` {Tous, Rayon, Selection},
`StatutInventaire` {EnCours, EnValidation, Cloture}.

### 1.1 Fournisseurs & catalogue (US-STOCK-02, RG-STOCK-03)

| Entité | Champ | Type Doctrine | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **Fournisseur** (`stk_fournisseur`) | id | uuid | non | PK | RG-STOCK-03 |
| | etablissement | `ManyToOne` → `Etablissement` | non | index | ⚠ HYPOTHÈSE établissement (pas groupe), cf. Risque n°8 |
| | raisonSociale, siret | string(180), string(14) | non/oui | — | — |
| | contact, email, telephone, adresse | string(120), string(180), string(20), text | oui | — | — |
| | conditionsPaiement | text | oui | délai/modalités en clair | — |
| | actif | bool | non, défaut true | — | désactivable, jamais supprimable si référencé (CatalogueFournisseur/CommandeAchat) |
| **CatalogueFournisseur** (`stk_catalogue_fournisseur`) | id | uuid | non | PK | RG-STOCK-03 |
| | fournisseur | `ManyToOne` → `Fournisseur` | non | — | — |
| | articleStock | `ManyToOne` → `ArticleStock` | non | **unique** (fournisseur, articleStock) | — |
| | referenceFournisseur | string(64) | oui | — | US-STOCK-02 |
| | prixAchatNegocie | decimal(12,4) | non | ≥ 0 | peut différer du prix effectif reçu (RG-STOCK-05) |
| | delaiLivraisonJours | smallint | non | ≥ 0 | alimente `CommandeAchat.dateLivraisonPrevue` |
| | principal | bool | non, défaut false | 1 seul `true` par `articleStock` (garde applicative `PrincipalUniqueValidator`, pas de contrainte SQL partielle — limite MariaDB) | — |

### 1.2 Article de stock (US-STOCK-01, RG-STOCK-01/02, §3)

| Entité | Champ | Type Doctrine | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **ArticleStock** (`stk_article`) | id | uuid | non | PK | RG-STOCK-02 |
| | etablissement | `ManyToOne` → `Etablissement` | non | index | RG-STOCK-13 |
| | produit | `ManyToOne` → `App\Offre\Entity\Produit` (**réutilisé**) | oui | **unique** (0..1) | RG-STOCK-01, non vendable si absent (CA-2) |
| | codeEAN | string(13) | non | **unique** (etablissement, codeEAN) | validé par `App\Stock\Validator\CodeEanValide` (clé de contrôle EAN-13/EAN-8, CA-1) |
| | libelle | string(180) | non | — | — |
| | unite | string(10), `enumType: Unite` | non | — | US-STOCK-01 |
| | prixAchatHT | decimal(12,4) | non | ≥ 0 | distinct du prix de vente M1 |
| | tauxTvaAchat | decimal(5,2) | non | ≥ 0 | distinct de la TVA de vente M1/M6 |
| | methodeValorisation | string(4), `enumType: MethodeValorisation`, nullable | oui | — | hérite `ParametrageStock` si null (RG-STOCK-08) |
| | seuilMin, seuilMax | decimal(12,3), decimal(12,3) | non/non, défaut 0/0 | `seuilMin ≤ seuilMax` (CHECK MariaDB + validateur) | RG-STOCK-10 |
| | actif | bool | non, défaut true | non désactivable si solde de lots restant > 0 (garde applicative, §8 spec) | — |
| | creeLe, modifieLe | datetime_immutable | non | — | — |

> **Disponibilité, réservé, seuils** — **jamais stockés** sur `ArticleStock` : la disponibilité vendable
> reste `Produit.stock.disponibiliteEffective()` (M1, réutilisé tel quel) ; la quantité « réservée »
> (paniers en ligne M3) reste calculée côté M3 (`RG-M3-03`, hors périmètre de ce plan, §2.3 spec) ;
> l'alerte de réappro (RG-STOCK-10) est un **calcul de lecture** (`GET /stock/alertes-reappro`, §4), pas
> une entité stockée.

### 1.3 Paramétrage & commande d'achat (US-STOCK-03, RG-STOCK-04, §4.5)

| Entité | Champ | Type Doctrine | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **ParametrageStock** (`stk_parametrage`) | id, etablissement | uuid, `OneToOne` → `Etablissement` | non | 1/établissement | RG-STOCK-08 |
| | methodeValorisationDefaut | string(4), `enumType: MethodeValorisation` | non, défaut `Fifo` | — | hérité par `ArticleStock.methodeValorisation` null |
| | autoriserStockNegatif | bool | non, défaut false | — | RG-STOCK-16, ⚠ filet de sécurité sans point d'ancrage M2, cf. Risque n°3 |
| | seuilEcartSignificatifPourcentage | decimal(5,2) | oui | — | RG-STOCK-12, ⚠ HYPOTHÈSE — significatif si % **ou** montant dépassé (au choix, l'un des deux peut être null) |
| | seuilEcartSignificatifMontant | decimal(12,2) | oui | — | idem |
| **CommandeAchat** (`stk_commande_achat`) | id | uuid | non | PK | RG-STOCK-04 |
| | etablissement, fournisseur | `ManyToOne`, `ManyToOne` | non/non | — | — |
| | numero | string(32) | non | unique | traçabilité (`App\Stock\Service\GenerateurNumeroAchat`, même patron que `GenerateurNumero` M2) |
| | statut | string(20), `enumType: StatutCommandeAchat` | non, défaut `Brouillon` | — | cycle §6 |
| | dateCommande | date_immutable | non | — | — |
| | dateLivraisonPrevue | date_immutable | oui | dérivée du `CatalogueFournisseur.delaiLivraisonJours` à l'envoi | — |
| **LigneCommandeAchat** (`stk_ligne_commande_achat`) | id, commandeAchat | uuid, `ManyToOne` → `CommandeAchat` (inversedBy `lignes`) | non | requis | — |
| | articleStock | `ManyToOne` → `ArticleStock` | non | index | — |
| | quantiteCommandee | decimal(12,3) | non | > 0 | — |
| | prixAchatUnitaireHT, tauxTVA | decimal(12,4), decimal(5,2) | non/non | ≥ 0 | prix négocié par défaut, modifiable |
| | quantiteRecue | decimal(12,3) | non, défaut 0 | ≤ `quantiteCommandee` sauf sur-réception acceptée (§8 spec, cas limite) | recalculée à chaque validation de réception liée |

### 1.4 Réception d'achat & couches de coût (US-STOCK-04/05, RG-STOCK-05/06)

| Entité | Champ | Type Doctrine | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **ReceptionAchat** (`stk_reception_achat`) | id | uuid | non | PK | RG-STOCK-05 |
| | commandeAchat | `ManyToOne` → `CommandeAchat` | oui | — | optionnelle (réception hors commande, §4.2 spec) |
| | etablissement, fournisseur | `ManyToOne`, `ManyToOne` | non/non | — | — |
| | date | date_immutable | non | — | — |
| | numeroBonLivraison | string(64) | non | — | — |
| | statut | string(10), `enumType: StatutReceptionAchat` | non, défaut `Brouillon` | — | la validation crée les mouvements/lots (§3.1) |
| **LigneReceptionAchat** (`stk_ligne_reception_achat`) | id, reception | uuid, `ManyToOne` → `ReceptionAchat` (inversedBy `lignes`) | non | requis | — |
| | articleStock | `ManyToOne` → `ArticleStock` | non | index | — |
| | ligneCommandeAchat | `ManyToOne` → `LigneCommandeAchat` | oui | — | absent si réception hors commande |
| | quantiteRecue | decimal(12,3) | non | > 0 | — |
| | prixAchatUnitaireHT | decimal(12,4) | non | ≥ 0 | peut différer du prix commandé (RG-STOCK-05, CA-5) |
| | lotCree | `OneToOne` → `LotStock` | oui | **unique** | créé à la validation, jamais avant (RG-STOCK-04, CA-4) |
| **LotStock** (`stk_lot`) *(couche de coût)* | id | uuid | non | PK | RG-STOCK-08 |
| | articleStock | `ManyToOne` → `ArticleStock` | non | index (articleStock, dateEntree) — ordre FIFO/LIFO | RG-STOCK-13 |
| | etablissement | `ManyToOne` → `Etablissement` | non | dénorm | RG-STOCK-13 |
| | dateEntree | datetime_immutable | non | — | ordonne FIFO/LIFO |
| | quantiteInitiale | decimal(12,3) | non | > 0 | figée |
| | quantiteRestante | decimal(12,3) | non | ≥ 0, **CHECK** `quantite_restante <= quantite_initiale` (MariaDB 11.4, CHECK natif) | décrémentée à chaque imputation |
| | coutUnitaireHT | decimal(12,4) | non | ≥ 0 | figé à l'entrée, jamais réécrit (RG-STOCK-05/08) |
| | origine | string(24), `enumType: OrigineLotStock` | non | — | traçabilité |
| | referenceOrigineType, referenceOrigineId | string(32), uuid | oui/oui | référence logique polymorphe (`ReceptionAchat`\|`Inventaire`\|`TransfertStock`) | même patron que `Caution::typeCible/referenceCible` |

### 1.5 Mouvements & imputations de lot — append-only (US-STOCK-06, RG-STOCK-07/15)

| Entité | Champ | Type Doctrine | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **MouvementStock** (`stk_mouvement`) | id | uuid | non | PK, **append-only** | RG-STOCK-07/15 |
| | articleStock | `ManyToOne` → `ArticleStock` | non | index (articleStock, date) | RG-STOCK-13 |
| | etablissement | `ManyToOne` → `Etablissement` | non | dénorm, index | RG-STOCK-13 |
| | type | string(24), `enumType: TypeMouvementStock` | non | — | RG-STOCK-07, ensemble fermé |
| | date | datetime_immutable | non | — | — |
| | quantite | decimal(12,3) | non | > 0 | signe porté par `type`, pas par la valeur |
| | coutUnitaireCalcule, coutTotalCalcule | decimal(12,4), decimal(12,2) | oui/oui | **requis** (garde applicative) si `type` est une sortie | RG-STOCK-08 |
| | motif | string(255) | oui | **requis** (garde applicative) sauf `EntreeAchat`/`SortieVente` | RG-STOCK-07 |
| | referenceType, referenceId | string(32), uuid | oui/oui | référence logique polymorphe (`Vente`/`LigneVente`, `ReceptionAchat`, `TransfertStock`, `Inventaire`, `Avoir`) | pas de FK dure (cohérence multi-module, même patron que `MouvementCaution.mouvementRegieRef`) |
| | auteur | `ManyToOne` → `Utilisateur` (socle) | oui | — | null pour un `sortie_vente` sans opérateur identifiable à l'écriture (journalisé par le listener, §3.2) |
| **ImputationLotStock** (`stk_imputation_lot`) | id | uuid | non | PK, **append-only** | traçabilité FIFO/LIFO §2 |
| | mouvementStock | `ManyToOne` → `MouvementStock` (inversedBy `imputations`) | non | index | — |
| | lotStock | `ManyToOne` → `LotStock` | non | index | — |
| | quantiteImputee | decimal(12,3) | non | > 0 | — |
| | coutUnitaire | decimal(12,4) | non | ≥ 0 | snapshot du coût du lot au moment de l'imputation (audit, reconstruction historique §2.4) |

### 1.6 Transfert inter-établissements (US-STOCK-10, RG-STOCK-13/14)

| Entité | Champ | Type Doctrine | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **TransfertStock** (`stk_transfert`) | id | uuid | non | PK | RG-STOCK-14 |
| | articleStockSource | `ManyToOne` → `ArticleStock` | non | établissement source | ⚠ **écart assumé vs. tableau §5 spec** (qui ne porte qu'un seul `articleStock`) : `RG-STOCK-13` impose qu'un `ArticleStock` soit rattaché à **un seul** établissement, donc un transfert **ne peut référencer un `ArticleStock` unique côté source et destination** — ce plan retient deux références distinctes, comportement observable inchangé (CA-12) |
| | articleStockDestination | `ManyToOne` → `ArticleStock` | non | établissement ≠ source (garde applicative) | — |
| | quantite | decimal(12,3) | non | > 0 | — |
| | statut | string(10), `enumType: StatutTransfertStock` | non, défaut `Demande` | — | cycle §6 |
| | mouvementSortie | `ManyToOne` → `MouvementStock` | oui | **unique**, requis dès `Expedie` | RG-STOCK-14 |
| | mouvementEntree | `ManyToOne` → `MouvementStock` | oui | **unique**, requis dès `Recu` | RG-STOCK-14 |
| | demandePar | `ManyToOne` → `Utilisateur` | oui | — | traçabilité |
| | dateDemande, dateExpedition, dateReception | datetime_immutable | non/oui/oui | — | — |

> Pas de champ `etablissement` racine unique (le transfert **relie** deux établissements) : la
> restriction Doctrine (`PerimetreStockExtension`) filtre sur `articleStockSource.etablissement OR
> articleStockDestination.etablissement` appartenant au périmètre de l'utilisateur.

### 1.7 Inventaire physique (US-STOCK-11, RG-STOCK-12)

| Entité | Champ | Type Doctrine | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **Inventaire** (`stk_inventaire`) | id | uuid | non | PK | RG-STOCK-12 |
| | etablissement | `ManyToOne` → `Etablissement` | non | — | RG-SOCLE-01 |
| | perimetre | string(10), `enumType: PerimetreInventaire` | non | — | — |
| | filtre | `json` (`list<uuid>` de `Categorie` M1 ou `ArticleStock`, selon `perimetre`) | oui | requis si `perimetre ∈ {rayon, selection}` | détail d'implémentation non porté par le tableau §5 spec, additif |
| | statut | string(14), `enumType: StatutInventaire` | non, défaut `EnCours` | — | cycle §6 |
| | dateLancement, dateCloture | datetime_immutable | non/oui | `dateCloture ≥ dateLancement` | — |
| **LigneInventaire** (`stk_ligne_inventaire`) | id, inventaire | uuid, `ManyToOne` → `Inventaire` (inversedBy `lignes`) | non | requis | — |
| | articleStock | `ManyToOne` → `ArticleStock` | non | index | — |
| | quantiteTheorique | decimal(12,3) | non | ≥ 0, **figée** au lancement | snapshot `Produit.stock.disponibiliteEffective()` |
| | quantiteComptee | decimal(12,3) | oui | ≥ 0 | saisie au comptage |
| | ecart | decimal(12,3) | oui | dérivé = `quantiteComptee − quantiteTheorique` | RG-STOCK-12 |
| | significatif | bool | oui, défaut false | dérivé du seuil `ParametrageStock` | requiert validation Responsable si vrai |
| | valideParUtilisateur | `ManyToOne` → `Utilisateur` | oui | requis si `significatif` | `stock.valider_ecart` |
| | dateValidation | datetime_immutable | oui | requis si `significatif` | — |
| | mouvementRegularisation | `ManyToOne` → `MouvementStock` | oui | **unique** | créé à la régularisation, RG-STOCK-12 |

**Objets référencés, non redéfinis** (constitution §4) : `App\Offre\Entity\{Produit,Stock,TypeProduit,
Categorie}` (M1), `App\Vente\Entity\{Vente,LigneVente,Avoir}` (M2), `App\Securite\Entity\Utilisateur`
(socle), `App\Organisation\Entity\Etablissement` (socle).

---

## 2. Moteur de valorisation FIFO/LIFO

### 2.1 Service — `App\Stock\Service\MoteurValorisationFifoLifo`

```php
final class MoteurValorisationFifoLifo
{
    /** Consommation générale (vente, perte/casse, ajustement négatif, sortie de transfert). */
    public function consommerSelonMethode(
        ArticleStock $article,
        string $quantite,
        MethodeValorisation $methode,
    ): ResultatConsommation; // {coutTotal: string, imputations: list<{lot: LotStock, quantite: string, cout: string}>}

    /** RG-STOCK-06 : retour fournisseur — retire prioritairement du lot d'origine, repli FIFO/LIFO sinon. */
    public function consommerLotPrioritaire(
        LotStock $lotPreferentiel,
        string $quantite,
        MethodeValorisation $methodeRepli,
    ): ResultatConsommation;

    /** RG-STOCK-18 : valorisation courante = Σ(quantitéRestante × coûtUnitaire) des lots actifs. */
    public function valoriserCourant(ArticleStock $article): string;

    /** RG-STOCK-18 : valorisation historique à une date T (reconstruction, §2.4). */
    public function valoriserADate(ArticleStock $article, \DateTimeImmutable $date): string;

    /** RG-STOCK-12 : coût de la dernière couche active (régularisation d'inventaire, §2.3). */
    public function coutDerniereCoucheActive(ArticleStock $article): ?string;
}
```

- **Sélection des couches** — requête `LotStock` `WHERE articleStock = :a AND quantiteRestante > 0`,
  triée `dateEntree ASC` (FIFO) ou `dateEntree DESC` (LIFO). Boucle : impute
  `min(quantiteRestante_du_lot, quantiteRestanteAConsommer)`, décrémente `LotStock.quantiteRestante`,
  crée une ligne `ImputationLotStock`, cumule `quantite × coutUnitaireHT` au `coutTotal`, passe au lot
  suivant si insuffisant — exactement l'algorithme des exemples chiffrés §4.5 de la spec (CA-8/CA-9).
- **Rupture de couches** (le stock des lots ne suffit pas à couvrir la quantité demandée, ce qui **ne
  devrait pas arriver** si `Stock.disponibilité` — décrémentée par M2 avant l'appel au listener, §3.2 —
  est cohérente avec la somme des `LotStock.quantiteRestante`) : lève une exception applicative
  **journalisée mais non bloquante** pour la vente déjà validée (le mouvement M2/NF525 est scellé,
  irréversible) — imputation partielle sur les couches disponibles, écart tracé en incohérence à
  résoudre manuellement. Cas limite documenté au Risque n°5 (dérive possible en mode dégradé, §3.3).
- **Aucun `flush()` dans le service** (même convention que `App\Caution\Service\GestionCaution`) : les
  handlers/listeners appelants portent la transaction.

### 2.2 Méthode : paramétrable par établissement, surchargeable par article (RG-STOCK-08)

`App\Stock\Service\ResolveurMethodeValorisation::pour(ArticleStock $article): MethodeValorisation` —
retourne `$article->getMethodeValorisation() ?? $parametrage($article->getEtablissement())
->getMethodeValorisationDefaut()`. **Changement de méthode en cours d'exercice** (§4.5 spec, ⚠ HYPOTHÈSE
verrouillage) : `PATCH /stock/parametrage/{etablissement}` réservé à `stock.parametrer`, **non
rétroactif** — les `LotStock` déjà consommés ne sont jamais recalculés ; seules les consommations
**futures** appliquent la méthode nouvellement choisie. ⚠ **À valider par un expert-comptable** avant
activation en production (comparabilité de la valorisation avant/après, cf. Risque n°6).

### 2.3 Exceptions au chemin général (§0 décision n°4)

- **Retour fournisseur (RG-STOCK-06)** — `AjustementStockHandler::retourFournisseur()` résout le
  `LotStock` créé par la `LigneReceptionAchat` d'origine (si `ReceptionAchat` connue) et appelle
  `consommerLotPrioritaire()` ; à défaut de lot identifiable, `consommerSelonMethode()` (repli explicite).
- **Régularisation d'inventaire (RG-STOCK-12)** — **ne suit pas** l'ordre FIFO/LIFO général :
  - Écart **positif** (compté > théorique) : `InventaireRegularisationHandler` crée un **nouveau**
    `LotStock` (origine `RegularisationInventaire`) au coût = `coutDerniereCoucheActive()` (ou, à défaut
    de couche active, au **dernier prix d'achat connu de l'article** — `ArticleStock.prixAchatHT` ou
    dernière `LigneReceptionAchat`, ⚠ HYPOTHÈSE explicite de la spec §4.9/point ouvert n°9).
  - Écart **négatif** (compté < théorique) : consomme en priorité la **dernière couche active**
    (RG-STOCK-12 littéral) ; ⚠ HYPOTHÈSE d'implémentation **non tranchée par la spec** — si cette couche
    seule ne suffit pas, repli sur l'ordre FIFO/LIFO général pour le reliquat (à confirmer avec le
    métier, cf. Risque n°7).

### 2.4 Valorisation à une date T — courante vs. historique (RG-STOCK-18, US-STOCK-12)

- **T = maintenant** — trivial : `Σ(LotStock.quantiteRestante × coutUnitaireHT)` sur les lots actifs de
  l'article (`quantiteRestante > 0`), quelle que soit la méthode (la méthode ne pilote que **l'ordre de
  consommation**, pas la formule de valorisation résiduelle — cohérent avec l'exemple chiffré §4.5 : FIFO
  et LIFO produisent des **valorisations résiduelles différentes** précisément parce que les couches
  restantes diffèrent, pas parce que la formule de valorisation elle-même change).
- **T = date passée** — `LotStock.quantiteRestante` est un état **courant**, pas historique.
  `valoriserADate()` reconstruit, pour chaque `LotStock` entré avant ou à T (`dateEntree ≤ T`) :
  `quantiteRestante(T) = quantiteInitiale − Σ(ImputationLotStock.quantiteImputee JOIN MouvementStock
  WHERE mouvementStock.date ≤ T)`, puis somme `quantiteRestante(T) × coutUnitaireHT`. Coût en lecture
  (`O(nombre de lots × nombre d'imputations)`, acceptable pour une consultation ponctuelle Comptable —
  pas d'agrégat matérialisé dans cette version, cf. Risque n°10 si le volume l'exige plus tard).

---

## 3. Intégration avec le compteur M1 (`Stock.disponibilité`)

### 3.1 Mouvements natifs `App\Stock` → écriture directe (réception, ajustement, transfert, inventaire)

`App\Stock\Service\DisponibiliteStockHandler` — **même patron exact** que
`App\Vente\Service\DecrementStockHandler` (code réel), en écriture directe sur `off_stock` via
`Doctrine\DBAL\Connection` (pas l'ORM, pour éviter tout conflit d'écrasement optimiste avec `Produit`) :

```php
// Incrément (réception, ajustement positif, entrée de transfert, régularisation positive) :
UPDATE off_stock SET disponibilite = disponibilite + :q WHERE id = UNHEX(:hex)

// Décrément (ajustement négatif, perte/casse, sortie de transfert, régularisation négative,
// retour fournisseur) : conditionnel, même garde que M2 (pas de disponibilité négative
// silencieuse), sauf ParametrageStock.autoriserStockNegatif (§3.3) :
UPDATE off_stock SET disponibilite = disponibilite - :q
WHERE id = UNHEX(:hex) AND (disponibilite >= :q OR :negatif_autorise = 1)
```

- Résolution de la cible : `ArticleStock.produit?.getStock()` (M1, `type = dedie` garanti par §0
  décision n°1) — si `ArticleStock.produit === null`, **aucune écriture** (article non catalogué, pas de
  compteur M1 à alimenter, traçabilité conservée côté `LotStock`/`MouvementStock` seuls).
- Appelé par : `ReceptionAchatValidationHandler` (RG-STOCK-05, CA-5), `AjustementStockHandler`
  (RG-STOCK-06/07), `TransfertStockHandler::expedier()/recevoir()` (RG-STOCK-14, CA-12),
  `InventaireRegularisationHandler` (RG-STOCK-12, CA-13), `ReintegrationRetourHandler` (§0 décision n°5).
- **Cohérence transactionnelle** : chaque handler crée `LotStock`/`MouvementStock`/`ImputationLotStock`
  (ORM, `persist()` sans `flush()`) **et** appelle `DisponibiliteStockHandler` (DBAL, exécution immédiate)
  **avant** que le Processor API Platform appelant ne fasse `$em->flush()` — la ligne DBAL s'exécute dans
  la **même transaction PDO** que le `flush()` Doctrine qui suit (comportement Doctrine/DBAL par défaut :
  une connexion, une transaction implicite ouverte au premier accès), garantissant qu'un rollback (ex.
  validation échouée plus loin dans le handler) annule aussi l'écriture DBAL.

### 3.2 Sortie de vente → observation, pas d'écriture (RG-STOCK-01/17, cœur de l'articulation demandée)

**`App\Stock\Doctrine\SortieVenteStockSubscriber`** (`#[AsDoctrineListener(event: Events::onFlush)]`,
même patron exact que `App\Audit\Doctrine\AuditWriteSubscriber`, code réel déjà présent dans ce dépôt) :

```php
public function onFlush(OnFlushEventArgs $args): void
{
    $em = $args->getObjectManager();
    $uow = $em->getUnitOfWork();

    foreach ($uow->getScheduledEntityUpdates() as $entity) {
        if (!$entity instanceof Vente) {
            continue;
        }
        $changeSet = $uow->getEntityChangeSet($entity);
        if (!isset($changeSet['statut']) || $changeSet['statut'][1] !== StatutVente::Validee) {
            continue; // ne traite que la transition EnCours → Validee (une seule fois, RG-M2-03 garantit qu'elle est unique).
        }
        foreach ($entity->getLignes() as $ligne) {
            $article = $this->repository->findOneBy(['produit' => $ligne->getProduit()]);
            if ($article === null) {
                continue; // produit non géré en stock (RG-M2-04) : rien à journaliser.
            }
            // §2.1 : consomme les couches actives selon la méthode active — NE TOUCHE JAMAIS
            // off_stock.disponibilite (déjà décrémentée par DecrementStockHandler avant ce flush,
            // cf. ValiderVenteService::valider(), code réel non modifié).
            $resultat = $this->moteur->consommerSelonMethode($article, (string) $ligne->getQuantite(), $methode);
            $mouvement = $this->creerMouvementSortieVente($article, $ligne, $resultat);
            $em->persist($mouvement);
            $uow->computeChangeSet($em->getClassMetadata(MouvementStock::class), $mouvement);
            foreach ($resultat->imputations as $imputation) {
                $ligneImputation = $this->creerImputation($mouvement, $imputation);
                $em->persist($ligneImputation);
                $uow->computeChangeSet($em->getClassMetadata(ImputationLotStock::class), $ligneImputation);
            }
            foreach ($resultat->lotsModifies() as $lot) {
                $uow->recomputeSingleEntityChangeSet($em->getClassMetadata(LotStock::class), $lot); // déjà managé, quantiteRestante modifiée
            }
        }
    }
}
```

- **Pourquoi `onFlush` et pas un événement métier dédié** — `ValiderVenteService::valider()` (code réel)
  ne dispatche **aucun** événement Symfony/Messenger observable ; le modifier créerait un couplage
  inverse interdit par la constitution (« ne pas modifier le socle/M2 »). Le hook `onFlush` observe
  strictement le **changement d'état persisté** (`Vente.statut → Validee`), sans dépendre d'un contrat
  que M2 n'expose pas — **même stratégie** que celle déjà retenue par `AuditWriteSubscriber` (audit) et
  par `InalterabiliteListener` (garde d'immuabilité) pour s'accrocher à `Vente` sans le modifier.
- **Non-double-écriture garantie** — `DecrementStockHandler::decrementer($vente)` s'exécute **avant**
  `$vente->setStatut(StatutVente::Validee)` dans `ValiderVenteService::valider()` (code réel lu, ligne
  61 puis ligne 74) ; si le stock M1 est insuffisant, une exception est levée **avant** le changement de
  statut et **avant** tout appel à `$em->flush()` par le Processor (`ValiderVenteProcessor::process()`,
  code réel : le `flush()` n'est atteint qu'après `$this->service->valider()` retourné sans exception) —
  donc **aucun `onFlush` n'est déclenché** pour une vente refusée par rupture (CA-10 : « sans qu'aucun
  mouvement de stock ne soit créé », garanti par construction, pas par un test défensif redondant).
- **Idempotence** — la transition `statut → Validee` ne peut survenir qu'une seule fois par `Vente`
  (`ValiderVenteService` refuse toute revalidation, `ConflictHttpException` si `statut !== EnCours`), donc
  le listener ne peut journaliser deux fois la même sortie.

### 3.3 Rupture, seuils, mode dégradé (RG-STOCK-09/10/11, cas limite §8 spec)

- **Blocage à rupture (CA-10)** — entièrement porté par le code M2 existant (`DecrementStockHandler`
  conditionnel, `RG-M2-04` côté panier) : **rien à ajouter** côté `App\Stock`, le module se contente de
  fournir une donnée source à jour (RG-STOCK-11, réutilisation stricte).
- **Alerte de réappro (RG-STOCK-10)** — `GET /stock/alertes-reappro` (provider de lecture, §4) : requête
  `ArticleStock` (`actif = true`) `JOIN Produit.stock` où `disponibiliteEffective() ≤ seuilMin`, quantité
  suggérée = `seuilMax − disponibilite`. Aucune entité d'alerte persistée (l'alerte « disparaît » de
  facto dès que la disponibilité repasse au-dessus du seuil, RG-STOCK-10).
- **⚠ Mode dégradé caisse hors-ligne (`RG-M2-08`, cas limite §8 spec point 2) — gap non résolu par ce
  plan, à documenter comme limite connue** : `DecrementStockHandler` (M2, inchangé) applique **toujours**
  sa garde conditionnelle stricte (`disponibilite >= q`), y compris à la **resynchronisation** d'une
  vente hors-ligne — il n'existe **aucun point d'extension actuel dans le code M2 réel** permettant à
  `ParametrageStock.autoriserStockNegatif` d'assouplir cette garde à la resynchro. `App\Stock` **définit**
  le paramètre (schéma §1.3) mais **ne peut pas l'appliquer** sans modification de M2 (hors périmètre de
  ce plan). cf. Risque n°3, priorité haute — à arbitrer avec M2 avant toute promesse fonctionnelle de
  « stock négatif exceptionnel toléré ».

---

## 4. API Platform

Toutes ressources `#[ApiResource]`, `security:` via `is_granted('PERM', 'stock.<action>')`. Cadrage
établissement : `ContexteEtablissement`/`PerimetreStockExtension` (même mécanique que L1/L2/L3/L4/L6).

| Ressource | Opérations | `security:` | Groupes | Filtres / Notes |
|---|---|---|---|---|
| **Fournisseur** | GetCollection, Get | `stock.lire` | `fournisseur:read` | — |
| | Post, Patch | `stock.gerer_fournisseur` | `fournisseur:write` | pas de `Delete` (désactivation via `actif`, §8 spec) |
| **CatalogueFournisseur** | GetCollection (par `articleStock` ou `fournisseur`), Get, Post, Patch, Delete | `stock.lire` / `stock.gerer_fournisseur` | `catalogue_fournisseur:*` | `ApiFilter(SearchFilter)` `articleStock`, `fournisseur` ; garde applicative « 1 seul principal » (CA-3) |
| **ArticleStock** | GetCollection, Get | `stock.lire` | `article:read` | `ApiFilter(SearchFilter)` `codeEAN` (exact), `libelle` (partial) ; `ApiFilter` seuil atteint via provider dédié (cf. alertes) |
| | Post, Patch | `stock.gerer_article` | `article:write` | création/édition (hors `produit`) |
| | `POST /stock/articles/{id}/rattacher-produit` | `stock.gerer_article` | — | custom processor, §0 décision n°1 (CA-1) |
| | `POST /stock/articles/{id}/detacher-produit` | `stock.gerer_article` | — | refuse si solde de lots > 0 |
| **ParametrageStock** | Get (par établissement) | `stock.lire` | `parametrage:read` | — |
| | Patch | `stock.parametrer` | `parametrage:write` | changement de méthode réservé Responsable (§2.2) |
| **CommandeAchat** + **LigneCommandeAchat** | GetCollection, Get | `stock.lire` | `commande_achat:read` | filtre `statut`, `fournisseur` |
| | Post/Patch (brouillon), sous-ressource lignes | `stock.gerer_achat` | `commande_achat:write` | CRUD lignes tant que `statut = brouillon` |
| | `POST .../envoyer`, `.../confirmer`, `.../annuler` | `stock.gerer_achat` | — | transitions §6, custom processors |
| **ReceptionAchat** + **LigneReceptionAchat** | GetCollection, Get, Post/Patch (brouillon) | `stock.receptionner` | `reception_achat:*` | CA-4/CA-5 |
| | `POST /stock/receptions-achat/{id}/valider` | `stock.receptionner` | — | déclenche `ReceptionAchatValidationHandler` (§3.1), CA-5 |
| **MouvementStock** | GetCollection, Get **(lecture seule, pas d'écriture directe)** | `stock.lire` | `mouvement:read` | `ApiFilter(SearchFilter)` `articleStock`, `type` ; `ApiFilter(DateFilter)` `date` |
| **ImputationLotStock** | GetCollection (par `mouvementStock`), Get | `stock.lire` | `imputation:read` | traçabilité FIFO/LIFO, audit |
| **LotStock** | GetCollection, Get | `stock.lire` | `lot:read` | filtre `articleStock`, `quantiteRestante > 0` (couches actives) |
| **`POST /stock/mouvements/ajustement`** (custom, non-ressource) | body `{articleStock, type ∈ {ajustement_positif, ajustement_negatif, perte_casse, retour_fournisseur}, quantite, motif, receptionOrigine?}` | `stock.ajuster` | — | `AjustementStockHandler`, motif requis (CA-7) |
| **`POST /stock/mouvements/reintegration-retour`** (custom) | body `{avoirId, articleStock, quantite}` | `stock.ajuster` | — | §0 décision n°5, CA-11, référence `Avoir` M2 en lecture seule |
| **TransfertStock** | GetCollection, Get, Post (`demande`) | `stock.transferer` | `transfert:*` | CA-12 |
| | `POST /stock/transferts/{id}/expedier` | `stock.transferer` (côté établissement source) | — | `sortie_transfert`, §3.1 |
| | `POST /stock/transferts/{id}/recevoir` | `stock.transferer` (côté établissement destination) | — | `entree_transfert`, coût = couche source consommée |
| **Inventaire** | GetCollection, Get, Post (lancement, snapshot théorique) | `stock.inventorier` | `inventaire:*` | CA-13 |
| | `PATCH /stock/lignes-inventaire/{id}` (saisie comptage) | `stock.inventorier` | `ligne_inventaire:write` | calcule `ecart`/`significatif` |
| | `POST /stock/lignes-inventaire/{id}/regulariser` | `stock.inventorier` (non significatif) **ou** `stock.valider_ecart` (significatif) | — | `InventaireRegularisationHandler`, §2.3 |
| | `POST /stock/inventaires/{id}/cloturer` | `stock.inventorier` | — | fige les lignes (append-only) |
| **`GET /stock/alertes-reappro`** (provider, non-ressource persistée) | — | `stock.lire` | `alerte_reappro:read` | §3.3, filtre `etablissement` |
| **`GET /stock/articles/{id}/valorisation`** | query `?date=` (défaut = maintenant) | `stock.lire_valorisation` | `valorisation:read` | §2.4, CA-14 (Comptable) |
| **`GET /stock/valorisation`** (agrégat établissement) | query `?etablissement=&date=` | `stock.lire_valorisation` | `valorisation:read` | somme par article |

- **Custom vs CRUD** : toute opération métier (rattachement produit, validation réception, ajustement,
  transfert, inventaire, valorisation) est un **State Processor/Provider** (handlers testables), pas du
  CRUD Doctrine brut — même logique que tous les plans précédents.
- **`MouvementStock`/`ImputationLotStock`/lignes d'inventaire clôturées** : **aucune** opération `Patch`/
  `Delete` exposée (append-only, §0 décision n°6).

---

## 5. Sécurité & droits

- **Permissions `stock.*`** (⚠ HYPOTHÈSE de nommage explicitement proposée par la spec §3, non
  littérale dans les sources — à figer avec M8, même réserve que tous les modules verticaux) :
  `gerer_article`, `gerer_fournisseur`, `gerer_achat`, `receptionner`, `ajuster`, `inventorier`,
  `transferer`, `lire`, `gerer` (administrateur, surensemble), `valider_ecart`, `parametrer`,
  `lire_valorisation` (Comptable, cf. RG-COMPTA-04 périmètre M6, §3 spec).
- **Voters** — aucun voter nouveau : `PermissionVoter` (socle) suffit, toutes les opérations sont
  scopées établissement (pas de notion `_soi`/client final dans ce module back-office).
- **Cadrage établissement** — `App\Stock\Doctrine\PerimetreStockExtension` (une classe, `match` sur
  `$resourceClass`, même patron que `PerimetreProduitExtension`) restreint `ArticleStock`,
  `CommandeAchat`, `ReceptionAchat`, `MouvementStock`, `LotStock`, `Inventaire`, `Fournisseur` par
  jointure `Affectation` (RG-SOCLE-05) ; `TransfertStock` par jointure sur `articleStockSource.
  etablissement OR articleStockDestination.etablissement` (§1.6).
- **Réutilisation** — `offre.lire` n'est **pas** requise pour consulter un `ArticleStock` (droit `stock.
  lire` autosuffisant, pas de dépendance croisée M1/App\Stock côté permissions, cohérent avec le fait que
  `Produit` reste géré exclusivement par `offre.*`).

---

## 6. Migrations

- **`VersionStock_structure`** : crée les 14 tables `stk_fournisseur`, `stk_catalogue_fournisseur`,
  `stk_article`, `stk_parametrage`, `stk_commande_achat`, `stk_ligne_commande_achat`,
  `stk_reception_achat`, `stk_ligne_reception_achat`, `stk_lot`, `stk_mouvement`, `stk_imputation_lot`,
  `stk_transfert`, `stk_inventaire`, `stk_ligne_inventaire`.
  - **Index/contraintes** : uniques `(fournisseur_id, article_stock_id)` (CatalogueFournisseur),
    `(etablissement_id, code_ean)` (ArticleStock), `produit_id` (ArticleStock, nullable-unique),
    `commande_achat.numero`, `reception_achat.ligne_creee` (`LigneReceptionAchat.lotCree`, unique),
    `transfert.mouvement_sortie`/`mouvement_entree` (unique), `ligne_inventaire.mouvement_regularisation`
    (unique) ; **CHECK** `stk_lot.quantite_restante <= stk_lot.quantite_initiale` (MariaDB 11.4, CHECK
    natif) ; **CHECK** `stk_article.seuil_min <= stk_article.seuil_max` ; index composites
    `(article_stock_id, date_entree)` (LotStock, ordre FIFO/LIFO), `(article_stock_id, date)`
    (MouvementStock, requêtes de valorisation historique §2.4). FK sortantes vers `App\Offre\Entity\
    Produit` (M1), `App\Securite\Entity\Utilisateur` (socle), `App\Organisation\Entity\Etablissement`
    (socle) — **suppose les migrations socle L0 + M1 (L1) + M2 (L2) jouées d'abord**.
- **`VersionStock_permissions`** : insère `Permission(module='stock', action ∈ {gerer_article,
  gerer_fournisseur, gerer_achat, receptionner, ajuster, inventorier, transferer, lire, gerer,
  valider_ecart, parametrer, lire_valorisation})` — **rejouable, idempotente** (vérifie l'existence avant
  insertion, même garde que toutes les migrations de permissions L1-L7/verticales).
- **Modification de fichier partagé (hors migration DB, additive)** — ajout de `MouvementStock`,
  `LotStock`, `CommandeAchat`, `ReceptionAchat`, `Inventaire`, `TransfertStock`, `Fournisseur` à
  `App\Audit\Doctrine\AuditWriteSubscriber::CLASSES_SURVEILLEES` (T13, même patron additif que Boutique/
  Musée/Padel/Sport). **Aucune modification** de `App\Vente\*`/`App\Offre\*` : le listener
  `SortieVenteStockSubscriber` et le service `DisponibiliteStockHandler` sont des fichiers **neufs**
  côté `App\Stock`, injectés/tagués par la configuration standard Symfony (`#[AsDoctrineListener]`,
  autoconfiguration), sans toucher `config/services.yaml` au-delà de l'auto-registration habituelle.
- Rejouables, versionnées Doctrine ; jamais de `schema:update --force`.

---

## 7. Tests (PHPUnit + ApiTestCase)

| Test | Type | Couvre |
|---|---|---|
| EAN valide/invalide : clé de contrôle EAN-13/EAN-8 refusée si incorrecte ; code déjà utilisé sur le même établissement refusé ; rattachement à un Produit facette `stock` → `Stock.disponibilité` piloté | API + Unit (`CodeEanValide`) | CA-1, RG-STOCK-01/02 |
| Produit sans ArticleStock jamais bloqué (réutilise le test M2 existant) ; ArticleStock sans Produit absent de tout catalogue vendable | API | CA-2, RG-STOCK-01 |
| Catalogue fournisseur : 3 fournisseurs sur un article, un seul `principal=true`, refus d'un second principal | API | CA-3, RG-STOCK-03 |
| Commande envoyée : disponibilité inchangée avant réception | API | CA-4, RG-STOCK-04 |
| Réception partielle 60/100 à prix différent (4,10 € vs 4,00 €) : `LotStock` créé, `Stock.disponibilité +60`, commande `partiellement_reçue` ; complément → `reçue` | API + Unit (`ReceptionAchatValidationHandler`) | CA-5, RG-STOCK-05 |
| Retour fournisseur 10 unités sur lot connu : mouvement motivé, lot d'origine `-10`, disponibilité `-10` | API + Unit (`consommerLotPrioritaire`) | CA-6, RG-STOCK-06 |
| Perte/casse sans motif refusée (422) ; avec motif créée, non supprimable ensuite (`preRemove` levé) | API + Unit (`MouvementStockInalterabiliteListener`) | CA-7, RG-STOCK-07/15 |
| **FIFO chiffré** — Lot A 100u@4,00€ (01/03), Lot B 50u@4,50€ (15/03), vente 120u (20/03) → coût sortie **490,00 €**, Lot A épuisé, Lot B **30u restantes = 135,00 €** | Unit (`MoteurValorisationFifoLifo::consommerSelonMethode`) | CA-8, RG-STOCK-08 |
| **LIFO chiffré** — mêmes lots, méthode LIFO, même vente 120u → coût sortie **505,00 €**, Lot B épuisé, Lot A **30u restantes = 120,00 €** | Unit (idem) | CA-9, RG-STOCK-08 |
| Rupture : disponibilité=0 → ajout panier refusé (réutilise test M2), **aucun `MouvementStock` créé** (assertion négative sur le repository) ; seuil min franchi → alerte réappro avec quantité suggérée = seuilMax − dispo | API + Unit (`GET /stock/alertes-reappro`) | CA-10, RG-STOCK-17/09/10 |
| Vente validée ligne qty=3 → `MouvementStock(sortie_vente)` auto créé référencé à la `LigneVente`, coût selon méthode active ; avoir + confirmation retour bon état → `ajustement_positif` réintègre ; sans confirmation → aucune réintégration | API + intégration (`App\Vente`, listener `onFlush`) | CA-11, RG-STOCK-17, §3.2 |
| Transfert 20u A→B : expédition décrémente A (couche selon méthode A), réception crée à B une nouvelle couche au coût de la couche consommée à la source (pas un nouveau prix d'achat) | API + Unit | CA-12, RG-STOCK-14 |
| Inventaire : théorique=50 figé au lancement, compté=46 → écart=-4 ; si > seuil significativité → validation Responsable requise ; régularisation → `ajustement_négatif` 4u au coût de la dernière couche active, disponibilité→46 ; clôture → lignes non modifiables | API + Unit (`InventaireRegularisationHandler`) | CA-13, RG-STOCK-12 |
| Valorisation à date T avec plusieurs couches actives = Σ(quantité restante × coût unitaire) reconstruite à T (test avec mouvements postérieurs à T exclus) | Unit (`valoriserADate`) | CA-14, RG-STOCK-18 |
| Cloisonnement établissement : agent sans affectation → 403/absent sur toutes les ressources `stock.*` ; `TransfertStock` visible des deux côtés uniquement par les affectés | API | RG-SOCLE-05, RG-STOCK-13 |
| Non-double-décrément : une vente validée ne modifie `off_stock.disponibilite` **qu'une seule fois** (assertion avant/après flush, vérifie que `SortieVenteStockSubscriber` ne réécrit jamais la colonne) | Unit (intégration listener + `DecrementStockHandler`) | §3.2, non-régression M2 |
| Architecture : aucun fichier `App\Offre\*`/`App\Vente\*`/`App\Compta\*` modifié par ce lot (contrôle statique diff), seule `AuditWriteSubscriber::CLASSES_SURVEILLEES` étendue | Unit (architecture) | non-régression socle/modules réutilisés |

---

## 8. Tâches (voir tasks-stock.md)

T1 enums + `Fournisseur`/`CatalogueFournisseur` (+ garde « un seul principal ») → T2 `ArticleStock` +
`CodeEanValide` + `RattacherArticleAuProduitHandler` (§0 n°1) → T3 `ParametrageStock` +
`ResolveurMethodeValorisation` → T4 `LotStock`/`MouvementStock`/`ImputationLotStock` +
`MouvementStockInalterabiliteListener` (append-only) → T5 `MoteurValorisationFifoLifo` (consommation
FIFO/LIFO + valorisation courante, tests chiffrés CA-8/CA-9 en priorité) → T6 `DisponibiliteStockHandler`
(DBAL incrément/décrément, §3.1) → T7 `CommandeAchat`/`LigneCommandeAchat` + transitions de statut → T8
`ReceptionAchat`/`LigneReceptionAchat` + `ReceptionAchatValidationHandler` (création lot + mouvement +
maj disponibilité + statut commande) → T9 `AjustementStockHandler` (ajustement/perte/retour fournisseur,
motif requis) → T10 `SortieVenteStockSubscriber` (`onFlush`, §3.2, tests non-double-décrément en
priorité) → T11 `ReintegrationRetourHandler` (§0 n°5) → T12 `TransfertStock` +
`TransfertStockHandler` (expédier/recevoir, coût transféré) → T13 `Inventaire`/`LigneInventaire` +
`InventaireRegularisationHandler` (§2.3) → T14 `valoriserADate` (reconstruction historique) + endpoints
valorisation → T15 `GET /stock/alertes-reappro` → T16 audit (extension `CLASSES_SURVEILLEES`) +
permissions `stock.*` + `PerimetreStockExtension` → T17 API Platform (ressources restantes, filtres) →
T18 migrations → T19 tests (ordonnées, cf. fichier tâches).

---

## 9. Risques / à valider

1. **⚠ GAP majeur (acté, non traité par ce plan)** — aucune écriture comptable de variation de stock
   n'est générée par `App\Stock`. `spec-compta.md`/M6 exclut explicitement la « comptabilité fournisseurs
   générale » et ne décrit aucune écriture débit/crédit de stock. Ce plan **calcule** une valorisation
   consultable (`stock.lire_valorisation`) mais **aucun mécanisme de génération automatique d'écriture**
   n'est implémenté — point d'extension futur (un `ProjectionValorisationInterface` côté M6 pourrait
   consommer `valoriserADate()`), à cadrer avec un **expert-comptable** avant toute intégration M6.
2. **⚠ Pool partagé M1 non traité (acté)** — `ArticleStock` ne se rattache qu'à un `Stock` `dedie`
   (§0 décision n°1) ; le rattachement à un `Stock` `partage`/`Pool` (mutualisé entre plusieurs variantes
   d'un même produit) est **explicitement refusé** (422) dans cette version — extension future si confirmé
   par le métier, complexifierait l'imputation FIFO/LIFO par variante (spec §8).
3. **⚠ Mode dégradé caisse hors-ligne / stock négatif (priorité haute)** — `ParametrageStock.
   autoriserStockNegatif` est **défini mais sans point d'ancrage réel** dans `DecrementStockHandler` (M2,
   non modifiable par ce plan) : la resynchronisation d'une vente hors-ligne appliquera **toujours** la
   garde stricte M2 (`disponibilite >= q`), pouvant refuser une vente déjà actée côté caisse physique. À
   arbitrer avec M2 — nécessitera probablement une évolution du contrat `DecrementStockHandler` (hors
   périmètre de ce plan, constitution : ne pas modifier le socle sans mandat).
4. **⚠ Schéma M1 `Produit.stock` singulier vs `Produit.etablissements` multiple (§0 décision n°2)** — ce
   plan assume **un `Produit` M1 par établissement** pour tout article physique ; un `Produit` M1
   réellement partagé entre sites avec des stocks physiques distincts par site **n'est pas représentable**
   avec le schéma M1 actuel (une seule relation `Stock`). À vérifier auprès des équipes M1/produit avant
   un déploiement multi-sites d'un même catalogue boutique.
5. **⚠ Cohérence `Stock.disponibilité` vs. Σ(LotStock.quantiteRestante)** — ces deux compteurs sont
   maintenus par **deux mécanismes distincts** (M2/DBAL pour la disponibilité, `App\Stock`/ORM pour les
   couches) ; en fonctionnement normal ils restent synchronisés par construction (§3.2), mais aucune
   tâche de réconciliation périodique (`stock:verifier-coherence`) n'est prévue dans cette version pour
   détecter une dérive (bug, incident, mode dégradé §3.3) — à envisager en tâche d'exploitation future.
6. **⚠ Verrouillage FIFO/LIFO en cours d'exercice (§2.2, HYPOTHÈSE spec §4.5)** — changement de méthode
   non rétroactif, réservé au Responsable ; impact sur la comparabilité de la valorisation dans le temps
   **non détaillé** — à valider avec un comptable avant activation en production.
7. **⚠ Écart d'inventaire négatif dont la dernière couche active ne suffit pas (§2.3)** — repli sur
   l'ordre FIFO/LIFO général retenu par ce plan, **non tranché explicitement** par la spec (RG-STOCK-12
   ne prévoit que « la dernière couche active », silence sur l'insuffisance) — à confirmer.
8. **⚠ `Fournisseur` rattaché à un Établissement, pas un Groupe** — la spec §5 omet le champ
   `établissement` dans le tableau `Fournisseur`/`CatalogueFournisseur` alors qu'elle l'impose partout
   ailleurs ; ce plan retient par défaut le rattachement établissement (cohérent avec le reste du module)
   mais une mutualisation groupe (un fournisseur livrant plusieurs sites d'un même groupe) serait
   probablement plus réaliste opérationnellement — à arbitrer, impact modéré (migration de données
   simple si tranché plus tard).
9. **US-STOCK-01 à 12 / RG-STOCK-01 à 18 non validées/numérotées officiellement** dans le backlog (spec
   préambule, aucune source `backlog.html`/`cahier-detaille.html`) — ajustement mineur de nommage/
   numérotation possible sans impact structurel attendu sur ce plan.
10. **⚠ Valorisation historique (`valoriserADate`) sans agrégat matérialisé** — reconstruction à la
    volée par jointure `ImputationLotStock`/`MouvementStock` (§2.4) ; performant pour un volume de lots/
    mouvements modéré par article, mais **non testé à l'échelle** — si le volume de mouvements par
    établissement s'avère élevé (forte rotation boutique musée/piscine), un job de snapshot périodique
    pourrait devenir nécessaire (hors périmètre de cette version, optimisation différée).
11. **Noms des permissions `stock.*` proposés selon le modèle socle** (§5), à figer avec **M8** comme pour
    tous les autres modules de ce dépôt.
12. **Sur-réception (quantité livrée > quantité commandée) acceptée par défaut** (§8 spec, hérité tel
    quel) — aucun contrôle bloquant implémenté, un paramètre d'alerte pourrait être ajouté ultérieurement.
