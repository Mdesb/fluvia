# Spec — Stock & Inventaire boutique (`App\Stock`)

- **Lot / module :** Module transverse **`App\Stock`** (non positionné dans l'ordre L0→L7 de la
  constitution §5 ; consommé par **L2 · M2 Vente & Caisse**, **L8 · M3 Boutique en ligne**, et les
  verticales à boutique physique — **Musée**, **Patinoire**, etc.)
- **Stories couvertes :** **US-STOCK-01 à US-STOCK-12** — ⚠ **HYPOTHÈSE / ABSENCE DE SOURCE OFFICIELLE** :
  `backlog.html` ne comporte **aucun onglet ni story `US-STOCK-*`** ; `cahier-detaille.html` ne
  comporte **aucun panel dédié à un module « Stock & Inventaire »** au sens demandé ici (fournisseurs,
  commandes d'achat, valorisation FIFO/LIFO, inventaire physique). Le cahier ne connaît que le **Stock
  M1** (`RG-M1-10`, cf. §2.2 et §4.1 ci-dessous), un **compteur de disponibilité** simple (dédié ou
  partagé/pool), et deux usages verticaux ponctuels du mot « stock » : la **location de patins par
  pointure** (`specs/patinoire/spec-patinoire.md`, `RG-PAT-01/06`) et l'**inventaire OTA contrôlé**
  (`specs/musee/spec-musee.md` §4.7, `RG-MUS-04`, `specs/L8-boutique/spec-boutique.md` §4.14,
  `RG-M3-09`) — deux notions **différentes** de l'inventaire physique de marchandises visé ici. Les 12
  stories ci-dessous sont **définies par cet agent**, à la demande explicite du commanditaire (musées,
  boutiques), sur le modèle déjà appliqué à `spec-boutique.md` (US-L8-\*) et `spec-musee.md`
  (US-MUSEE-\*). **À faire valider, numéroter et chiffrer officiellement** dans le backlog avant
  développement.
- **Règles de gestion :** **RG-STOCK-01 à RG-STOCK-18** (**nouvelles**, aucune ne préexiste dans le
  cahier ; cf. avertissement ci-dessus) + règles socle/modules **réutilisées, non redéfinies** :
  `RG-SOCLE-01` à `07` (`spec-socle.md`), `RG-M1-02/05/09/10` (`spec-offre.md`), `RG-M2-04/07`
  (`spec-vente.md`), `RG-M3-03/09` (`spec-boutique.md`), `RG-MUS-04` (`spec-musee.md`),
  `RG-COMPTA-04`/`RG-M6-04` (`spec-compta.md`).
- **Statut :** brouillon — module **entièrement défini par cet agent** en l'absence de source cahier ;
  plusieurs points `⚠ À VALIDER PAR EXPERT` (comptable, sur l'articulation valorisation ↔ écritures) et
  `⚠ HYPOTHÈSE` (verrouillage de méthode, stock négatif hors-ligne) à trancher avant figement.

## 1. Objectif
Donner à un magasinier/gestionnaire de boutique (musée, piscine, patinoire…) la **traçabilité complète
d'une marchandise physique** — du fournisseur jusqu'au client — avec un **code-barres EAN** pour la
retrouver instantanément en caisse, une **valorisation FIFO ou LIFO** fidèle au coût réel des lots
achetés, des **alertes de réapprovisionnement** avant la rupture, et un **inventaire physique**
rapprochable à tout instant, **sans jamais dupliquer** le compteur de disponibilité déjà porté par le
produit M1 que M2/M3 consomment pour bloquer une vente.

## 2. Périmètre

### 2.1 [RÉUTILISE SOCLE/M1/M2] — paramétré, non redéfini ici
- **Produit vendable, catalogue, tarifs, canaux, cycle de publication** → **M1**
  (`specs/L1-offre/spec-offre.md`). Un **article de boutique physique = un Produit M1** (type
  « Boutique », facette `stock`, `RG-M1-02`), dont le **prix de vente** est porté par la **grille
  tarifaire M1** (`RG-M1-01`), **jamais** par `App\Stock`.
- **Le compteur `Stock.disponibilité` (dédié/partagé) consommé par M2/M3 pour bloquer une vente à
  quantité 0** → **M1** (`RG-M1-10`), **réutilisé tel quel**, non redéfini. `App\Stock` **alimente** ce
  compteur en temps réel (§4.1) ; il n'existe **qu'une seule valeur de disponibilité observable** par
  Produit × Établissement.
- **L'acte de vente, la décrémentation déclenchée par une ligne de vente, le blocage caisse à stock
  épuisé** → **M2** (`spec-vente.md`, `RG-M2-04`) et **M3** (`spec-boutique.md`, `RG-M3-03/08`).
  `App\Stock` **fournit** le mouvement de sortie et le coût, **ne redéfinit pas** le panier/la caisse.
- **Écritures comptables, plan de comptes, TVA, PCA** → **M6** (`spec-compta.md`). `App\Stock` **calcule**
  une valorisation ; l'**écriture comptable** éventuelle (variation de stock) reste **propriété M6**
  (cf. §8, point ouvert).

### 2.2 [SPÉCIFIQUE `App\Stock`] — inclus dans cette spec
- **Article de stock** avec code-barres **EAN-13/EAN-8**, unité, prix d'achat (HT + TVA d'achat) —
  US-STOCK-01, RG-STOCK-02.
- **Fournisseurs** : coordonnées, conditions, catalogue fournisseur (référence, prix négocié, délai) —
  US-STOCK-02, RG-STOCK-03.
- **Commande d'achat → réception** (entrée en stock au prix d'achat réel), **retours fournisseur** —
  US-STOCK-03/04/05, RG-STOCK-04/05/06.
- **Mouvements de stock typés** (entrée, sortie, ajustement, perte/casse, transfert, inventaire) —
  US-STOCK-06, RG-STOCK-07/15.
- **Valorisation FIFO / LIFO paramétrable**, couches de coût (lots), coût de sortie calculé selon la
  méthode ; valorisation du stock à une date — US-STOCK-07/12, RG-STOCK-08/18.
- **Décrément automatique à la vente** (M2 guichet, M3 boutique en ligne) — US-STOCK-09, RG-STOCK-01/17.
- **Seuils min/max, alertes de réapprovisionnement**, stock disponible vs réservé — US-STOCK-08,
  RG-STOCK-09/10/11.
- **Transfert inter-établissements** — US-STOCK-10, RG-STOCK-13/14.
- **Inventaire physique** : comptage, écarts, régularisation — US-STOCK-11, RG-STOCK-12.

### 2.3 Exclu (pour l'instant), que `App\Stock` *référence* seulement
- La **définition du produit vendable et de son prix** (libellé, canaux, statut de publication, grille
  tarifaire, promotions) → **M1** (`spec-offre.md`). `App\Stock` **rattache** un `ArticleStock` à un
  `Produit`, ne le redéfinit pas.
