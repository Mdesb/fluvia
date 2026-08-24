# Spec — CQ-8 : émission multiple de supports à quantité > 1 (`RG-CQ8-xx`)

- **Lot / module :** L2 · Vente/Caisse (`App\Vente`) — catégorie **ARGENT** (défaut financier constaté)
- **Stories couvertes :** aucune `US-Lx-nn` dédiée au backlog — lot défaut, révélé par CQ-1 (revue de
  cohérence, `COORDINATION/MESSAGES.md` 2026-08-24 « claude-A → CQ-1 », repris `COORDINATION/TASKS.md`
  ligne 70 : *« CQ-8 — ARGENT — vendre N cartes en une ligne facture N et n'émet qu'une seule chargée »*)
- **Règles de gestion :** RG-M2-04 (« l'émission d'un billet/abonnement génère **son** support » —
  implicitement un support par unité vendue), RG-M2-07 (traçabilité annulation/avoir, non modifiée),
  RG-CQ1-01/05/07 (recharge de carte, **non re-tranchées**, seulement citées pour ordre des contrôles),
  + `RG-CQ8-01` à `RG-CQ8-07` (nouvelles, ce lot)
- **Décisions actées, non re-tranchées :** RG-CQ1-08/D7-bis (transaction DBAL unique, publication
  d'événement différée après commit réel), D3/D8 (cloisonnement établissement), D5 (anglais pour tout
  identifiant technique **neuf**)
- **Statut :** brouillon

## 0. Ce que ce lot n'est pas

- **CQ-1** (recharge de carte) — inchangé. Le refus « une ligne de recharge ne peut pas porter une
  quantité > 1 » (`RG-CQ1-07`, `ValiderVenteService.php` lignes 246-257) reste tel quel : une ligne dont
  l'identifiant fourni **existe déjà** en tant que carte continue de sortir de `creerSupport()` **avant**
  d'atteindre le code que ce lot modifie (`return null;` ligne 271). CQ-8 ne concerne que la branche
  **émission** (identifiant absent, ou identifiant fourni mais inédit).
- **Pas de refonte du calcul de prix.** `PanierCalculateur::recalculerVente()` (lignes 24, 45) multiplie
  déjà correctement `prix unitaire × quantité` — c'est le côté facturé qui est correct ; seul le côté
  « supports émis » est en défaut.
- **Pas de bénéficiaire distinct par unité** pour une ligne nominative (`FACETTE_ACCES`) à `quantite >
  1` — `LigneVente.beneficiaire` reste un champ unique par ligne (`AjoutLigneHandler.php` ligne 79), pas
  une liste. Gap préexistant, non introduit ni corrigé ici (cf. §8, cas limite).
- **Pas de nouvel événement de domaine.** Aucun événement `access.*`/`vente.*` n'est publié par
  `creerSupport()`/`valider()` en dehors de `access.card_recharged` (branche recharge, hors périmètre) —
  ce lot ne fait qu'itérer un code existant, aucun nouveau point d'observation n'est requis par les CA
  ci-dessous (vérifié : rien ne consomme aujourd'hui « nombre de supports émis par ligne »).
- **Pas de migration de schéma ni de régularisation des ventes historiques.** `BilletSupport` ne change
  pas de forme (§6). Les ventes déjà scellées avant ce correctif, où une ligne à `quantite > 1` n'a émis
  qu'un seul support, ne sont **pas** corrigées rétroactivement (cf. §8).

## 1. Objectif

Qu'une ligne de vente à `quantite = N` sur un produit émetteur de support (billet, carnet/carte,
abonnement — `TypeProduit::aFacette(BILLET|CARNET|ACCES)`) émette **N `BilletSupport`** distincts et
appairés indépendamment à la validation, exactement comme le montant facturé (déjà `N × prix unitaire`)
le laisse attendre. Aujourd'hui le client paie N et ne reçoit qu'un seul support chargé — défaut ARGENT
constaté sur pièce, documenté dans le code lui-même (`ValiderVenteService.php` lignes 251-253).

## 2. Périmètre

