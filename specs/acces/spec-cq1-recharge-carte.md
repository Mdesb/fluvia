# Spec — CQ-1 : recharge d'une carte multi-entrées (`RG-CQ1-xx`)

- **Lot / module :** L3 · Accès (`App\Acces`) — déclenchée depuis L2 · Vente/Caisse (`App\Vente`)
- **Stories couvertes :** aucune `US-Lx-nn` dédiée au backlog ; lot issu de D23/D26
  (`COORDINATION/DECISIONS.md`), complément direct de US-L3-02 (appairage) et de la vente M2 existante
- **Règles de gestion :** RG-M1-04/13 (carte multi-entrées, stock initial), RG-ACC-01/02/07 (moteur de
  validation générique, non redéfini), + `RG-CQ1-01` à `RG-CQ1-10` (nouvelles, ce lot)
- **Décisions actées, non re-tranchées :** D23 (recharge sans changement de support, `versionMaj`),
  D26 (la recharge prolonge la validité — une période complète depuis la recharge, défaut livré),
  D3/D8 (cloisonnement à périmètre serveur), D2 (contract-first, pas d'appel direct module→module),
  D5 (anglais pour tout identifiant technique **nouveau**)
- **Statut :** brouillon

## 0. Ce que ce lot n'est pas (lire avant tout)

- **CQ-2** (claude-A) — la consultation en lecture seule du solde et la modale de caisse (scan → solde
  + 2 boutons D23) sont un **autre lot**. CQ-1 rend l'opération de recharge *possible et correcte* ;
  CQ-2 lui donne une surface d'agent de caisse. CQ-2 **dépend de** CQ-1 (il a besoin du mécanisme de
  déclenchement décidé ici pour savoir quoi appeler), pas l'inverse.
- **CQ-0** (claude-C, facultatif) — le rattachement d'un `DroitAcces` à un porteur CRM. La carte reste
  utilisable au porteur (anonyme) sans CQ-0 ; CQ-1 ne crée, ne lit ni ne suppose aucune relation CRM.
- **CQ-7** — le paramétrage « conserver la validité d'origine » vs « prolonger » est une **option du
  produit-carte**, pas un choix fait ici. CQ-1 livre le **défaut** (D26 : prolongation, période
  complète depuis la recharge) et conçoit `RG-CQ1-04` pour que CQ-7 n'ait qu'**une condition** à
  ajouter, pas une réécriture.
- **CQ-3** — la carte de N réservations (`TypeDroitAcces::Booking`, `creditRestant` aujourd'hui figé à
  `null` dans `ProjectionAccesReservationHandler`) est un mécanisme voisin mais un lot séparé.
- **CQ-4** — le nettoyage de la promesse creuse `propositionRecharge: ['caisse','borne','app']`
  (`App\Acces\State\PassageIngestionProcessor::reponse()` ligne 64) n'est pas traité ici. CQ-1 rend le
  canal « caisse » **réellement actionnable** pour la première fois, mais ne câble pas ce champ de
  réponse sur l'UI — c'est CQ-4/CQ-2.

## 1. Objectif

Qu'un agent de caisse puisse **recharger une carte multi-entrées déjà vendue** — augmenter son solde
d'entrées restantes — sans jamais produire un second support ni un second droit d'accès pour le même
porteur, que cette recharge **génère du chiffre d'affaires** comme n'importe quelle vente, et que le
terminal hors-ligne **voie le nouveau solde** au prochain rafraîchissement (`Support.versionMaj`).
Aujourd'hui, aucune de ces trois choses n'est vraie (§3).

## 2. Périmètre

- **Inclus :**
  - L'opération de recharge elle-même : détection du déclenchement (§5, `RG-CQ1-01`), incrément
    atomique de `DroitAcces.creditRestant` sur le droit **existant** (`RG-CQ1-02`), bascule de
    `Support.versionMaj` (`RG-CQ1-03`), calcul et écriture de la nouvelle échéance de validité selon le
    défaut D26 (`RG-CQ1-04`).
  - Le rattachement de la recharge à une `Vente` scellée NF525, comme n'importe quelle ligne de vente
    (`RG-CQ1-05`).
  - Le cloisonnement par établissement de bout en bout (`RG-CQ1-06`).
  - Les refus explicites sur les cas où la recharge ne peut pas s'appliquer proprement (`RG-CQ1-07`).
  - L'atomicité sous concurrence (deux recharges simultanées, ou une recharge simultanée à un passage
    qui décrémente) (`RG-CQ1-08`).
  - La frontière de module (nouveau port, direction de dépendance) (`RG-CQ1-09`).
  - La décision « comment ça se déclenche, et avec quelle permission » (§6).
- **Exclu (autres lots, ne pas refaire ici) :**
  - CQ-2 : lecture seule du solde + modale caisse (D13 : la modale est le défaut).
  - CQ-0 : rattachement du droit à un porteur CRM identifié.
  - CQ-7 : paramétrage produit « conserver vs prolonger » (CQ-1 livre uniquement le défaut prolonger).
  - CQ-3 : carte de N réservations (`TypeDroitAcces::Booking`).
  - CQ-4 : nettoyage de `propositionRecharge`.
  - La correction du référentiel M1 (`CarteMultiEntrees`) elle-même — inchangée, seulement lue.
  - Un historique dédié des recharges (type `MouvementPmv`) — la `Vente` rattachée *est* la trace ;
    un journal dédié est signalé en dépendance (§9), pas construit ici.