- **Le Stock/Pool de capacité M1** (`RG-M1-10`) — quota de compostages d'une carte multi-entrées, jauge
  d'un créneau réservation, capacité mutualisée entre variantes — **n'est pas un stock physique de
  marchandises** ; `App\Stock` ne s'y substitue pas et ne le redéfinit pas (cf. §8, point ouvert
  d'articulation, réponse apportée en §4.1).
- **L'acte de vente, la caisse, le panier, le paiement, le ticket, l'avoir** → **M2**
  (`spec-vente.md`). `App\Stock` **reçoit** l'événement « vente validée » et **produit** le mouvement de
  sortie correspondant ; il ne compose ni n'encaisse rien.
- **La réservation temporaire de stock en ligne** (panier boutique, timed-entry) → **M3**
  (`spec-boutique.md`, `RG-M3-03`). `App\Stock` **expose** un compteur « réservé » consommé par ce
  mécanisme (§4.4) ; il ne réimplémente pas le panier en ligne.
- **La comptabilité fournisseurs générale** (factures fournisseur, échéancier de paiement, lettrage des
  règlements aux fournisseurs) — **explicitement hors périmètre de M6** (cahier §M6 « Hors périmètre »,
  cf. citation §8) et **hors périmètre de `App\Stock`** : ce module **valorise** l'entrée en stock au
  prix d'achat, il ne gère **ni la facture fournisseur, ni son règlement**. ⚠ **GAP** — aucun module de
  ce dépôt ne couvre à ce jour la comptabilité fournisseurs ; à créer si le besoin est confirmé.