- **Inclus :**
  - Émission d'exactement `ligne.getQuantite()` `BilletSupport` par ligne émettrice, au lieu d'un seul
    (`RG-CQ8-01`).
  - Le garde-fou sur un identifiant explicite fourni pour une ligne à `quantite > 1` en **émission**
    (`RG-CQ8-02`) — un identifiant explicite ne peut pas être réutilisé pour N supports (contrainte
    d'unicité globale, `BilletSupport.php` ligne 21).
  - L'homogénéité de l'`override['type']` sur les N supports d'une même ligne (`RG-CQ8-03`).
  - L'appairage indépendant de chacun des N supports (`RG-CQ8-04`).
  - La confirmation que le scellement NF525 (`payload()`) n'a besoin d'aucune modification de structure
    (`RG-CQ8-05`).
  - La confirmation que les consommateurs existants de `vente.supports` (API, PDF, e-mail) fonctionnent
    déjà en « N supports par vente » sans modification (`RG-CQ8-06`).
  - La question ouverte du bornage anti-abus de `quantite` pour une ligne émettrice (`RG-CQ8-07`, arbitrage
    demandé).
- **Exclu (autres lots, ne pas refaire ici) :**
  - CQ-1 : mécanisme de recharge lui-même (inchangé, seulement lu).
  - CQ-7 : paramétrage de la validité après recharge (n'entre pas en jeu ici, branche recharge non
    touchée).
  - Bénéficiaire nominatif multiple par ligne (hors périmètre, cf. §0/§8).
  - Toute régularisation rétroactive des ventes déjà scellées avant ce correctif.

## 3. État actuel (constat, code réel)

`ValiderVenteService::valider()` (`app/src/Vente/Service/ValiderVenteService.php` lignes 119-128) itère
`$vente->getLignes()` et appelle **une fois** `creerSupport($vente, $ligne, …)` par ligne, quel que soit
`$ligne->getQuantite()` :

```
foreach ($vente->getLignes() as $ligne) {
    $support = $this->creerSupport($vente, $ligne, $supportsOverride[...] ?? null);
    if ($support === null) { continue; }
    $vente->addSupport($support);
    $this->em->persist($support);
    $supportsCrees[] = $support;
    $this->appairage->appairer($support);
}
```

`creerSupport()` (lignes 201-296) ne lit **jamais** `$ligne->getQuantite()` : il construit un seul
`BilletSupport`, lui assigne un identifiant unique (généré ou fourni en override) et, pour un
produit-carte, `nbCompostages = $produit->getCarte()->getStockCompostagesInitial()` (ligne 292) — valeur
fixe, non multipliée. Pendant ce temps :

- `PanierCalculateur::recalculerVente()` (lignes 24, 45) facture `(prixUnitaire + impactOptions) ×
  quantite` — le client paie bien N.
- `payload()` (lignes 331-338) scelle `'qte' => $ligne->getQuantite()` — la quantité N est donc déjà
  correctement enregistrée côté NF525 (aucun défaut de ce côté).

Le commentaire du code documente déjà l'écart pour la branche recharge et renvoie explicitement à ce lot
(`RG-CQ1-07`, lignes 251-253 : *« N'affecte pas l'émission normale à quantite > 1 (même défaut, mais hors
périmètre CQ-1 — CQ-8) »*).

Aucun garde-fou n'empêche aujourd'hui de fournir un `override['identifiant']` explicite sur une ligne à
`quantite > 1` en émission (identifiant inédit, donc pas une recharge) : `creerSupport()` assignerait cet
identifiant unique au seul support créé — le défaut ARGENT se manifeste alors sans même provoquer
d'erreur technique.

## 4. Acteurs & droits

| Acteur | Peut | Permission (module × action) |
|---|---|---|
| Agent de caisse / guichet | Valider une vente portant une ligne à `quantite > 1` sur un produit émetteur | `vente.encaisser` (existante, inchangée — `POST /ventes/{id}/valider`, `ValiderVenteProcessor`) |
| Boutique en ligne (système) | Confirmer une commande payée avec ligne(s) à `quantite > 1` | aucun droit utilisateur — `ConfirmerCommandeHandler::confirmerApresPaiementReussi()`, appel serveur à `ValiderVenteService::valider()` |

**Ce lot ne crée aucune nouvelle permission ni aucun nouvel endpoint API** — il modifie uniquement le
comportement interne de `ValiderVenteService::valider()`/`creerSupport()`.

## 5. Comportements & règles

### Invariant central

- **RG-CQ8-01 — Un support par unité vendue.** Pour une ligne `L` dont le produit émet un support
  (`emetSupport($type)` vrai, lignes 315-320) et dont la ligne **n'est pas** une recharge au sens de
  `RG-CQ1-01` (identifiant absent, ou fourni mais inédit), `valider()` crée exactement
  `L.getQuantite()` `BilletSupport` distincts, chacun :
  - avec un `identifiantSupport` propre, unique, généré indépendamment (même mécanisme retry existant,
    `genererIdentifiantUnique()`, lignes 302-313 — inchangé, simplement appelé N fois) ;
  - avec, pour un produit-carte, `nbCompostages = produit.getCarte().getStockCompostagesInitial()`
    **répété à l'identique sur chacun des N supports** (pas divisé, pas multiplié par N — chaque carte
    physique émise porte son propre stock initial complet, cohérent avec RG-CQ1-02/08 déjà appliqué à la
    recharge) ;
  - rattaché à la **même** `LigneVente` (`BilletSupport.ligne`, pas de nouveau champ, §6).
  Le total facturé (`N × prixUnitaire`, déjà correct) correspond alors au nombre de supports réellement
  émis — c'est l'invariant que ce lot restaure.