## 3. État actuel (ce qui existe, ce qui manque — code réel)

**Ce qui marche déjà (D23, vérifié) :**
- Vendre un produit-carte alimente `BilletSupport.nbCompostages` depuis
  `CarteMultiEntrees.getStockCompostagesInitial()` —
  `app/src/Vente/Service/ValiderVenteService.php` lignes 138-140 (`creerSupport()`).
- La projection construit un `DroitAcces` `sourceType = CarteQuota` avec `creditRestant` —
  `app/src/Acces/Projection/StubProjectionDroit.php` lignes 43-47.
- Chaque passage décrémente `creditRestant` par un `UPDATE` SQL conditionnel atomique (pas de
  read-modify-write) — `app/src/Acces/Service/ValidationPassageHandler.php` lignes 183-204, et bascule
  `Support.versionMaj` au même moment (ligne 199) via `VersionSnapshotSequencer::suivant()`.
- Le solde est renvoyé au terminal — `compostagesRestants` dans
  `app/src/Acces/State/SnapshotTerminalProvider.php` ligne 162 et
  `app/src/Acces/Service/AffichagePorteurResolver.php` ligne 67.
- Un seul appairage actif par support (`Appairage.supportActif`, contrainte unique) —
  `app/src/Acces/Entity/Appairage.php` lignes 27-28, 160-163.

**Ce qui manque, vérifié dans le code, et qui concerne CQ-1 :**

1. **Aucune recharge d'entrées n'existe.** `Recharge` n'existe que pour le porte-monnaie
   (`app/src/Crm/Service/PmvRechargeHandler.php`). `PassageIngestionProcessor::reponse()` (ligne 64)
   renvoie déjà `propositionRecharge: ['caisse','borne','app']` sur crédit épuisé — **trois canaux
   promis, zéro implémenté.**
2. **Le chemin existant crasherait, pas échouerait proprement.** `ValiderVenteService::creerSupport()`
   (lignes 112-143) prend un identifiant *override* fourni par la caisse
   (`$override['identifiant']`, ligne 130) et l'assigne **sans vérifier qu'il existe déjà** à un
   nouveau `BilletSupport`. Comme `vente_billet_support.identifiant_support` porte une contrainte
   unique (`app/src/Vente/Entity/BilletSupport.php` ligne 21), scanner aujourd'hui la carte d'un client
   pour la « recharger » via ce chemin **lèverait une violation de contrainte SQL au flush**, pas un
   message métier clair. C'est le seul point d'entrée qui ressemble à une recharge, et il est cassé.
3. **`AppairageAccesInterface` reste un stub.** `app/config/services.yaml` ligne 40 lie
   `App\Vente\Port\AppairageAccesInterface` à `App\Vente\Port\AppairageAccesStub`, qui ne fait que
   positionner `BilletSupport.statutAppairage = Actif` **sans jamais créer** de
   `App\Acces\Entity\Support`/`Appairage`/`DroitAcces` réels. L'appairage réel se fait aujourd'hui par
   un **second acte explicite**, `POST /acces/appairages` (`App\Acces\State\AppairageProcessor`), qui
   invoque `ProjectionDroitInterface::projeter()` pour construire le `DroitAcces`. **Conséquence directe
   pour CQ-1** : une carte n'est « rechargeable » au sens de ce lot qu'après être passée par cet
   appairage explicite — une carte vendue mais jamais appairée n'a **aucun** `DroitAcces` à incrémenter
   (traité en refus explicite, `RG-CQ1-07`).
4. **`DroitAcces.fenetreFin` n'est jamais écrit pour une carte.** `StubProjectionDroit::projeter()`
   (lignes 43-47) construit le droit `CarteQuota` sans jamais appeler `setFenetreDebut()`/
   `setFenetreFin()` — le commentaire de classe l'admet (« la fenêtre métier précise relève de M1,
   cf. Risque n°8 du plan »). `ResolveurMarges::estDansMarges()` (lignes 22-25) traite un droit sans
   fenêtre comme **sans contrainte de validité** : à ce jour, une carte multi-entrées vendue **n'expire
   jamais**, quel que soit `CarteMultiEntrees.validiteDuree`/`dateButoir`. C'est un point critique pour
   `RG-CQ1-04` (§5) : CQ-1 sera le **premier code** à jamais écrire `fenetreFin` pour ce type de droit.

## 4. Acteurs & droits

| Acteur | Peut | Permission (module × action) |
|---|---|---|
| Agent de caisse | Valider une vente qui recharge une carte existante (scanne l'identifiant en `supportsOverride`) | `vente.encaisser` (**existante, inchangée** — même permission que `POST /ventes/{id}/valider` aujourd'hui, `app/src/Vente/Entity/Vente.php` ligne 103) |
| Agent de caisse | Composer/encaisser la vente qui porte la recharge | `vente.creer` (existante, inchangée) |
| Agent d'accueil (hors CQ-1) | Consulter le solde résultant | `acces.lire` (existante, inchangée — via CQ-2) |
| Système (moteur de validation de passage) | Consommer le crédit rechargé | aucune (inchangé) |