- **La production/logistique de supports physiques non marchands** (cartes/bracelets vierges pour le
  contrôle d'accès, matériel loué comme les patins de patinoire) → traitée par les verticales concernées
  (`spec-patinoire.md` `ParcPatins`, hors gestion d'achat/valorisation FIFO-LIFO) ; `App\Stock` **pourrait**
  à terme servir de socle générique à ces parcs spécialisés, mais **ne les redéfinit pas** ici (cf. §8).
- **L'UI (front)** ; l'**authentification, les rôles/permissions et le journal d'audit** → **socle L0**
  (`spec-socle.md`), réutilisés et non redéfinis.

## 3. Acteurs & droits
Les permissions réutilisent le modèle du socle (`RG-SOCLE-02/03/04/05`) : couple `module × action` sur
le module **`stock`**, portées par l'**établissement actif** ; l'UI **masque** ce qui n'est pas autorisé
(`RG-SOCLE-04`).

| Acteur | Peut | Ne peut pas | Permission (module × action) |
|---|---|---|---|
| **Magasinier / Gestionnaire de stock** | Créer/éditer un `ArticleStock` (EAN, unité, prix d'achat) et le rattacher à un Produit M1 ; gérer les fournisseurs et le catalogue fournisseur ; créer/envoyer une commande d'achat ; réceptionner (totale/partielle) ; enregistrer un retour fournisseur ; saisir un ajustement/perte/casse (motif requis) ; lancer et compter un inventaire ; créer un transfert inter-établissements ; consulter les alertes de réappro | Modifier le prix de vente ou le statut de publication du Produit (réservé à M1) ; valider un écart d'inventaire significatif (droit dédié, cf. Responsable) ; changer la méthode de valorisation d'un établissement déjà mouvementé | `stock × gerer_article`, `stock × gerer_fournisseur`, `stock × gerer_achat`, `stock × receptionner`, `stock × ajuster`, `stock × inventorier`, `stock × transferer`, `stock × lire` |
| **Agent de caisse / boutique** *(réutilise `vente`, cf. `spec-vente.md` §3)* | Consulter la disponibilité et le prix (lecture) ; rechercher un article par code-barres à l'écran de caisse (réutilise `RG-M2` §4.2) | Créer un mouvement de stock manuel ; réceptionner un achat | `stock × lire` (hérité de `offre × lire`) |
| **Responsable de stock / Administrateur** | Tout ce qui précède + **valider un écart d'inventaire significatif** avant régularisation ; **changer la méthode de valorisation** (FIFO ↔ LIFO) d'un établissement ; **autoriser le stock négatif exceptionnel** (paramètre sensible, journalisé) ; clôturer un inventaire | — | `stock × gerer` (surensemble), `stock × valider_ecart`, `stock × parametrer`, `securite × gerer` (socle, délégation) |
| **Comptable** *(réutilise `compta`, cf. `spec-compta.md` §3)* | Consulter la **valorisation du stock à une date** (FIFO/LIFO), par article/établissement, en vue d'une éventuelle écriture de variation de stock | Créer/modifier un mouvement de stock | `stock × lire_valorisation` ⚠ HYPOTHÈSE (permission fine proposée, à arbitrer avec M8/M6) |
| **Système** | Décrémenter le stock à chaque vente validée (M2/M3) ; réintégrer le stock sur avoir confirmé « retour physique bon état » ; générer les alertes de réappro ; libérer une réservation de panier en ligne expirée (réutilise `RG-M3-03`) | Décider hors des règles paramétrées | *(acteur technique — pas de permission humaine)* |

- ⚠ HYPOTHÈSE — Les noms de permissions `stock × …` sont **proposés** par cet agent selon le modèle
  `module × action` du socle (aucune source ne les nomme, le module lui-même étant hors cahier) ; à
  **arbitrer avec M8** comme pour tous les autres modules de ce dépôt.
- ⚠ HYPOTHÈSE — Le seuil de « significativité » d'un écart d'inventaire déclenchant une **validation
  Responsable** avant régularisation automatique (plutôt qu'une régularisation directe par le
  Magasinier) n'est fixé par aucune source ; retenu comme **paramétrable par établissement** (ex. écart
  > X % ou > Y € de valorisation), cohérent avec l'absence de logique codée en dur (constitution §4.4).

## 4. Comportements & règles
Chaque comportement trace une **RG-STOCK** (nouvelle, définie par cet agent, cf. avertissement d'en-tête)
et/ou une **US-STOCK** (définie §1).

### 4.1 Article de stock, code-barres EAN & articulation avec le Stock M1 (US-STOCK-01, RG-STOCK-01/02)
- **RG-STOCK-01 (articulation avec M1 — point central)** — Le **Stock M1** (`RG-M1-10`, objet `Stock` de
  `spec-offre.md` §5) est un **compteur de disponibilité** générique (dédié ou partagé/pool), utilisé
  aussi bien pour des compostages de carte, un quota de créneau ou une marchandise. `App\Stock`
  **spécialise** ce compteur pour le cas « marchandise physique » : un `ArticleStock` est **rattaché
  1:1 au maximum** à un `Produit` M1 de type « Boutique » (facette `stock`, `RG-M1-02`) via son objet
  `Stock` (type **dédié**). **Chaque `MouvementStock` recalcule immédiatement `Stock.disponibilité`**
  (`RG-M1-10`) du produit concerné : il n'existe **jamais deux compteurs divergents** — M2/M3 continuent
  de lire et de décrémenter **le même** `Stock.disponibilité` qu'aujourd'hui (`RG-M2-04`, `RG-M3-08`),
  `App\Stock` en devient simplement la **source de vérité détaillée** (lots, coûts, mouvements,
  fournisseur d'origine) derrière ce compteur unique.
- Un **Produit M1 sans `ArticleStock`** reste possible (produit de type « Boutique » **non géré en
  stock**, cf. `RG-M2-04` : jamais bloqué) ; un **`ArticleStock` sans `Produit` rattaché** n'est **pas
  vendable** (article en attente de catalogage, ou composant interne).
- **RG-STOCK-02** — Un `ArticleStock` porte un **code-barres EAN-13 ou EAN-8** (validation de la clé de
  contrôle du code), **unique par établissement** (⚠ HYPOTHÈSE : unicité par établissement retenue par
  cohérence avec le cloisonnement multi-entités du socle, `RG-SOCLE-01` ; une unicité **groupe** serait
  alternative si un même article référence plusieurs boutiques d'un même groupe — à confirmer), une
  **unité** (pièce, kg, litre, paquet…), un **prix d'achat** (HT + taux de TVA d'achat, distinct du taux
  de TVA de vente porté par M1/M6) et une **méthode de valorisation** héritée du paramétrage de
  l'établissement (§4.5), avec possibilité de **surcharge par article** ⚠ HYPOTHÈSE.
- **Recherche caisse** — Le code-barres EAN est le champ de **recherche instantanée** déjà prévu côté
  écran de caisse M2 (« recherche par libellé, code ou **code-barres** », `spec-vente.md` §4.2) ;
  `App\Stock` **fournit** la correspondance EAN → Produit, ne redéfinit pas l'écran.

### 4.2 Fournisseurs & catalogue fournisseur (US-STOCK-02, RG-STOCK-03)
- **RG-STOCK-03** — Un `Fournisseur` porte coordonnées, **conditions** (délai de paiement, minimum de
  commande), et un **catalogue fournisseur** : pour chaque `ArticleStock`, une **référence fournisseur**,
  un **prix d'achat négocié**, un **délai de livraison** et un **fournisseur principal** désignable parmi
  plusieurs.
- Un article **sans fournisseur renseigné** peut néanmoins être réceptionné manuellement (achat ponctuel
  hors catalogue) ; le prix d'achat de la ligne de réception fait alors foi (§4.3).

### 4.3 Commande d'achat → réception, retours (US-STOCK-03/04/05, RG-STOCK-04/05/06)
- **RG-STOCK-04** — Une `CommandeAchat` suit le cycle : `brouillon → envoyée → confirmée →
  (partiellement_reçue) → reçue → clôturée`, ou `annulée` (avant réception). **Aucun mouvement de
  stock** n'est généré avant réception : la commande **n'engage** que la quantité, pas la disponibilité
  affichée en caisse.
- **RG-STOCK-05** — Une `ReceptionAchat` **entre en stock** au **prix d'achat réel** de la ligne reçue
  (qui peut **différer** du prix initialement commandé) ; elle crée une **nouvelle couche de coût**
  (`LotStock`, §4.5) pour chaque ligne. Une **réception partielle** est possible : la commande reste
  `partiellement_reçue` tant que `quantitéReçue < quantitéCommandée` sur au moins une ligne ; une
  nouvelle réception peut compléter la même commande jusqu'à `reçue`.
- **RG-STOCK-06** — Un **retour fournisseur** génère un `MouvementStock` de type `retour_fournisseur`
  (sortie motivée), qui référence la `ReceptionAchat` d'origine si connue et **retire prioritairement**
  dans le **lot concerné** (indépendamment de la méthode FIFO/LIFO active, qui ne s'applique qu'aux
  sorties de vente) ; à défaut de lot identifiable, retrait selon la méthode active de l'établissement.

### 4.4 Mouvements de stock — typologie fermée (US-STOCK-06, RG-STOCK-07/15)
- **RG-STOCK-07** — Tout mouvement porte un **type** parmi un ensemble **fermé** : `entrée_achat`
  (réception), `sortie_vente` (M2/M3), `retour_fournisseur`, `ajustement_positif`, `ajustement_négatif`,
  `perte_casse`, `sortie_transfert`, `entrée_transfert`, `régularisation_inventaire`. Un **motif** est
  **obligatoire** pour tout mouvement autre que `entrée_achat`/`sortie_vente` (traçabilité).
- **RG-STOCK-15** — **Aucun mouvement n'est supprimable ni modifiable** une fois enregistré
  (**append-only**, cohérent `RG-SOCLE-07`) : toute correction se fait par un **nouveau mouvement
  compensatoire** (ajustement), jamais par édition/suppression du mouvement d'origine.
- **Stock disponible vs réservé (RG-STOCK-09)** — `Stock.disponibilité` M1 (§4.1) affiche la
  **disponibilité vendable** = quantité physique en stock **moins** la quantité **réservée** (paniers en
  ligne en attente de paiement, `RG-M3-03`). `App\Stock` **expose** ce compteur de réservation temporaire
  à M3, sans le redéfinir.

### 4.5 Valorisation FIFO / LIFO (US-STOCK-07/12, RG-STOCK-08/18)
- **RG-STOCK-08** — La **méthode de valorisation** (FIFO — *first in, first out* — ou LIFO — *last in,
  first out*) est **paramétrable par établissement** (défaut hérité du groupe, `⚠ HYPOTHÈSE` surcharge
  possible par article, §4.1). Chaque entrée en stock (réception, régularisation positive, entrée de
  transfert) crée une **couche de coût** (`LotStock`) : quantité initiale, coût unitaire HT, date
  d'entrée. Chaque **sortie** (vente, perte/casse, ajustement négatif, sortie de transfert) **consomme**
  les couches actives **dans l'ordre défini par la méthode** :
  - **FIFO** — la couche la **plus ancienne** (date d'entrée la plus faible) est consommée en premier.
  - **LIFO** — la couche la **plus récente** (date d'entrée la plus élevée) est consommée en premier.
  - Le **coût de sortie** de chaque mouvement = **somme** des `quantité consommée × coût unitaire de la
    couche`, pour chaque couche traversée (une sortie peut traverser **plusieurs couches** si la première
    couche disponible ne suffit pas) ; le détail (quelle couche, quelle quantité, quel coût) est
    **conservé** sur le mouvement pour audit.
- **RG-STOCK-18** — La **valorisation du stock à une date T** d'un article = **somme, sur toutes les
  couches actives à T**, de `quantité restante × coût unitaire de la couche`. Elle est **consultable à
  tout instant** par le Comptable (`stock × lire_valorisation`).
- ⚠ HYPOTHÈSE — **Verrouillage de la méthode** : le cahier ne fixant rien, cette spec retient par défaut
  qu'un **changement de méthode** (FIFO ↔ LIFO) sur un établissement **déjà mouvementé** est **possible
  mais réservé au Responsable/Administrateur** (`stock × parametrer`) et ne **s'applique qu'aux
  mouvements futurs** (les couches déjà consommées ne sont pas recalculées rétroactivement) ; à
  **confirmer avec le métier/un comptable** avant figement (impact sur la comparabilité d'une
  valorisation dans le temps).

**Exemple chiffré — FIFO** *(illustratif, non normatif)* :
Article « Mug musée » — deux réceptions :
- Lot A, reçu le 01/03 : **100 unités** à **4,00 € HT**.
- Lot B, reçu le 15/03 : **50 unités** à **4,50 € HT**.

Le 20/03, une vente de **120 unités** est validée. En **FIFO**, la sortie consomme d'abord le Lot A
(le plus ancien) :
- 100 unités × 4,00 € = **400,00 €** (Lot A épuisé)
- 20 unités × 4,50 € = **90,00 €** (Lot B entamé)
- **Coût de sortie total = 490,00 €** (coût moyen de sortie ≈ 4,0833 €/unité)
- **Stock restant** = 30 unités du Lot B → **valorisation restante = 30 × 4,50 = 135,00 €**.

**Exemple chiffré — LIFO** *(même article, mêmes lots, même vente de 120 unités)* :
En **LIFO**, la sortie consomme d'abord le Lot B (le plus récent) :
- 50 unités × 4,50 € = **225,00 €** (Lot B épuisé)
- 70 unités × 4,00 € = **280,00 €** (Lot A entamé)
- **Coût de sortie total = 505,00 €** (coût moyen de sortie ≈ 4,2083 €/unité)
- **Stock restant** = 30 unités du Lot A → **valorisation restante = 30 × 4,00 = 120,00 €**.

→ Sur ce même événement de vente, **FIFO et LIFO produisent un coût de sortie et une valorisation
résiduelle différents** (490,00 € vs 505,00 € de coût sorti ; 135,00 € vs 120,00 € de stock restant) :
la méthode retenue a un **impact direct** sur la marge affichée et sur la valorisation comptable du
stock, d'où l'exigence de **paramétrage explicite et stable** par établissement (RG-STOCK-08, ⚠
hypothèse de verrouillage ci-dessus).

### 4.6 Seuils, alertes de réapprovisionnement, rupture (US-STOCK-08, RG-STOCK-09/10/11)
- **RG-STOCK-10** — Un `ArticleStock` porte un **seuil min** et un **seuil max** (paramétrables par
  établissement). Dès que `Stock.disponibilité ≤ seuilMin`, une **alerte de réapprovisionnement** est
  déclenchée (visible dans un tableau de bord réappro) avec une **quantité suggérée** = `seuilMax −
  disponibilité`. L'alerte **disparaît** dès que la disponibilité repasse au-dessus du seuil (par
  réception ou ajustement).
- **RG-STOCK-11** — À **disponibilité nulle**, un article **géré en stock** est **bloqué à la vente**
  (réutilise `RG-M2-04`, `spec-vente.md` : « grisé et non ajoutable », message « stock épuisé »).
  `App\Stock` ne redéfinit pas ce blocage, il en garantit la **donnée source à jour**.

### 4.7 Décrément à la vente — intégration M2/M3 (US-STOCK-09, RG-STOCK-01/17)
- **RG-STOCK-17** — Toute **Vente M2 validée** (guichet, canal `en_ligne` M3) portant une `LigneVente`
  dont le `Produit` est rattaché à un `ArticleStock` déclenche **automatiquement** un `MouvementStock`
  de type `sortie_vente`, de **quantité = quantité vendue**, référencé à la `Vente`/`LigneVente`
  d'origine (traçabilité croisée avec `spec-vente.md` §5). Le **coût de sortie** est calculé **au moment
  de la validation de la vente** (pas à l'ajout au panier), selon la méthode FIFO/LIFO active à cet
  instant (§4.5).
- **Remboursement / avoir (RG-M2-07)** — Un avoir portant sur une ligne de marchandise **ne réintègre le
  stock qu'après confirmation explicite** de l'opérateur que l'article est **physiquement retourné en
  bon état** (génère alors un `MouvementStock` d'`ajustement_positif`, motif « retour client ») ; à
  défaut (article non retourné, ou retourné endommagé), **aucune réintégration** n'a lieu, éventuellement
  suivie d'un mouvement `perte_casse` si l'article rendu est inutilisable.
- **Vente en ligne (M3)** — La `Reservation` temporaire de panier (`RG-M3-03`) **ne génère aucun
  mouvement de stock** ; seul le **paiement réussi** (Vente confirmée) déclenche le `sortie_vente`
  ci-dessus, cohérent avec le fait qu'un panier expiré libère la réservation sans avoir jamais touché le
  stock physique.

### 4.8 Multi-établissements & transfert (US-STOCK-10, RG-STOCK-13/14)
- **RG-STOCK-13** — Chaque `ArticleStock`, chaque `LotStock` et chaque `MouvementStock` sont rattachés à
  un **Établissement** (`RG-SOCLE-01`) : le stock physique **n'est pas mutualisé implicitement** entre
  établissements (contrairement au pool de capacité M1, `RG-M1-10`, qui **peut** l'être) ; deux
  établissements d'un même groupe possédant le « même » produit boutique ont chacun **leur propre**
  quantité et **leurs propres** couches de coût.
- **RG-STOCK-14** — Un `TransfertStock` entre deux établissements du même groupe génère **deux
  mouvements liés** : `sortie_transfert` côté établissement source (consommant une couche selon la
  méthode active côté source) et `entrée_transfert` côté établissement destination, qui **crée une
  nouvelle couche de coût dont le coût unitaire est celui de la couche consommée à la source** (transfert
  de la valeur réelle, **pas** un nouvel achat au prix courant). Le transfert suit un statut `demandé →
  expédié → reçu`.

### 4.9 Inventaire physique & écarts (US-STOCK-11, RG-STOCK-12)
- **RG-STOCK-12** — Un `Inventaire` porte un **périmètre** (tous articles / rayon / sélection) et un
  **établissement**. À son **lancement**, la `quantitéThéorique` de chaque `LigneInventaire` est **figée**
  (snapshot de `Stock.disponibilité` à cet instant). Le comptage saisit une `quantitéComptée` ; l'**écart**
  = `quantitéComptée − quantitéThéorique` est calculé automatiquement.
  - Un écart **non significatif** (sous un seuil paramétrable, ⚠ HYPOTHÈSE §3) peut être **régularisé
    directement** par le Magasinier (`stock × inventorier`).
  - Un écart **significatif** requiert la **validation** du Responsable (`stock × valider_ecart`) avant
    régularisation.
  - À la **régularisation**, un `MouvementStock` de type `régularisation_inventaire` est généré
    (`ajustement_positif` si écart > 0, `ajustement_négatif` si écart < 0), **valorisé** au **coût de la
    dernière couche active** de l'article (⚠ HYPOTHÈSE : à défaut de source d'entrée identifiable pour
    un écart positif « inconnu », le coût retenu est celui du **dernier prix d'achat connu** de
    l'article ; à confirmer avec le métier).
  - La **clôture** de l'inventaire **fige** définitivement ses lignes (append-only, cohérent
    `RG-SOCLE-07`) ; toute correction ultérieure passe par un **nouvel** ajustement, jamais par la
    réouverture de l'inventaire clôturé.

## 5. Objets de données
Les types PHP sont indicatifs (spec = comportement observable). Tout objet est rattaché à un
**Établissement** via le socle (`RG-SOCLE-01`) ; identifiants = **UUID** (constitution §3). Les objets
M1 (`Produit`, `Stock`), M2 (`Vente`, `LigneVente`, `Avoir`) et M3 (`PanierEnLigne`) sont **référencés,
non redéfinis** (`spec-offre.md` §5, `spec-vente.md` §5, `spec-boutique.md` §5).

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| **Fournisseur** | id | uuid | PK | RG-STOCK-03 |
| | raisonSociale, siret | string | requis | — |
| | contact, email, téléphone, adresse | string | optionnels | — |
| | conditionsPaiement | texte | délai, modalités | — |
| | actif | bool | défaut = true | désactivable, non supprimable si utilisé |
| **CatalogueFournisseur** *(article ↔ fournisseur)* | id | uuid | PK | RG-STOCK-03 |
| | fournisseur, articleStock | ref, ref | requis, clé unique | — |
| | référenceFournisseur | string | — | code article côté fournisseur |
| | prixAchatNégocié | decimal ≥ 0 | HT | peut différer du prix effectif à réception |
| | délaiLivraisonJours | int ≥ 0 | — | US-STOCK-02 |
| | principal | bool | 1 seul principal par article | — |
| **ArticleStock** | id | uuid | PK | RG-STOCK-02 |
| | établissement | ref (socle) | requis | RG-SOCLE-01 |
| | produit | ref Produit (M1)? | 0..1, unique | RG-STOCK-01, non vendable si absent |
| | codeEAN | string(8\|13) | unique par établissement, clé de contrôle valide | RG-STOCK-02 |
| | libellé, unité | string, enum {pièce, kg, litre, paquet…} | requis | — |
| | prixAchatHT, tauxTvaAchat | decimal ≥ 0, decimal | requis | distinct de la TVA de vente (M1/M6) |
| | méthodeValorisation | enum {FIFO, LIFO}? | hérite de l'établissement si vide | RG-STOCK-08, ⚠ surcharge par article |
| | seuilMin, seuilMax | int/decimal ≥ 0 | seuilMin ≤ seuilMax | RG-STOCK-10 |
| | fournisseurPrincipal | ref Fournisseur? | — | via CatalogueFournisseur |
| | actif | bool | défaut = true | désactivable, non supprimable si mouvementé |
| **ParametrageStock** | id, établissement | uuid, ref | 1 par établissement | RG-STOCK-08 |
| | méthodeValorisationDéfaut | enum {FIFO, LIFO} | requis | défaut hérité par les ArticleStock |
| | autoriserStockNégatif | bool | défaut = false | RG-STOCK-16, ⚠ HYPOTHÈSE |
| | seuilÉcartSignificatif | decimal/% | paramétrable | RG-STOCK-12, ⚠ HYPOTHÈSE |
| **CommandeAchat** | id | uuid | PK | RG-STOCK-04 |
| | établissement, fournisseur | ref, ref | requis | — |
| | statut | enum {brouillon, envoyée, confirmée, partiellement_reçue, reçue, clôturée, annulée} | défaut = brouillon | cycle de vie §4.3 |
| | dateCommande, dateLivraisonPrévue | date | — | délai issu du CatalogueFournisseur |
| **LigneCommandeAchat** | id, commande | uuid, ref | PK | — |
| | articleStock | ref | requis | — |
| | quantitéCommandée | decimal > 0 | — | — |
| | prixAchatUnitaireHT, tauxTVA | decimal ≥ 0 | requis | prix négocié par défaut, modifiable |
| | quantitéReçue | decimal ≥ 0 | dérivé, ≤ quantitéCommandée sauf sur-réception ⚠ | Σ des LigneReceptionAchat liées |
| **ReceptionAchat** | id | uuid | PK | RG-STOCK-05 |
| | commandeAchat | ref CommandeAchat? | optionnel (réception hors commande) | — |
| | établissement, fournisseur | ref, ref | requis | — |
| | date, numéroBonLivraison | date, string | requis | — |
| | statut | enum {brouillon, validée} | défaut = brouillon | validation crée les mouvements |
| **LigneReceptionAchat** | id, réception | uuid, ref | PK | — |
| | articleStock, ligneCommandeAchat | ref, ref? | requis / optionnel | — |
| | quantitéReçue | decimal > 0 | — | — |
| | prixAchatUnitaireHT | decimal ≥ 0 | peut différer du prix commandé | RG-STOCK-05 |
| | lotCréé | ref LotStock | 1:1 | créé à la validation |
| **LotStock** *(couche de coût)* | id | uuid | PK | RG-STOCK-08 |
| | articleStock, établissement | ref, ref | requis | — |
| | dateEntrée | datetime | requis | ordonne FIFO/LIFO |
| | quantitéInitiale, quantitéRestante | decimal > 0, decimal ≥ 0 | quantitéRestante ≤ quantitéInitiale | décrémentée à chaque sortie consommant ce lot |
| | coûtUnitaireHT | decimal ≥ 0 | figé à l'entrée | RG-STOCK-05/08 |
| | origine | enum {réception, régularisation_inventaire, entrée_transfert} | requis | traçabilité |
| | référenceOrigine | ref (polymorphe) | requis | ReceptionAchat / Inventaire / TransfertStock |
| **MouvementStock** | id | uuid | PK, append-only | RG-STOCK-07/15 |
| | articleStock, établissement | ref, ref | requis | RG-STOCK-13 |
| | type | enum {entrée_achat, sortie_vente, retour_fournisseur, ajustement_positif, ajustement_négatif, perte_casse, sortie_transfert, entrée_transfert, régularisation_inventaire} | requis | RG-STOCK-07 |
| | date | datetime | requis | — |
| | quantité | decimal > 0 | signe porté par le type, pas par la valeur | — |
| | coûtUnitaireCalculé, coûtTotalCalculé | decimal ≥ 0 | requis si sortie | RG-STOCK-08 |
| | lotsImputés[] | (lot, quantité, coût)[] | requis si sortie multi-couches | traçabilité FIFO/LIFO §4.5 |
| | motif | texte | requis sauf entrée_achat/sortie_vente | RG-STOCK-07 |
| | référence | ref polymorphe | requis | Vente/LigneVente (M2), ReceptionAchat, TransfertStock, Inventaire |
| | auteur | ref Utilisateur (socle) | requis | — |
| **TransfertStock** | id | uuid | PK | RG-STOCK-14 |
| | articleStock | ref | requis | — |
| | établissementSource, établissementDestination | ref, ref | ≠ | — |
| | quantité | decimal > 0 | — | — |
| | statut | enum {demandé, expédié, reçu} | défaut = demandé | — |
| | mouvementSortie, mouvementEntrée | ref MouvementStock? | requis dès expédition/réception | RG-STOCK-14 |
| **Inventaire** | id | uuid | PK | RG-STOCK-12 |
| | établissement | ref | requis | RG-SOCLE-01 |
| | périmètre | enum {tous, rayon, sélection} | requis | — |
| | statut | enum {en_cours, en_validation, clôturé} | défaut = en_cours | append-only après clôture |
| | dateLancement, dateClôture | datetime | clôture ≥ lancement | — |
| **LigneInventaire** | id, inventaire | uuid, ref | PK | — |
| | articleStock | ref | requis | — |
| | quantitéThéorique | decimal ≥ 0 | figée au lancement | snapshot `Stock.disponibilité` |
| | quantitéComptée | decimal ≥ 0? | saisie au comptage | — |
| | écart | decimal | dérivé = comptée − théorique | RG-STOCK-12 |
| | significatif | bool | dérivé du seuil `ParametrageStock` | requiert validation si vrai |
| | validéPar, dateValidation | ref Utilisateur?, datetime? | requis si significatif | `stock × valider_ecart` |
| | mouvementRégularisation | ref MouvementStock? | créé à la régularisation | RG-STOCK-12 |

## 6. États & cycle de vie
```
CommandeAchat : brouillon → envoyée → confirmée → partiellement_reçue → reçue → clôturée
                                                        ↘ annulée (avant réception)
```
```
LotStock (couche de coût) : créé (quantitéRestante = quantitéInitiale) → consommé partiellement
                             (FIFO/LIFO) → épuisé (quantitéRestante = 0)
```
```
TransfertStock : demandé → expédié (mouvement sortie_transfert) → reçu (mouvement entrée_transfert)
```
```
Inventaire : en_cours → en_validation (si écart significatif) → clôturé (append-only)
```

## 7. Critères d'acceptation
- **CA-1 (US-STOCK-01, RG-STOCK-02)** — *Étant donné* la création d'un `ArticleStock`, *quand* je saisis
  un code-barres, *alors* le système **valide la clé de contrôle EAN-13/EAN-8** et **refuse** un code déjà
  utilisé sur le même établissement ; *quand* je le rattache à un Produit M1 de type Boutique, *alors* le
  `Stock.disponibilité` de ce produit devient piloté par `App\Stock`.
- **CA-2 (US-STOCK-01, RG-STOCK-01)** — *Étant donné* un Produit M1 « Boutique » **sans** `ArticleStock`
  rattaché, *quand* il est vendu en caisse, *alors* il n'est **jamais bloqué par le stock** (cohérent
  `RG-M2-04` : produit non géré en stock) ; *étant donné* un `ArticleStock` **sans** Produit rattaché,
  *alors* il n'apparaît **dans aucun catalogue vendable**.
- **CA-3 (US-STOCK-02, RG-STOCK-03)** — *Étant donné* un article relié à trois fournisseurs du catalogue
  fournisseur, *quand* je consulte sa fiche, *alors* je vois les trois références/prix/délais, et **un
  seul** est marqué **principal**.
- **CA-4 (US-STOCK-03, RG-STOCK-04)** — *Étant donné* une `CommandeAchat` en statut `envoyée`, *quand* je
  consulte la disponibilité en caisse de l'article commandé, *alors* elle **n'a pas bougé** (aucun
  mouvement tant qu'aucune réception n'est validée).
- **CA-5 (US-STOCK-04, RG-STOCK-05)** — *Étant donné* une commande de 100 unités à 4,00 € HT, *quand* je
  réceptionne **60 unités à 4,10 € HT** (prix réel différent), *alors* un `LotStock` de 60 unités à
  4,10 € est créé, le `Stock.disponibilité` **augmente de 60**, et la commande passe en
  `partiellement_reçue` ; *quand* je réceptionne les 40 unités restantes, *alors* la commande passe à
  `reçue`.
- **CA-6 (US-STOCK-05, RG-STOCK-06)** — *Étant donné* une réception validée, *quand* j'enregistre un
  **retour fournisseur** de 10 unités sur ce lot, *alors* un mouvement `retour_fournisseur` motivé est
  créé, le lot d'origine **perd 10 unités de `quantitéRestante`**, et le `Stock.disponibilité` diminue
  d'autant.
- **CA-7 (US-STOCK-06, RG-STOCK-07)** — *Étant donné* un article, *quand* je saisis une **perte/casse**
  sans motif, *alors* l'enregistrement est **refusé** ; *quand* je saisis un motif, *alors* un mouvement
  `perte_casse` est créé et **jamais supprimable** ensuite (RG-STOCK-15).
- **CA-8 (US-STOCK-07, RG-STOCK-08, exemple FIFO §4.5)** — *Étant donné* un article en méthode **FIFO**
  avec un Lot A (100 u. à 4,00 €, entré le 01/03) et un Lot B (50 u. à 4,50 €, entré le 15/03), *quand*
  une vente de 120 unités est validée le 20/03, *alors* le coût de sortie calculé est **490,00 €** (100 ×
  4,00 + 20 × 4,50), le Lot A est **épuisé**, le Lot B a **30 unités restantes** valorisées à **135,00 €**.
- **CA-9 (US-STOCK-07, RG-STOCK-08, exemple LIFO §4.5)** — *Étant donné* le **même article et les mêmes
  lots**, mais une méthode **LIFO**, *quand* la même vente de 120 unités est validée, *alors* le coût de
  sortie calculé est **505,00 €** (50 × 4,50 + 70 × 4,00), le Lot B est **épuisé**, le Lot A a **30
  unités restantes** valorisées à **120,00 €** — *démontrant* que FIFO et LIFO produisent un coût de
  sortie et une valorisation résiduelle **différents** sur le même événement.
- **CA-10 (US-STOCK-09, RG-STOCK-17, cas rupture)** — *Étant donné* un article à `Stock.disponibilité =
  0`, *quand* un agent de caisse tente de l'ajouter au panier (M2), *alors* l'ajout est **refusé** («
  stock épuisé », cohérent `RG-M2-04`), **sans qu'aucun mouvement de stock ne soit créé** ; *étant donné*
  ce même article passant sous son **seuil min** après une vente partielle, *alors* une **alerte de
  réapprovisionnement** apparaît avec une **quantité suggérée** = `seuilMax − disponibilité`.
- **CA-11 (US-STOCK-09, RG-STOCK-17)** — *Étant donné* une Vente M2 validée avec une ligne d'article de
  stock (quantité 3), *quand* la vente est confirmée, *alors* un `MouvementStock` `sortie_vente` de
  quantité 3 est créé **automatiquement**, référencé à la `LigneVente`, avec un coût calculé selon la
  méthode active ; *quand* un avoir est émis avec confirmation de retour physique en bon état, *alors* un
  mouvement `ajustement_positif` réintègre la quantité ; *sans* cette confirmation, **aucune
  réintégration** n'a lieu.
- **CA-12 (US-STOCK-10, RG-STOCK-14)** — *Étant donné* un `TransfertStock` de 20 unités entre
  Établissement A et B, *quand* il est expédié, *alors* un mouvement `sortie_transfert` décrémente A
  (consommant une couche selon la méthode de A) ; *quand* il est reçu, *alors* un mouvement
  `entrée_transfert` crée à B une **nouvelle couche** dont le **coût unitaire est celui de la couche
  consommée à la source** (pas un nouveau prix d'achat).
- **CA-13 (US-STOCK-11, RG-STOCK-12, cas inventaire avec écart)** — *Étant donné* un article à
  `quantitéThéorique = 50` figée au lancement d'un `Inventaire`, *quand* le comptage physique relève
  `quantitéComptée = 46`, *alors* l'**écart calculé = −4** ; *si* cet écart dépasse le seuil de
  significativité paramétré, *alors* la ligne **requiert une validation** du Responsable avant
  régularisation ; *une fois validée* (ou directement si non significatif), *alors* un mouvement
  `régularisation_inventaire` de type `ajustement_négatif` de 4 unités est créé, valorisé au **coût de la
  dernière couche active**, et `Stock.disponibilité` passe à 46 ; *à la clôture de l'inventaire*, *alors*
  ses lignes deviennent **non modifiables**.
- **CA-14 (US-STOCK-12, RG-STOCK-18)** — *Étant donné* un article possédant plusieurs couches actives à
  une date T, *quand* le Comptable consulte la **valorisation à cette date**, *alors* elle est calculée
  comme la **somme, sur toutes les couches actives à T**, de `quantité restante × coût unitaire`, cohérente
  avec la méthode (FIFO/LIFO) active sur la période concernée.

## 8. Cas limites
- **Produit M1 rattaché à un pool partagé (`Stock.type = partagé`, `RG-M1-10`)** — ⚠ HYPOTHÈSE : un
  `ArticleStock` n'est **prévu que pour un `Stock` dédié** (§4.1) ; le rattachement d'un article physique
  à un **pool mutualisé** entre plusieurs produits (ex. plusieurs variantes d'un même mug partageant un
  quota) n'est **pas couvert** par cette version de la spec — à traiter comme extension si le besoin est
  confirmé (chaque variante aurait alors son propre `ArticleStock`/EAN, mais un `Stock.disponibilité`
  commun, ce qui **complexifierait** l'imputation FIFO/LIFO par variante).
- **Vente en caisse hors-ligne (mode dégradé M2, `RG-M2-08`)** — ⚠ HYPOTHÈSE : la caisse peut valider une
  vente **sans vérification stricte en temps réel** du stock si le réseau est coupé ; le `MouvementStock`
  `sortie_vente` correspondant n'est créé **qu'à la resynchronisation**, ce qui peut produire une
  **disponibilité négative temporaire** découverte après coup. Le paramètre `autoriserStockNégatif`
  (`ParametrageStock`) est retenu comme **filet de sécurité** pour ne pas bloquer la resynchronisation,
  mais la **procédure de régularisation** (alerte, arbitrage priorité de vente) n'est **pas détaillée**
  par les sources — **à arbitrer avec M2** (risque déjà signalé côté vente pour le stock pool M1,
  `spec-vente.md` §7).
- **Réception excédant la commande (sur-réception)** — ⚠ HYPOTHÈSE : le cahier ne fixant rien, une
  réception dont `quantitéReçue > quantitéCommandée` sur une ligne est **acceptée par défaut** (le
  fournisseur a livré plus que prévu) et **génère un lot au surplus**, sans blocage ; un contrôle
  paramétrable (alerte, blocage) pourrait être ajouté — non tranché.
- **Prix d'achat à 0 € ou lot sans coût connu (don, échantillon)** — ⚠ HYPOTHÈSE : un `LotStock` à coût
  nul est **accepté** (traçabilité de quantité conservée), mais fausse la valorisation moyenne si mêlé à
  des lots payants ; comportement à confirmer (isoler dans un lot dédié « don »).
- **Article désactivé avec un solde de stock restant** — Refusé : un `ArticleStock` **non mouvementé à
  zéro** ne peut être désactivé/archivé tant qu'un solde positif subsiste, cohérent avec la
  non-suppression des référentiels utilisés déjà retenue en M1 (`spec-offre.md` §7).
- **Changement de méthode de valorisation en cours d'exercice** — Réservé au Responsable
  (`stock × parametrer`), **non rétroactif** sur les couches déjà consommées (⚠ HYPOTHÈSE, §4.5) ; impact
  sur la comparabilité d'une valorisation avant/après changement **non détaillé** — à valider avec un
  comptable avant activation en production.
- **Écriture comptable de variation de stock** — ⚠ **GAP** : le cahier exclut explicitement la
  « comptabilité fournisseurs générale » de M6 (§2.3) et ne décrit **aucune écriture de variation de
  stock** (débit/crédit compte de stock, compte d'achat) dans `spec-compta.md`. `App\Stock` **calcule**
  la valorisation (RG-STOCK-18) mais **aucune génération automatique d'écriture** n'est spécifiée à ce
  jour — cf. §9 « Points ouverts ».
- **Utilisateur sans affectation sur l'établissement de l'article/du mouvement** — Aucun accès (hérité du
  socle, `RG-SOCLE-05`).

## 9. Dépendances
- **Dépend de : socle L0** (`specs/L0-socle/spec-socle.md`) — hiérarchie Groupe/Région/Établissement/
  Espace (`RG-SOCLE-01`) à laquelle se rattachent `ArticleStock`, `LotStock`, `MouvementStock` (RG-STOCK-13) ;
  permissions `module × action` réutilisées sur le module **`stock`** (`RG-SOCLE-02/03/04`) ; journal
  d'audit append-only (`RG-SOCLE-07`) auquel `App\Stock` s'aligne (RG-STOCK-15).
- **Dépend de : M1 · Offre & Tarification** (`specs/L1-offre/spec-offre.md`) — `Produit` de type
  « Boutique » (`RG-M1-02`) et son `Stock.disponibilité` dédié (`RG-M1-10`) : **point d'articulation
  central** (§4.1, RG-STOCK-01) — `App\Stock` **alimente** ce compteur, ne le duplique pas ; prix de
  vente et TVA de vente restent portés par M1/la grille tarifaire (`RG-M1-01`), distincts du prix d'achat
  et de la TVA d'achat portés ici.
- **Dépend de : M2 · Vente & Caisse** (`specs/L2-vente/spec-vente.md`) — **fait générateur** du mouvement
  `sortie_vente` (RG-STOCK-17) à chaque `Vente` validée (`RG-M2-04`, blocage à disponibilité nulle) ;
  l'**avoir** (`RG-M2-07`) déclenche, sur confirmation opérateur, la réintégration de stock.
- **Dépend de : M3 · Boutique en ligne** (`specs/L8-boutique/spec-boutique.md`) — la **réservation
  temporaire de panier** (`RG-M3-03`) consomme le compteur « réservé » exposé par `App\Stock` (RG-STOCK-09)
  sans générer de mouvement tant que le paiement n'est pas confirmé ; les **connecteurs OTA**
  (`RG-M3-09`) décrémentent le **même inventaire réel**, cohérent avec l'absence de stock séparé déjà
  retenue côté musée (`RG-MUS-04`).
- **Dépend de : M6 · Compta & Régie** (`specs/L4-compta/spec-compta.md`) — **consommateur potentiel** de
  la valorisation FIFO/LIFO (RG-STOCK-18) pour une éventuelle écriture de variation de stock — **non
  spécifiée à ce jour côté M6** (cf. §8, gap) ; le **taux de TVA d'achat** porté par `ArticleStock` reste
  **distinct** du moteur multi-taux de TVA de **vente** (`RG-M6-04/05`), non redéfini ici.
- **Référencé par : verticale Musée** (`specs/musee/spec-musee.md`) — la **boutique du musée**
  (audioguides, produits dérivés) est le cas d'usage explicitement à l'origine de ce module (cf. §7 de
  `spec-musee.md`, « Audioguide en rupture… potentiellement rattachable au Stock générique M1, non
  détaillée spécifiquement ») ; `App\Stock` **répond** à ce gap pour tout produit boutique physique du
  musée, sans redéfinir les mécaniques spécifiques musée (OTA, timed-entry).
- **Référencé par : verticale Patinoire** (`specs/patinoire/spec-patinoire.md`) — le `ParcPatins`
  (location par pointure) **pourrait** à terme se rattacher à `App\Stock` comme cas spécialisé (article
  = pointure, mouvement = location/retour plutôt qu'achat/vente) ; **non réconcilié** dans cette version
  (le `ParcPatins` reste, pour l'instant, un objet **spécifique** à `spec-patinoire.md`, hors valorisation
  FIFO/LIFO qui n'a pas de sens pour du matériel loué et non consommé) — cf. §9 points ouverts.

---

## Points ouverts / hypothèses (récapitulatif)

### Absence de source officielle (à faire trancher/valider par le produit)
1. **`App\Stock` est un module entièrement défini par cet agent** — ni `backlog.html` ni
   `cahier-detaille.html` ne décrivent de module « Stock & Inventaire boutique » avec fournisseurs,
   commandes d'achat, valorisation FIFO/LIFO ou inventaire physique ; **US-STOCK-01 à 12** et
   **RG-STOCK-01 à 18** sont **nouvelles**, à faire **valider, numéroter et chiffrer** officiellement dans
   le backlog avant développement (même réserve que `spec-boutique.md`/`spec-reservation.md`).

### Articulation avec le Stock/Pool de capacité M1 (point explicitement demandé)
2. **Réponse apportée (§4.1, RG-STOCK-01)** : le `Stock.disponibilité` de M1 (`RG-M1-10`) reste
   l'**unique compteur observable** consommé par M2/M3 ; `App\Stock` **spécialise** ce compteur côté
   marchandises physiques (1 `ArticleStock` ↔ 1 `Produit`/`Stock` dédié au plus) sans le dupliquer.
3. **⚠ Non couvert** : le rattachement d'un `ArticleStock` à un **`Stock` partagé/pool** (mutualisé entre
   plusieurs produits, `RG-M1-10`) — cas limite §8, non traité dans cette version, extension possible si
   confirmé par le métier.

### Impact comptable de la valorisation (point explicitement demandé)
4. **⚠ GAP majeur** — Aucune écriture comptable de **variation de stock** (débit/crédit) n'est décrite par
   `spec-compta.md`/M6, qui exclut explicitement la « comptabilité fournisseurs générale » (§2.3, cahier
   §M6). `App\Stock` **calcule** une valorisation (RG-STOCK-18, `stock × lire_valorisation`) mais
   **aucun mécanisme de génération automatique d'écriture** n'est spécifié à ce jour ; à cadrer avec un
   **comptable** avant toute intégration M6 (nouvelle RG-M6 à créer côté `spec-compta.md` si retenu).
5. **⚠ HYPOTHÈSE À VALIDER PAR EXPERT** — Verrouillage/impact d'un **changement de méthode FIFO ↔ LIFO**
   en cours d'exercice sur la comparabilité de la valorisation (§4.5, §8) — non tranché par les sources
   (inexistantes), retenu par défaut « non rétroactif, réservé au Responsable ».

### ⚠ HYPOTHÈSE (fonctionnelle, à trancher avec le métier / M2 / M8)
6. Unicité du code-barres EAN par **établissement** vs par **groupe** (§4.1).
7. Stock négatif en mode dégradé caisse (M2 hors-ligne, `RG-M2-08`) : filet de sécurité posé
   (`autoriserStockNégatif`), procédure de régularisation après resynchronisation non détaillée (§8).
8. Seuil de significativité d'un écart d'inventoire déclenchant une validation Responsable (§3, §4.9).
9. Coût retenu pour un écart d'inventaire **positif** sans source d'entrée identifiable (dernier prix
   d'achat connu, retenu par défaut) (§4.9).
10. Sur-réception (quantité livrée > quantité commandée) : acceptée par défaut, sans contrôle bloquant
    (§8).
11. Noms des permissions `stock × …` : proposés selon le modèle socle, à figer avec **M8** (§3).
12. Réconciliation future avec `ParcPatins` (`spec-patinoire.md`) comme cas spécialisé de matériel loué
    (non consommé, sans valorisation FIFO/LIFO pertinente) — non traitée dans cette version (§9).