### Override identifiant explicite + quantité > 1 (décision)

- **RG-CQ8-02 — Un identifiant explicite en émission impose `quantite = 1`.** Si, pour une ligne qui
  n'est **pas** une branche recharge (`RG-CQ1-01` non déclenchée), un `override['identifiant']` est
  fourni **et** `ligne.getQuantite() > 1`, `valider()` refuse explicitement (422 —
  `UnprocessableEntityHttpException`, *« Un identifiant explicite impose une quantité de 1 en émission
  (RG-CQ8-02) »*) **avant** toute création de support pour cette ligne. Aucun support n'est créé, la
  transaction complète est annulée (RG-CQ1-08, atomicité déjà garantie, inchangée).

  **Décision retenue : option (a) — refus explicite**, plutôt que (b) « premier support = identifiant
  fourni, N-1 auto-générés ». Justification :
  - **Symétrie avec l'existant.** C'est exactement le choix déjà fait pour la recharge (`RG-CQ1-07`,
    ligne 253-257 : *« Une ligne de recharge ne peut pas porter une quantité supérieure à 1 »*) — même
    contrainte technique (unicité globale de `identifiantSupport`, `BilletSupport.php` ligne 21), même
    réponse. Un lecteur du code trouve la même règle aux deux endroits, pas deux politiques différentes
    pour un même problème.
  - **(b) est arbitraire et coûte plus cher à spécifier/tester** : quel support « reçoit » l'identifiant
    demandé (le premier ? le dernier ?) n'a aucune réponse métier évidente, et un appelant qui a fourni
    un identifiant s'attend en général à un **seul** objet physique porteur de ce code (ex. une carte
    RFID pré-encodée scannée à la caisse), pas à ce que ce code désigne arbitrairement 1 objet parmi N.
  - **Impact réel nul sur les appelants existants**, vérifié dans le code :
    - `ConfirmerCommandeHandler::confirmerApresPaiementReussi()` (lignes 189-192) ne fournit **jamais**
      d'`identifiant` en override — seulement `['type' => 'qr']`. Ce refus ne le concerne donc jamais.
    - Le seul point d'entrée qui peut fournir un `identifiant` est le corps de
      `POST /ventes/{id}/valider` (`ValiderVenteProcessor.php` lignes 36-43, `supports[].identifiant`,
      usage guichet). Rien dans le code actuel ne combine aujourd'hui `identifiant` explicite **et**
      `quantite > 1` en émission — cette combinaison, si elle survenait, produirait *aujourd'hui* le
      défaut ARGENT en silence (un seul support pour N facturés). Le refus explicite est donc strictement
      **plus sûr** que le comportement actuel, jamais une régression fonctionnelle pour un usage réel.

### Override type (homogénéité)

- **RG-CQ8-03 — L'`override['type']` s'applique identiquement aux N supports d'une ligne.** Le type d'un
  support vient du **produit/override**, jamais de l'unité individuelle (code actuel lignes 278-282,
  inchangé) : si `override['type']` résout un `TypeSupport` valide, **chacun** des N supports créés pour
  la ligne le porte ; sinon chacun retombe sur la même règle par défaut (`TypeSupport::Carte` si
  `FACETTE_CARNET`, sinon `TypeSupport::Billet`). Aucune hétérogénéité de type au sein d'une même ligne.

### Appairage et impression