**Ce lot ne crée aucune nouvelle permission ni aucun nouvel endpoint API.** Justification complète en
§6.

## 5. Comportements & règles

### Invariants centraux (D23) — à ne jamais violer

- **RG-CQ1-02 — Incrémenter, jamais recréer.** Une recharge **augmente** `DroitAcces.creditRestant` du
  droit **déjà appairé** au support scanné. Elle ne crée **jamais** de second `DroitAcces`, de second
  `Appairage`, ni de second `App\Acces\Entity\Support`. C'est ce qui garantit « pas de changement de
  support » (D23) : un support n'a qu'un appairage actif (`Appairage.supportActif`, contrainte unique) ;
  en créer un second imposerait de révoquer et refaire l'appairage, c'est-à-dire une nouvelle carte
  physique pour le client.
- **RG-CQ1-03 — `versionMaj` bascule à chaque recharge.** Le `App\Acces\Entity\Support` appairé au
  droit rechargé voit son `versionMaj` avancer via `VersionSnapshotSequencer::suivant()` — **même
  mécanisme, même service** que `ValidationPassageHandler` (ligne 199) et `AppairageHandler::appairer()`
  (ligne 71). Sans cette bascule, un lecteur hors-ligne qui n'a pas encore vu la recharge continuerait
  de refuser une carte qui vient d'être créditée (le delta du snapshot terminal, `SnapshotTerminalProvider`,
  n'est indexé que sur `versionMaj`, ligne 70).

### Déclenchement

- **RG-CQ1-01 — Détection du mode recharge.** Une ligne de vente déclenche une recharge (au lieu d'une
  émission de nouveau support) si, et seulement si, **les deux conditions suivantes sont réunies** au
  moment de `ValiderVenteService::creerSupport()` :
  1. Le `Produit` de la ligne porte une `CarteMultiEntrees` (`$produit->getCarte() !== null`) — comme
     aujourd'hui pour une émission normale.
  2. Un identifiant est fourni en *override* (`$override['identifiant']`) et correspond à un
     `BilletSupport.identifiantSupport` **déjà existant** dans l'établissement courant, dont le
     `type` est `TypeSupport::Carte`.

  Si la condition 2 n'est pas remplie (identifiant absent, ou inédit), le comportement **actuel** est
  inchangé : un nouveau `BilletSupport` est émis (cf. CA-8, non-régression). Si l'identifiant fourni
  existe déjà mais que la ligne 2 échoue sur autre chose (pas une carte, autre établissement, etc.), voir
  les refus explicites (`RG-CQ1-07`) — jamais de tentative silencieuse de créer un doublon.

  **Pourquoi ce déclencheur et pas un « produit de recharge » dédié** : cela ne nécessite aucune
  extension du référentiel M1 (`Produit`/`TypeProduit`). L'agent vend le **même** produit-carte (ou tout
  produit portant une `CarteMultiEntrees` compatible) une seconde fois ; c'est le fait de scanner un
  identifiant déjà connu, plutôt que d'en laisser générer un nouveau, qui fait basculer l'opération en
  recharge. ⚠ HYPOTHÈSE non bloquante : rien n'impose que le produit rechargé soit *identique* (même
  `Produit.id`) au produit d'origine — seulement qu'il porte une `CarteMultiEntrees`. Si l'exploitant
  veut un SKU « recharge » à un prix différent de l'émission initiale (support + première charge), le
  référentiel M1 peut déjà l'exprimer (un second `Produit` avec sa propre `CarteMultiEntrees` et son
  propre prix) sans aucune extension de schéma — à documenter en formation plutôt qu'en code.

### Nouvelle échéance de validité (D26, défaut)