- **RG-CQ8-04 — Chaque support s'appaire indépendamment.** La boucle sur les lignes de `valider()`
  (lignes 119-128) itère désormais sur les N supports d'une ligne émettrice ; `$vente->addSupport($support)`,
  `$this->em->persist($support)` et `$this->appairage->appairer($support)` sont appelés **pour chacun**,
  exactement comme aujourd'hui pour un support unique. Un échec d'appairage sur l'un des N supports laisse
  **ce support-là** en `StatutAppairage::Echec` (remise bloquée pour lui seul, comportement actuel
  ligne 127) sans empêcher la création ni l'appairage des N-1 autres, ni la validation/scellement de la
  vente (comportement inchangé, simplement répété N fois). CA-11 (seuil d'impression, lignes 141-145)
  reste calculé sur `vente.getTotal()` — déjà correct, N'est pas affecté par le nombre de supports.

### Scellement NF525

- **RG-CQ8-05 — Aucune modification du payload scellé.** `payload()` (lignes 325-349) continue de porter
  `'qte' => $ligne->getQuantite()` par ligne (déjà correct, §3) et **ne liste pas** individuellement les
  identifiants des N supports émis. Le scellement NF525 porte sur la **vente** (montant, quantités,
  moyens de paiement) — pas sur le détail des supports physiques, exactement comme c'était déjà le cas à
  `quantite = 1` (l'unique identifiant de support n'était déjà pas présent dans le payload avant ce lot).
  **Conséquence** : aucun changement de format de payload, aucun impact sur le hash de chaînage NF525
  existant, aucune migration. La traçabilité des N identifiants émis reste garantie par la relation
  `BilletSupport.ligne → LigneVente → Vente` (table `vente_billet_support`), interrogeable indépendamment
  du scellement.
  ⚠ HYPOTHÈSE non bloquante : si un audit NF525 futur exigeait la liste des identifiants de support
  **dans** le payload scellé lui-même (traçabilité renforcée au niveau du scellement), ce serait un
  changement de format affectant **toutes** les ventes (pas seulement `quantite > 1`) — hors périmètre de
  ce lot, à instruire séparément si l'exigence se confirme.

### Sortie API / consommateurs existants

- **RG-CQ8-06 — `vente.supports` expose N entrées pour une ligne à `quantite = N`.** C'est déjà le
  comportement générique de la collection `Vente::supports` (`Groups(['vente:read'])`,
  `BilletSupport.php` lignes 26-53) : aucune modification de sérialisation n'est nécessaire. Vérifié dans
  le code que les consommateurs existants itèrent déjà `vente.getSupports()` en toute généralité :
  - `App\Boutique\Billet\GenerateurPdfBillet::genererPourVente()` (lignes 40-43) génère déjà **un billet
    PDF par support** (`foreach ($vente->getSupports() as $support) { $billets[] = $this->vueBillet($support); }`).
  - `App\Boutique\Notification\ConfirmationCommandeMailer` (ligne 41) calcule déjà
    `$vente->getSupports()->count()` pour `nbBillets`.
  - `App\Vente\Service\ContrePassationHandler::annuler()` (lignes 44-50) invalide déjà **chaque** support
    de la vente individuellement en cas d'annulation après impression.
  Ces trois consommateurs bénéficient automatiquement de la correction, **sans aucune modification** de
  leur propre code — seule `ValiderVenteService` change. Non-régression garantie pour `quantite = 1`
  (toujours 1 entrée, comportement inchangé).

### Bornage (question ouverte, arbitrage demandé)

- **RG-CQ8-07 — Pas de plafond introduit par ce lot ; risque signalé, arbitrage A demandé.**
  `AjoutLigneHandler::ajouter()` (lignes 60-64) borne déjà `quantite` au stock disponible **pour un
  produit à stock géré** (`CA-6`) — mais un produit à stock **non géré**
  (`$produit->getStock() === null`) reste, aujourd'hui, sans aucun plafond (commentaire du code même,
  ligne 60 : *« produit non géré : jamais bloqué »*). Un tel produit, s'il émet un support (billet
  simple, abonnement sans gestion de stock), pourrait porter une ligne à `quantite` arbitrairement grande
  et déclencher, dans la même transaction DBAL, jusqu'à `N` générations de code signé (chacune jusqu'à 5
  tentatives, `genererIdentifiantUnique()`) et `N` appels d'appairage.
  **Recommandation de ce lot (non retenue comme critère d'acceptation bloquant, ⚠ arbitrage A requis)** :
  introduire un plafond configurable, raisonnable par défaut (proposition : 100 supports par ligne),
  refusé en 422 explicite à la validation si dépassé, appliqué **uniquement** aux lignes émettrices de
  support (pas aux lignes sans support, qui ne coûtent rien de plus). Deux options pour l'intégrateur :
  1. Accepter le plafond proposé → `RG-CQ8-07` devient un critère d'acceptation à part entière (ajouté au
     plan).
  2. Considérer que ce risque est déjà couvert en pratique (interface de caisse ne permet pas de saisir
     des quantités déraisonnables, produits à stock non géré rares en usage réel) et **ne pas** l'ajouter
     dans ce lot — cohérent avec la consigne « reste additif » : la correction du défaut ARGENT
     (`RG-CQ8-01/02`) ne dépend d'aucune façon de ce bornage.
  Ce point est volontairement laissé ouvert (§9, dépendances) plutôt que tranché unilatéralement ici : ce
  n'est pas un défaut ARGENT (aucun client ne peut être lésé par l'absence de plafond), c'est un risque de
  robustesse/anti-abus distinct.