- **RG-CQ1-04 — Prolongation, une période complète depuis la recharge (défaut D26).** Au moment de la
  recharge, `DroitAcces.fenetreFin` est recalculée ainsi, à partir de la `CarteMultiEntrees` du produit
  **vendu pour cette recharge** :
  - si `validiteDuree` est renseignée : `fenetreFin = maintenant + validiteDuree` ;
  - si `dateButoir` est également renseignée : `fenetreFin = min(maintenant + validiteDuree, dateButoir)` ;
  - si seule `dateButoir` est renseignée (`validiteDuree` nulle) : `fenetreFin = dateButoir` (plafond
    fixe, ne recule pas à chaque recharge) ;
  - `dateButoir` vaut ici la fin de ce jour, 23:59:59 à l'heure de l'établissement : c'est le dernier
    jour utilisable (décision de Maxime du 07/10/2026, `RG-M1-13`) ;
  - si aucune des deux n'est renseignée : `fenetreFin` reste `null` (carte sans expiration, comportement
    actuel inchangé).
  `DroitAcces.fenetreDebut` **n'est pas modifiée** par la recharge (elle reste `null` aujourd'hui pour
  une carte, cf. §3 point 4 — il n'existe pas de plancher de validité à faire avancer).
  **Point d'extension pour CQ-7** : cette règle doit être écrite comme *une seule* condition
  supplémentaire (« si `CarteMultiEntrees.conserverValiditeOrigine` est vrai, ne pas toucher
  `fenetreFin` ») — pas une réécriture du calcul.
  **Risque assumé, non traité ici (D26)** : recharger une seule entrée sur une carte à validité longue
  repart pour une période complète (« grignotage »). **Aucun garde-fou n'est ajouté** (minimum de
  recharge, plafond de prolongations cumulées) — décision explicite de D26, à ajouter seulement sur
  constat.
  ⚠ HYPOTHÈSE signalée, non bloquante pour ce lot mais à trancher en revue : `StubProjectionDroit`
  (émission initiale, §3 point 4) n'écrit **jamais** `fenetreFin`, y compris à la toute première vente
  d'une carte avec `validiteDuree` renseignée — c'est un gap préexistant, pas introduit par CQ-1. Une
  carte jamais rechargée reste donc **structurellement sans expiration** après ce lot, alors qu'une
  carte rechargée une fois en gagne une. C'est incohérent pour un même produit. **Recommandation** :
  factoriser le calcul de `RG-CQ1-04` dans un petit service partagé (p. ex.
  `App\Acces\Service\EcheanceCarteCalculateur`) et l'appeler **aussi** depuis `StubProjectionDroit` à
  l'émission initiale — correctif quasi gratuit, même formule, à valider au plan plutôt qu'à bloquer
  cette spec dessus.

### Vente rattachée

- **RG-CQ1-05 — La recharge génère du chiffre d'affaires comme une vente normale.** La recharge est
  portée par une `LigneVente` d'une `Vente` standard, encaissée et validée par le flux **existant**
  (`POST /ventes/{id}/paiements` puis `POST /ventes/{id}/valider`, `ValiderVenteService::valider()`),
  scellée NF525 dans la même transaction que toute autre vente (§2 du plan M2, inchangé). Il n'existe
  **aucun** flux de recharge « hors caisse » : pas de mouvement gratuit, pas d'ajustement admin. Le
  `BilletSupport` **d'origine** (rattaché à la toute première vente de la carte) n'est **pas modifié**
  par une recharge ultérieure — il reste la trace immuable de l'émission (`RG-M2-07`) ; la recharge
  produit sa **propre** `Vente`/`LigneVente`, qui est la trace de cette opération-là.
  `ValiderVenteService::creerSupport()` retourne `null` pour la ligne de recharge (aucun **nouveau**
  `BilletSupport` n'est ajouté à `$vente->addSupport()`) — même branche que le cas déjà géré aujourd'hui
  ligne 66-68 (`if ($support === null) { continue; }`).

### Cloisonnement

- **RG-CQ1-06 — Cloisonnement établissement (D3/D8), échec fermé.** L'identifiant scanné est fourni par
  le client (la caisse) : sa résolution en `BilletSupport` **et** en `Acces\Entity\Support` doit
  revérifier l'appartenance à l'établissement de la session serveur — jamais un `X-Etablissement`
  client. Un identifiant qui existe mais appartient à un autre établissement échoue **comme s'il
  n'existait pas** (mêmes 404 que `AppairageProcessor` lignes 79-82, pour ne pas transformer l'opération
  en oracle d'existence cross-tenant).

### Refus explicites

- **RG-CQ1-07 — La recharge refuse plutôt que de dévier de l'invariant.** Chacun des cas suivants
  produit un refus explicite (409/422 selon la nature), **jamais** une création silencieuse de doublon
  ni un crash de contrainte SQL :
  - identifiant scanné inconnu de `BilletSupport` **mais** déclaré comme override sur une ligne
    portant `getCarte() !== null` sans qu'aucun `BilletSupport` préexistant ne le porte → **ce n'est
    pas une recharge**, c'est une émission normale avec un identifiant choisi par la caisse (chemin
    déjà existant, inchangé, cf. CA-8) ;
  - identifiant scanné correspondant à un `BilletSupport` existant dont le `type` **n'est pas**
    `TypeSupport::Carte` (ex. un billet simple) → conflit explicite (le chemin actuel crasherait sur la
    contrainte unique, §3 point 2 — c'est une amélioration de robustesse, pas une régression) ;
  - le `BilletSupport` visé n'a **jamais** été appairé côté Accès (aucun `Acces\Entity\Support` ne porte
    cet `identifiant`, §3 point 3) → refus avec message explicite invitant à finaliser l'émission
    (`POST /acces/appairages`) avant de recharger — CQ-1 ne réimplémente pas l'appairage ;
  - le `Support` Accès trouvé est `Bloque` (perte/vol déclarée, `RG-ACC-07`) → refus (on ne recharge pas
    une carte déclarée perdue/volée) ;
  - le `DroitAcces` appairé actif a `statutProjection != Valide` (dévalidé) → refus ;
  - le `DroitAcces` appairé actif a `sourceType != CarteQuota` (ex. `Billet`, `Abonnement`, `Personnel`,
    `Booking`) → refus, la recharge ne s'applique qu'aux droits à crédit.