## 6. Objets de données

Aucune nouvelle entité, aucun nouveau champ, **aucune migration** de schéma requise pour `RG-CQ8-01` à
`RG-CQ8-06`.

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| `App\Vente\Entity\BilletSupport` (existant, inchangé) | `identifiantSupport` | `?string` | unique globale (`uniq_billet_support_identifiant`) | un par **item physique** — modèle déjà correct, seul le **nombre d'instances créées** par ligne change |
| | `ligne` | `?LigneVente` | FK | N `BilletSupport` peuvent partager la **même** `LigneVente` — déjà le cas nominal du modèle (pas de champ « quantité » sur `BilletSupport`, confirmé) |
| | `nbCompostages` | `?int` | = `stockCompostagesInitial` du produit-carte vendu | répété à l'identique sur chacun des N supports d'une même ligne (`RG-CQ8-01`) |
| `App\Vente\Entity\LigneVente` (existant, inchangé) | `quantite` | `int`, défaut 1 | déjà multiplié dans `PanierCalculateur` (correct) | pilote désormais aussi le nombre de `BilletSupport` créés (`RG-CQ8-01`) — jusqu'ici ignoré par `creerSupport()` |
| `App\Vente\Service\ValiderVenteService` (existant, modifié) | `creerSupport()` | méthode privée | signature inchangée **ou** retour `list<BilletSupport>` (détail laissé au plan) | boucle interne `for ($i = 0; $i < $ligne->getQuantite(); $i++)` autour de la construction d'un support (détail d'implémentation, non normatif ici) |
| *(optionnel, si RG-CQ8-07 retenu)* paramètre de configuration | `MAX_SUPPORTS_PAR_LIGNE` (nom provisoire, D5 anglais si retenu : ex. `max_supports_per_line`) | `int` | valeur par défaut proposée 100 | seulement si l'arbitrage A retient le bornage (§5) |

## 7. Critères d'acceptation

- **CA-1 (RG-CQ8-01, invariant central — billet)** — *Étant donné* une ligne de vente sur un produit
  émetteur de support simple (`FACETTE_BILLET`) à `quantite = 3`, sans override d'identifiant, *quand* la
  vente est validée, *alors* exactement 3 `BilletSupport` sont créés et rattachés à la ligne/vente,
  chacun avec un `identifiantSupport` distinct et un code signé valide (`GenerateurCodeSupport::verifier()`
  vrai pour chacun) ; `vente.getTotal()` reste `3 × prixUnitaire` (déjà correct, non modifié).
- **CA-2 (RG-CQ8-01, invariant central — carte)** — *Étant donné* une ligne sur un produit-carte
  (`stockCompostagesInitial = 10`) à `quantite = 2`, sans override d'identifiant existant, *quand* la
  vente est validée, *alors* 2 `BilletSupport` de type `Carte` sont créés, chacun avec `nbCompostages =
  10` (ni 20, ni 5) et un identifiant distinct.
- **CA-3 (RG-CQ8-02, refus identifiant explicite + `quantite > 1` en émission)** — *Étant donné* une
  ligne à `quantite = 2` sur un produit émetteur avec un `override['identifiant']` explicite **inédit**
  (pas de `BilletSupport` existant portant cet identifiant), *quand* la vente est validée, *alors* elle
  échoue en 422 avec un message explicite référençant `RG-CQ8-02`, et **aucun** `BilletSupport` n'est
  créé pour cette ligne (vérifié après rollback de la transaction).