### Concurrence et atomicité

- **RG-CQ1-08 — Incrément atomique, jamais un read-modify-write ORM.** L'écriture de
  `creditRestant += n` **doit** passer par un `UPDATE` SQL conditionnel sur l'identifiant du droit,
  exactement comme le décrément de `ValidationPassageHandler` (lignes 188-191) :
  `UPDATE acces_droit_acces SET credit_restant = credit_restant + :n, fenetre_fin = :nouvelleEcheance
  WHERE id = UNHEX(:hex)`, puis mirage de la valeur sur l'objet en mémoire (même remarque que ligne
  195 : « pour éviter qu'un appelant relisant... via la map d'identité n'observe une valeur périmée »).
  **Pourquoi c'est non négociable** : `DroitAcces` ne porte pas de colonne `@ORM\Version` ; un
  `flush()` Doctrine classique après un `getCreditRestant() + n` en PHP écrirait une valeur **absolue**
  calculée sur une lecture potentiellement déjà périmée — deux recharges concurrentes, ou une recharge
  concurrente à un passage qui vient de décrémenter via SQL brut, produiraient une **perte
  d'incrément silencieuse** (l'un des deux écrasant l'autre). C'est exactement le risque que
  `ValidationPassageHandler` évite déjà côté décompte ; la recharge doit l'éviter symétriquement côté
  crédit.

### Frontière de module

- **RG-CQ1-09 — Nouveau port, pas d'import direct d'entités Accès dans Vente.** La détection du
  déclenchement (`RG-CQ1-01`, qui `BilletSupport` existe, quel `type`) reste **entièrement** dans
  `App\Vente` (c'est sa propre table, `vente_billet_support`). L'incrément réel
  (`DroitAcces`/`Support`/`Appairage`, qui appartiennent à `App\Acces`) passe par un **nouveau port**
  défini côté `App\Vente` — même patron que `App\Vente\Port\AppairageAccesInterface` déjà en place
  (`App\Vente\Service\ValiderVenteService` en dépend, ligne 38, sans jamais importer une entité
  `App\Acces\*`). `App\Vente` ne connaît toujours, après ce lot, **aucune** entité de `App\Acces`.
  L'implémentation réelle du port vit dans `App\Acces` (elle, en revanche, peut légitimement lire/écrire
  ses propres entités `Support`/`Appairage`/`DroitAcces` directement — pas de stub nécessaire ici,
  contrairement à `AppairageAccesStub` : Accès est le propriétaire naturel de cette logique).

## 6. Décision — comment la recharge se déclenche, et permission

**Option retenue : A — recharger, c'est vendre.** Aucun nouvel endpoint, aucune nouvelle permission.
La recharge emprunte le flux **existant** `POST /ventes/{id}/valider`
(`App\Vente\State\ValiderVenteProcessor`, sécurisé par `vente.encaisser`, déjà déclaré dans
`app/src/Vente/Entity/Vente.php` ligne 103 — fichier existant, inchangé par ce lot) avec le corps
`supportsOverride` **déjà supporté** aujourd'hui (`{"supports": [{"ligne": uuid, "identifiant": "…"}]}`,
`ValiderVenteProcessor` lignes 35-43). Ce qui change, c'est **l'interprétation** de cet identifiant côté
`ValiderVenteService::creerSupport()` (§5, `RG-CQ1-01`) — un identifiant déjà connu bascule en recharge
au lieu de tenter (et échouer) une création.

**Option B écartée (endpoint dédié `POST /acces/.../recharger`)** : rejetée, pour trois raisons.
1. Elle dupliquerait tout ce que le flux de vente fait déjà et doit continuer à faire pour qu'une
   recharge soit *une vente* (`RG-CQ1-05`) : calcul de prix, encaissement scindé, scellement NF525,
   décrément de stock (`DecrementStockHandler`, générique, inchangé), impression au-dessus du seuil.
   Un second endpoint devrait soit rappeler `ValiderVenteService` en interne (aucun gain), soit
   réimplémenter une fraction de son comportement (dérive garantie).
2. Elle introduirait un choix de permission désagréable : soit une **nouvelle** permission
   (ex. `acces.recharger`), soit une **référence** à une permission française déjà existante
   (`vente.encaisser`) mais **dans un fichier neuf** — c'est précisément le cas que le garde-fou de
   nommage actuel bloque (référence à une permission française existante depuis un fichier ajouté).
   Avec l'Option A, l'endpoint et son attribut `security` restent dans un fichier **déjà en place**
   (`Vente.php`), donc **hors du diff** que le garde-fou inspecte — aucune dépendance à son
   assouplissement en cours côté claude-C.
3. Elle romprait `RG-CQ1-05` par construction : un endpoint dédié qui ne passe pas par
   `ValiderVenteService::valider()` ne produirait ni `Vente` ni scellement NF525 sans un travail
   spécifique pour les recréer — la vente rattachée deviendrait une reconstruction plutôt qu'une
   réutilisation.

**Conséquence pour les fichiers neufs de ce lot** (le port `App\Vente\Port\CardRechargeInterface` et son
implémentation `App\Acces\Service\CardRechargeHandler`, cf. `RG-CQ1-09`) : ce sont de **simples classes
de service**, sans attribut `#[ApiResource]` ni chaîne `is_granted('PERM', …)` — elles ne déclarent
aucune permission, française ou anglaise, neuve ou existante. Le risque de friction avec le garde-fou de
nommage est donc **nul par construction**, pas seulement minimisé. Seuls les noms de classes/méthodes de
ces deux fichiers neufs doivent être en anglais (D5) — proposition : `CardRechargeInterface::recharge(
BilletSupport $support, int $credits): bool` côté port, `CardRechargeHandler` côté implémentation ;
nommage exact laissé au plan, cohérent avec la tension déjà documentée dans `spec-acc3-projection-reservation.md`
§5 (D5 vs cohérence locale française du module `App\Acces`).

## 7. Événement de domaine — proposition

Aucun événement `access.*` n'est publié aujourd'hui par `App\Acces` (vérifié : aucune occurrence
d'`EventDispatcherInterface`/`App\Platform\Event\EventBus` dans `app/src/Acces`). Ce lot serait donc le
**premier** émetteur d'événement du module Accès.

**Proposition, à ajouter au catalogue (`COORDINATION/CONTRACT/catalogue-evenements.md`) avant
implémentation, pas pendant :**

| Événement | Émis par | Payload clé | Consommateurs probables |
|---|---|---|---|
| `access.card_recharged` | `App\Acces\Service\CardRechargeHandler` | `droitId`, `supportId`, `creditsAdded`, `creditBalanceAfter`, `newExpiryAt?`, `saleId` | Reporting (fréquentation/revenu), CRM (si CQ-0 rattache un porteur), Smart Flow (relance côté carte bientôt épuisée, hypothétique) |

Publié **après commit** (D7-bis), depuis `CardRechargeHandler`, une fois l'incrément SQL effectué —
pas depuis `ValiderVenteService` (qui ne connaît pas la sémantique « recharge », seulement « vente »).
⚠ HYPOTHÈSE non bloquante : cet événement n'est **pas indispensable** aux critères d'acceptation de ce
lot (aucune CA n'en dépend) — il est proposé parce que D22 a montré que retarder l'émission d'un
événement jusqu'à avoir un consommateur concret produit des modules entiers construits sur des
événements qui n'existent jamais. L'ajouter maintenant, à la source, coûte une ligne de catalogue et un
appel `publish()` ; ne pas l'ajouter laisse un point d'observation manquant que CQ-2 ou le Reporting
redécouvriront plus tard.

## 8. Objets de données

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| `DroitAcces` (existant, `App\Acces\Entity\DroitAcces`) | `creditRestant` | `?int` | incrément atomique SQL (`RG-CQ1-08`) | jamais lu-puis-réécrit en PHP |
| | `fenetreFin` | `?\DateTimeImmutable` | recalculée à chaque recharge si `validiteDuree`/`dateButoir` présents (`RG-CQ1-04`) | **premier code à l'écrire** pour ce type de droit (§3 pt.4) |
| | `fenetreDebut` | `?\DateTimeImmutable` | **non modifiée** par la recharge | reste `null` aujourd'hui pour `CarteQuota` |
| `App\Acces\Entity\Support` (existant) | `versionMaj` | `int` | `+ 1` logique via `VersionSnapshotSequencer::suivant()` à chaque recharge (`RG-CQ1-03`) | pilote le delta du snapshot terminal |
| `App\Vente\Entity\BilletSupport` (existant) | — | — | **non modifié** par une recharge (`RG-CQ1-05`) | reste la trace de l'émission d'origine ; la recharge a sa propre `Vente`/`LigneVente` |
| `App\Vente\Entity\Vente` / `LigneVente` (existant) | — | — | une recharge = une `Vente` standard | chiffre d'affaires, scellement NF525, inchangés |
| `App\Vente\Port\CardRechargeInterface` (**nouveau**) | `recharge(BilletSupport $support, int $credits): bool` | interface | définie côté `App\Vente`, implémentée côté `App\Acces` | miroir de `AppairageAccesInterface` (`RG-CQ1-09`) |
| `App\Acces\Service\CardRechargeHandler` (**nouveau**) | — | classe de service | implémente `CardRechargeInterface`, réel dès ce lot (pas de stub) | contient l'`UPDATE` atomique (`RG-CQ1-08`) et l'appel au séquenceur (`RG-CQ1-03`) |
| `CarteMultiEntrees` (existant, `App\Offre\Entity`) | `validiteDuree`, `dateButoir` | `?\DateInterval`, `?\DateTimeImmutable` | **lus seuls**, non modifiés | source du calcul `RG-CQ1-04` |
| Événement `access.card_recharged` (**proposé**, §7) | — | `DomainEvent` | à ajouter au catalogue avant implémentation | non bloquant pour les CA de ce lot |

Aucune migration de schéma n'est requise : `credit_restant`, `fenetre_fin`, `version_maj` existent déjà
en base. Un champ additionnel « date de dernière recharge » sur `DroitAcces` serait utile pour un futur
historique côté CQ-2 mais n'est **pas nécessaire** aux CA de ce lot — laissé en dépendance ouverte (§9).

## 9. Critères d'acceptation

- **CA-1 (RG-CQ1-01/02, invariant central)** — *Étant donné* une carte déjà vendue et appairée
  (`DroitAcces.sourceType = CarteQuota`, `creditRestant = 3`), *quand* un agent valide une nouvelle
  vente d'un produit-carte en fournissant l'identifiant existant en `supportsOverride`, *alors* le
  **même** `DroitAcces` (même `id`) voit son `creditRestant` passer à `3 + credité`, et **aucun**
  second `DroitAcces`, `Appairage` ni `Support` n'existe pour ce porteur après l'opération (le compte
  de lignes reste strictement inchangé sur ces trois tables, hors la mise à jour du droit existant).
- **CA-2 (RG-CQ1-03, invariant central)** — *Étant donné* le scénario CA-1, *quand* la recharge est
  validée, *alors* `Support.versionMaj` du support Accès appairé est strictement supérieur à sa valeur
  d'avant recharge, et un `GET /terminal/snapshot?depuis=<ancienne_version>` postérieur inclut ce
  support avec le `compostagesRestants` mis à jour.
- **CA-3 (RG-CQ1-04, D26 défaut)** — *Étant donné* un produit-carte `validiteDuree = P1Y` et une carte
  dont `fenetreFin` actuelle est dans 3 jours, *quand* une recharge est validée le jour J, *alors*
  `DroitAcces.fenetreFin` devient `J + 1 an` (pas « ancienne échéance + 1 an »).
- **CA-4 (RG-CQ1-04, plafond)** — *Étant donné* un produit-carte avec `validiteDuree = P1Y` et
  `dateButoir` = dans 2 mois, *quand* une recharge est validée, *alors* `fenetreFin` est plafonnée à
  `dateButoir` (pas repoussée à un an).
- **CA-5 (RG-CQ1-05, vente rattachée)** — *Étant donné* une recharge validée, *quand* on consulte la
  `Vente` qui l'a portée, *alors* elle apparaît comme une vente standard scellée NF525 (numéro, ligne,
  montant, moyen de paiement), comptée dans le chiffre d'affaires de l'établissement — pas une écriture
  hors caisse.
- **CA-6 (RG-CQ1-06, cloisonnement)** — *Étant donné* une carte appairée sur l'établissement A, *quand*
  un agent scopé sur l'établissement B tente de la recharger avec l'identifiant connu, *alors*
  l'opération échoue (identifiant traité comme inconnu) et **aucun** champ du droit de l'établissement A
  n'est modifié.
- **CA-7 (RG-CQ1-08, concurrence)** — *Étant donné* deux recharges valides concurrentes sur le même
  droit (`+5` et `+3`), *quand* les deux transactions s'exécutent en parallèle, *alors* le solde final
  reflète les deux incréments (`+8` au total, aucun perdu) — vérifié par un test qui déclenche les deux
  écritures sans les sérialiser côté application.
- **CA-8 (RG-CQ1-07, refus — jamais appairée)** — *Étant donné* un `BilletSupport` de type `Carte` sans
  aucun `Acces\Entity\Support` correspondant (jamais appairé), *quand* l'agent tente de le recharger,
  *alors* la validation de vente échoue avec un message explicite invitant à appairer d'abord — aucun
  `DroitAcces` n'est créé en repli.
- **CA-9 (RG-CQ1-07, refus — support bloqué / droit dévalidé)** — *Étant donné* un support marqué
  `Bloque` (perte/vol) ou un droit `statutProjection = Devalide`, *quand* une recharge est tentée,
  *alors* elle est refusée explicitement, sans modification du solde.
- **CA-10 (non-régression)** — *Étant donné* une vente normale d'un produit-carte **sans** override
  d'identifiant existant (nouvelle carte, ou identifiant inédit fourni), *quand* elle est validée,
  *alors* le comportement actuel est strictement inchangé : un nouveau `BilletSupport` est créé avec
  `nbCompostages` = stock initial du produit — ce lot n'affecte pas ce chemin.

## 10. Cas limites

- **Ligne de recharge avec `quantite > 1`.** Le comportement de l'émission initiale ne multiplie pas
  `nbCompostages` par `ligne.getQuantite()` (`ValiderVenteService::creerSupport()` ligne 139) : ce lot
  **mirore** ce choix pour la recharge — le crédit ajouté est celui de la `CarteMultiEntrees` du produit
  vendu, indépendamment de `quantite`. ⚠ HYPOTHÈSE : si l'exploitant veut « recharger 3 packs de 10 en
  une ligne », ce n'est **pas** couvert par ce lot (à traiter, le cas échéant, en multipliant le crédit
  par la quantité — écart mineur, laissé au plan si le besoin est confirmé).