- **CA-4 (non-régression CQ-1, ordre des contrôles)** — *Étant donné* une ligne à `quantite = 2` avec un
  `override['identifiant']` qui **existe déjà** en tant que `BilletSupport` de type `Carte` (déclenche
  `RG-CQ1-01`), *quand* la vente est validée, *alors* le refus reste celui de `RG-CQ1-07` (message de
  recharge), pas celui de `RG-CQ8-02` — le contrôle de branche recharge est toujours évalué en premier,
  comportement CQ-1 strictement inchangé.
- **CA-5 (RG-CQ8-02, non-régression `quantite = 1`)** — *Étant donné* une ligne à `quantite = 1` avec un
  `override['identifiant']` explicite inédit, *quand* la vente est validée, *alors* un seul
  `BilletSupport` est créé portant exactement cet identifiant — comportement actuel strictement inchangé.
- **CA-6 (RG-CQ8-03, override type homogène)** — *Étant donné* une ligne à `quantite = 3` avec
  `override['type'] = 'qr'` (cas `ConfirmerCommandeHandler`), *quand* la vente est validée, *alors* les 3
  `BilletSupport` créés portent tous `type = TypeSupport::Qr`.
- **CA-7 (RG-CQ8-04, appairage indépendant, échec partiel)** — *Étant donné* une ligne à `quantite = 3`
  où l'implémentation d'`AppairageAccesInterface::appairer()` échoue pour le 2ᵉ appel seulement, *quand*
  la vente est validée, *alors* les 3 `BilletSupport` existent, le 2ᵉ a `statutAppairage = Echec`, les
  deux autres `Actif` — la vente reste validée et scellée (l'échec d'un appairage individuel ne bloque
  jamais la validation, comportement actuel inchangé).
- **CA-8 (RG-CQ8-05, scellement inchangé)** — *Étant donné* une vente avec une ligne à `quantite = 5` sur
  un produit émetteur, *quand* elle est validée, *alors* le payload scellé NF525 contient `qte: 5` pour
  cette ligne (déjà le cas avant ce lot) sans liste d'identifiants de support, et la fonction `payload()`
  n'a subi aucune modification de structure (vérifiable par diff).
- **CA-9 (RG-CQ8-06, consommateurs boutique, additif + non-régression)** — *Étant donné* une commande
  boutique en ligne avec une ligne à `quantite = 2` sur un billet, *quand* elle est confirmée après
  paiement (`ConfirmerCommandeHandler::confirmerApresPaiementReussi()`), *alors* (a) la réponse API expose
  2 entrées dans `vente.supports`, (b) le PDF généré par `GenerateurPdfBillet::genererPourVente()`
  contient 2 billets, (c) `ConfirmationCommandeMailer` annonce `nbBillets = 2` — sans qu'aucun de ces
  deux fichiers consommateurs n'ait été modifié.
- **CA-10 (RG-CQ8-01, non-régression générale `quantite = 1`)** — *Étant donné* n'importe quelle ligne à
  `quantite = 1` sur un produit émetteur, sans override, *quand* la vente est validée, *alors* le
  comportement est strictement identique à avant ce lot : un seul `BilletSupport` créé.