- **« Ajouter des entrées à l'unité » (bouton D23, distinct du forfait complet).** Le mécanisme
  d'incrément (`RG-CQ1-02/03/08`) est générique — il accepte n'importe quel entier positif de crédit à
  ajouter. Ce lot ne construit **pas** de produit/prix « à l'unité » ni le bouton correspondant (D13,
  modale = CQ-2) ; il garantit seulement que le mécanisme d'incrément sous-jacent fonctionnera
  identiquement, quel que soit ce qui détermine le nombre d'unités côté vente.
- **Grignotage de validité (D26, risque assumé).** Rappel explicite : recharger une seule entrée sur une
  carte à validité longue repart pour une période complète. **Ne pas** ajouter de garde-fou (minimum de
  recharge, plafond de prolongations) dans ce lot — décision actée, à ajouter seulement sur constat.
- **Carte sans `validiteDuree` ni `dateButoir`.** `fenetreFin` reste `null` après recharge — carte
  illimitée, comportement actuel inchangé (`RG-CQ1-04`).
- **Annulation/avoir sur une vente de recharge.** Hors périmètre de ce lot (non traité, non testé) :
  `App\Vente\Service\ContrePassationHandler` sait aujourd'hui invalider un `BilletSupport` **émis**
  (`AppairageAccesInterface::invalider()`) mais ne sait rien retirer d'un `DroitAcces` déjà incrémenté —
  annuler une vente de recharge après validation **ne retire pas** le crédit ajouté aujourd'hui. Signalé
  en dépendance (§11), pas résolu ici : le risque existe déjà symétriquement côté PMV
  (`PmvRechargeHandler` n'a pas non plus de contre-passation dédiée).
- **Identifiant scanné qui n'est ni un `BilletSupport` connu ni un format valide.** Comportement
  identique au chemin actuel pour une émission avec override manuel — aucune régression introduite.

## 11. Dépendances

- **Dépend de** (existant, non modifié dans son comportement) : `App\Vente\Service\ValiderVenteService`
  (méthode `creerSupport()` étendue, pas réécrite), `App\Vente\Service\DecrementStockHandler` (le stock
  du produit rechargé est décrémenté comme toute vente, générique, inchangé),
  `App\Acces\Service\VersionSnapshotSequencer`, `App\Acces\Entity\Appairage`,
  `App\Acces\Service\AppairageHandler` (patron d'atomicité répliqué, pas appelé directement).
- **Précédent direct répliqué** : `App\Crm\Service\PmvRechargeHandler` — patron « recharge rattachée à
  une transaction, journalisée » ; la différence assumée est que CQ-1 n'a pas besoin d'un mouvement
  dédié (`MouvementPmv`) car la `Vente`/`LigneVente` elle-même est déjà la trace de l'opération (RG-CQ1-05).
- **Dépendance non bloquante mais réelle** : `AppairageAccesInterface` reste un **stub**
  (`app/config/services.yaml` ligne 40) — une carte n'est rechargeable, au sens de ce lot, qu'après un
  appairage explicite réel (`POST /acces/appairages`). Les fixtures de test de ce lot doivent donc
  composer : vente initiale → appairage explicite → **puis** recharge. Ce n'est pas un défaut introduit
  par CQ-1, mais une précondition à documenter clairement dans les tests d'intégration.
- **Référencé par / attendu par** : CQ-2 (modale caisse, boutons « recharger le forfait courant » /
  « ajouter à l'unité », D23) construira son bouton « recharger le forfait courant » directement sur le
  mécanisme livré ici (`RG-CQ1-01/02/03/04/05`). CQ-4 (nettoyage de `propositionRecharge`) pourra
  s'appuyer sur le fait que le canal « caisse » est désormais réellement actionnable, sans changer ce
  lot. CQ-7 (paramétrage conserver/prolonger) ajoute **une condition** dans `RG-CQ1-04`, pas une
  réécriture.
- **Risque — gap préexistant sur l'échéance de l'émission initiale** (§5, `RG-CQ1-04`) :
  `StubProjectionDroit` n'écrit jamais `fenetreFin` à l'émission d'une carte, y compris quand
  `CarteMultiEntrees.validiteDuree` est renseignée. Recommandé (non bloquant) : factoriser et réutiliser
  le calcul de `RG-CQ1-04` depuis ce point d'entrée aussi, pour éviter l'incohérence « carte jamais
  rechargée = jamais d'expiration, carte rechargée une fois = expiration soudaine ».
  ⚠ à trancher au plan.
- **Risque — pas d'historique dédié des recharges.** Contrairement au PMV (`MouvementPmv`), ce lot ne
  crée pas de table de mouvements pour les recharges de carte — l'historique existe via les `Vente`
  successives portant une ligne du même produit-carte, mais retrouver « toutes les recharges de cette
  carte » demande une jointure par `identifiantSupport`, pas une relation directe. Si CQ-2 a besoin d'un
  historique de recharges affiché en modale, ce point est à rouvrir à ce moment-là.
- **Ne dépend pas** de CQ-0, CQ-3, CQ-7 pour être livrable et testable en autonomie.
- **Dépend de la validation du catalogue d'événements** (§7) si l'événement `access.card_recharged` est
  retenu — sinon ce point est simplement retiré du plan sans impact sur les CA.