- **CA-11 (atomicité, non-régression RG-CQ1-08)** — *Étant donné* une ligne à `quantite = 3` où la
  génération d'identifiant échoue pour le 3ᵉ support (`ConflictHttpException`, collision épuisée après 5
  tentatives) ou le décrément de stock échoue avant la boucle, *quand* `valider()` est appelé, *alors*
  **aucun** des supports déjà construits en mémoire pour cette vente n'est persisté en base (rollback
  complet de la transaction DBAL), conformément au mécanisme déjà en place (`RG-CQ1-08`, non modifié par
  ce lot — seulement exercé sur davantage d'itérations).

## 8. Cas limites

- **Ligne nominative (`FACETTE_ACCES`) à `quantite > 1`.** `LigneVente.beneficiaire` reste un champ
  **unique** par ligne (`AjoutLigneHandler.php` ligne 79) : les N `BilletSupport` créés pour une telle
  ligne partageront tous, via `ligne.getBeneficiaire()`, le **même** bénéficiaire déclaré au niveau de la
  ligne — gap préexistant (aucun mécanisme actuel ne permet N bénéficiaires distincts pour une ligne),
  **non corrigé par ce lot** (hors périmètre ARGENT strict : le nombre de supports et le montant facturé
  redeviennent cohérents, la question « qui est le bénéficiaire du 2ᵉ support » reste ouverte pour un
  futur lot si l'exploitant vend des billets nominatifs par lot).
- **Génération de code et volume.** `genererIdentifiantUnique()` retente déjà jusqu'à 5 fois par support
  (`ValiderVenteService.php` lignes 302-313, inchangé) : N supports impliquent donc jusqu'à `5 × N`
  lectures de vérification d'unicité dans la même transaction — accepté, lié au risque de bornage
  (`RG-CQ8-07`, §5), pas un défaut de correction.
- **Ligne de recharge (`RG-CQ1-01`) à `quantite > 1`.** Refus `RG-CQ1-07` inchangé — cette branche
  retourne (`return null;`, ligne 271, précédée du refus ligne 253-257) **avant** d'atteindre le code
  modifié par ce lot ; CQ-8 ne touche pas cette branche.
- **Ventes historiques déjà scellées.** Les ventes validées **avant** le déploiement de ce correctif, où
  une ligne à `quantite > 1` n'a émis qu'un seul support, ne sont **pas** régularisées rétroactivement
  (pas de migration de données ici) — une régularisation manuelle (émission a posteriori des supports
  manquants, hors chaîne NF525 d'origine) est hors périmètre de ce lot et laissée à l'exploitant/support.
- **Produit à stock non géré et `quantite` très élevée.** Cf. `RG-CQ8-07` (§5) — aucun plafond n'est
  imposé par défaut ; risque de robustesse signalé, arbitrage A demandé, pas un défaut de correction
  ARGENT en soi.
- **Options de ligne (`ImpactOptionType`) sur une ligne à `quantite > 1`.** Le prix impacté par les
  options est déjà intégré dans `PanierCalculateur` avant multiplication par `quantite` (inchangé) — ce
  lot n'affecte pas la tarification, seulement le nombre de supports émis ; aucun cas limite nouveau côté
  options.

## 9. Dépendances

- **Dépend de** (existant, non modifié dans son comportement) :
  `App\Vente\Service\PanierCalculateur` (facturation déjà correcte),
  `App\Vente\Service\DecrementStockHandler` (décrémente déjà `quantite` par ligne, indépendant du nombre
  de supports),
  `App\Vente\Service\GenerateurCodeSupport` (génération/retry par support, réutilisé tel quel, N fois),
  `App\Vente\Port\AppairageAccesInterface` (appelé indépendamment par support, inchangé),
  `App\Vente\Nf525\ScellementHandler`/`payload()` (aucune modification requise, `RG-CQ8-05`).
- **Ordre des contrôles à préserver dans `creerSupport()`** : le test de branche recharge (`RG-CQ1-01`,
  identifiant existant + carte) doit rester évalué **avant** le nouveau garde-fou `RG-CQ8-02` (identifiant
  explicite + `quantite > 1` en émission), pour ne jamais transformer un refus de recharge légitime
  (`RG-CQ1-07`) en un refus `RG-CQ8-02` au message trompeur (CA-4).
- **Référencé par / bénéficie automatiquement de ce lot sans modification** :
  `App\Boutique\Billet\GenerateurPdfBillet`, `App\Boutique\Notification\ConfirmationCommandeMailer`,
  `App\Vente\Service\ContrePassationHandler` — les trois itèrent déjà génériquement `vente.getSupports()`
  (`RG-CQ8-06`).
- **Ne dépend pas** de CQ-1, CQ-5, CQ-7 pour être livrable et testable en autonomie ; réutilise seulement
  leur garde-fou (`RG-CQ1-07`) sans le modifier.
- **Point ouvert, arbitrage A demandé** : `RG-CQ8-07` (bornage `quantite` pour une ligne émettrice sur
  produit à stock non géré) — à trancher avant ou pendant le plan ; n'affecte ni le périmètre ni les CA
  1 à 11 ci-dessus, qui sont livrables indépendamment de cette décision.
- **Point ouvert, non bloquant** : la formule de `RG-CQ8-05` (payload NF525 sans liste d'identifiants)
  suppose qu'aucune exigence réglementaire nouvelle n'impose cette liste au niveau du scellement — à
  confirmer si un audit NF525 le requiert un jour (hors périmètre de ce lot, cf. §5).
