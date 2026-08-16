# Spec — Boutique en ligne & tunnel d'achat (`M3` / lot `L8`)

- **Lot / module :** L8 · M3 Boutique en ligne & App client
- **Stories couvertes :** **US-L8-01 à US-L8-14** — ⚠ **HYPOTHÈSE** : `backlog.html` ne comporte **aucun
  onglet `L8`** (les onglets vont de `t-l0` à `t-l7`, cf. constitution §5 « ordre de construction des
  lots MVP ») et **aucune story `US-L8-*` n'existe dans les sources**. Les stories listées ci-dessous
  sont **définies par cet agent** à partir des 5 écrans du panel `p-m3` du cahier détaillé
  (M3-01 à M3-05), des `RG-M3-01` à `10`, des décisions actées « M3 · Boutique & App client » (onglet
  ★ Décisions) et des points explicitement demandés par le commanditaire (click & collect, connecteur
  OTA, remboursement). **À faire valider, numéroter et chiffrer officiellement** dans le backlog avant
  développement (même réserve que `spec-reservation.md` pour `US-RES-*`).
- **Règles de gestion :** `RG-M3-01` à `RG-M3-10` (source `cahier-detaille.html`, panel `p-m3`,
  reprises **verbatim**) + `RG-M3-11` à `RG-M3-18` (**nouvelles**, cf. §4, généralisant les décisions
  actées « M3 · Boutique & App client » et l'articulation avec M6/PayFiP-CB, la SEPA en ligne et le
  click & collect explicitement demandés par le commanditaire). Règles socle/modules **réutilisées, non
  redéfinies** : `RG-SOCLE-01` à `07`, `RG-M1-01/03/04/07/09/10/12/13` (`spec-offre.md`),
  `RG-M2-01` à `08` (`spec-vente.md`), `RG-M5-01` à `12`/`RG-RES-*` (`spec-reservation.md`),
  `RG-ACC-01` à `07` (`spec-acces.md`), `RG-M4-01` à `10` (`spec-crm.md`), `RG-M6-01` à `10` /
  `RG-PAYFIP-03` (`spec-compta.md`), `RG-MUS-04` (`specs/musee/spec-musee.md`, partenaires OTA).
- **Statut :** brouillon — plusieurs points d'intégration externe (FranceConnect, PSP CB, connecteur
  technique OTA) `⚠ À CADRER` avant tout plan technique (cf. §8 et récapitulatif final).

## 1. Objectif
Permettre à un établissement de **vendre 24/7** via une **vitrine white-label** propre à son identité,
en conduisant le client (invité, titulaire de compte ou identifié via **FranceConnect**) d'un **panier**
à un **billet QR/wallet immédiatement utilisable**, en réutilisant sans les redéfinir le catalogue (M1),
la réservation de créneau timed-entry (module `reservation`), la vente/le paiement (M2/M6) et le
contrôle d'accès (module Accès) — tout en offrant un **espace client** et une **app mobile self-service**
(billets, abonnements, réservation de cours, recharge, notifications) qui font de la boutique le point
d'entrée numérique unique du client final.

## 2. Périmètre
- **Inclus (couvert par US-L8-01 à 14) :**
  - **Vitrine white-label** par établissement : catalogue, filtres/tri, promos, multilingue, panier
    persistant — US-L8-01, RG-M3-01/08, écran M3-01.
  - **Fiche produit en ligne** + **choix de créneau timed-entry** avant ajout au panier — US-L8-02,
    RG-M3-02/08, écran M3-02.
  - **Panier** avec **réservation temporaire du stock** (produit et/ou créneau), délai configurable par
    établissement, libération et relance à expiration — US-L8-03, RG-M3-03/16, décision actée « Abandon
    de panier ».
  - **Tunnel de commande en 4 étapes** : identification (compte / invité / FranceConnect), bénéficiaires,
    paiement, confirmation — US-L8-04/05/06/07/08, écran M3-03, RG-M3-06/07/10/11/12/13/14.
  - **Paiement en ligne commuté** PayFiP (public) / CB-PSP (privé) selon le régime de l'exploitant —
    US-L8-07, RG-M3-11.
  - **Émission du billet** (QR dynamique + pass wallet, repli QR automatique si wallet indisponible) et
    **confirmation e-mail** — US-L8-08, RG-M3-04/14.
  - **Achat d'abonnement avec mandat SEPA en ligne**, réservé aux **titulaires de compte** (pas d'invité)
    — US-L8-09, RG-M3-12/17.
  - **Espace client web** : historique de commandes, billets/factures, famille, abonnements & SEPA,
    consentements — US-L8-10, écran M3-04.
  - **App mobile client** : badge QR dynamique + wallet (fonctionne hors-ligne côté tourniquet),
    abonnement, carte, réservation de cours, recharge du porte-monnaie virtuel, notifications push —
    US-L8-11, écran M3-05, RG-M3-04/05.
  - **Demande de remboursement en ligne** (formulaire, jamais d'automatique) — US-L8-12, RG-M3-15,
    décision actée « Remboursement en ligne ».
  - **Retrait / click & collect** d'un support physique (carte, bracelet RFID) commandé en ligne —
    US-L8-13, RG-M3-18 (⚠ nouvelle, non explicitement couverte par le cahier, cf. §4.13).
  - **Connecteurs OTA** : activation par établissement, contrôle d'inventaire partagé, reversements —
    US-L8-14, RG-M3-09.
- **Exclu (pour l'instant), que L8 *référence* seulement :**
  - **Définition des produits, tarifs, grilles, saisons, promotions, stocks, canaux** → **M1** (L1,
    `spec-offre.md`, RG-M1-01/07/09/10). L8 **consomme** le prix résolu et la visibilité canal
    `en_ligne` ; ne les redéfinit pas.
  - **La ressource, le créneau timed-entry, la jauge, la liste d'attente, le no-show** → module
    `reservation` (`spec-reservation.md`). L8 **consomme** `Creneau`/`Ressource`/`Reservation` pour le
    choix de créneau en fiche produit (§4.2) et **déclenche** la création d'une `Reservation` confirmée
    au paiement réussi ; ne réimplémente pas le moteur générique de réservation.
  - **L'encaissement lui-même (session de caisse, TPE, ticket physique, appairage RFID en caisse), la
    Commande/Vente, l'Avoir** → **M2** (L2, `spec-vente.md`). La **Commande en ligne est une Vente M2**
    (canal `en_ligne`) : L8 **compose** le panier et **déclenche** la vente à distance (« Click & Pay »,
    déjà signalé hors périmètre L2 §2/§8, rattaché ici) ; M2 en reste le **modèle canonique**
    (statuts, contre-passation, NF525).
  - **Le contrôle d'accès physique au tourniquet** (validation, jauge FMI, anti-passback, hors-ligne) →
    module **Accès** (L3, `spec-acces.md`). L8 **émet** le support (QR/wallet) et **déclenche**
    l'appairage ; l'Accès **valide** le passage, ne redéfinit rien ici.
  - **La fiche client 360°, la famille, le porte-monnaie virtuel, les consentements RGPD comme objet,
    la fusion de doublons** → **M4/CRM** (L5, `spec-crm.md`). L8 **crée/rattache** un `Client` (M4) au
    moment de la création de compte et **consomme** le `Consentement` et le `PMV` ; ne les redéfinit pas.
  - **Le référentiel des moyens de paiement, le profil exploitant (régie/DSP/privé), PayFiP, la
    conformité NF525/TVA** → **M6** (L4, `spec-compta.md`). L8 **consomme** la commutation
    `ProfilExploitant.type` pour choisir PayFiP ou CB/PSP (RG-M6-01, RG-PAYFIP-03) ; ne les redéfinit
    pas.
  - **Le mandat SEPA en tant qu'objet** (IBAN tokenisé, RUM, remise pain.008) → module **`App\Sepa`**
    (`specs/sepa/plan-sepa.md`). L8 **déclenche** la collecte du mandat au moment de l'achat
    d'abonnement en ligne ; ne réimplémente pas le moteur de prélèvement.
  - **Le connecteur technique OTA** (protocole d'échange, format, fréquence de synchronisation avec les
    plateformes revendeurs) — ⚠ **non spécifié dans ce dépôt à ce jour** (déjà signalé comme gap de
    dépendance par `specs/musee/spec-musee.md` §8 point 2). L8 est le **propriétaire fonctionnel** de la
    capacité générique « connecteur OTA » (§4.14) ; le protocole lui-même reste `⚠ À CADRER` (cf.
    récapitulatif final).
  - **L'UI (front)** ; l'**authentification interne staff, les rôles/permissions et le journal d'audit**
    → **socle L0** (`spec-socle.md`), réutilisés et non redéfinis ici. **L'authentification client final**
    (compte/FranceConnect) est en revanche **spécifiée ici** (§4.4), car c'est un mécanisme distinct de
    l'authentification staff du socle.

## 3. Acteurs & droits
Les permissions staff réutilisent le modèle du socle (`RG-SOCLE-02/03/04/05`) : couple `module × action`
sur le module **`boutique`**, portées par l'**établissement actif** ; l'UI **masque** ce qui n'est pas
autorisé (`RG-SOCLE-04`). Le **client final** n'est pas un utilisateur staff : il porte des permissions
**sur ses propres données uniquement** (`_soi`), à la manière du modèle déjà retenu en L5/CRM
(`crm × lire_soi`, cf. `spec-crm.md` §3). Source : cahier `p-m3` §2 (tableau Acteurs & droits, repris).

| Acteur | Peut | Ne peut pas | Permission (module × action) |
|---|---|---|---|
| **Client final (invité)** | Parcourir la vitrine, filtrer/trier, consulter fiches et disponibilité, ajouter au panier, s'identifier ou créer un compte, payer un **achat simple** (billet, entrée) | Acheter un **abonnement/SEPA** sans compte (RG-M3-12) ; accéder à l'historique/aux abonnements sans compte ; modifier produits ou prix | `boutique × acheter_invite` |
| **Client final (titulaire de compte)** | Voir ses commandes/billets, gérer sa famille, ses abonnements & SEPA, ses consentements ; réserver un cours, recharger son PMV, obtenir le badge mobile, demander un remboursement | Voir les données d'un autre compte ; réserver au-delà du quota de sa formule sans bascule vente à l'unité | `boutique × acheter_soi`, `boutique × lire_soi`, `boutique × gerer_famille_soi`, `boutique × demander_remboursement_soi` |
| **Gestionnaire** | Personnaliser la vitrine (logo, couleurs, langues), activer/désactiver canaux et connecteurs OTA, publier des promos, paramétrer le délai d'expiration du panier | Modifier une commande payée ; accéder aux moyens de paiement enregistrés des clients ; traiter une demande de remboursement (droit dédié, cf. Responsable) | `boutique × gerer_vitrine`, `boutique × gerer_promo`, `boutique × gerer_connecteur_ota`, `boutique × lire` |
| **Responsable** *(⚠ HYPOTHÈSE, cf. modèle `vente × rembourser` L2)* | Traiter une demande de remboursement en ligne (acceptation → avoir M2, refus motivé), traiter une déclaration de retrait click & collect en attente | — | `boutique × traiter_remboursement`, `boutique × traiter_retrait` |
| **Administrateur** | Configurer plusieurs établissements, gérer connecteurs/inventaire/reversements OTA, superviser la conformité RGPD du tunnel | Contourner le consentement RGPD ; supprimer des justificatifs légaux | `boutique × gerer` (surensemble), `securite × gerer` (socle, délégation) |
| **Système** | Faire expirer un panier après le délai configuré et libérer le stock, relancer par e-mail/push, promouvoir une liste d'attente réservation, rapprocher un retour PayFiP/PSP, synchroniser l'inventaire OTA | Décider hors des règles paramétrées | *(acteur technique — pas de permission humaine)* |

- ⚠ HYPOTHÈSE — Les noms de permissions `boutique × …` déclinent le tableau « Acteurs & droits » du
  cahier M3-§2 selon le modèle `module × action` du socle, mais ne sont **pas nommés littéralement**
  dans les sources ; à **arbitrer avec M8** (même réserve que dans toutes les specs de ce dépôt).
- ⚠ HYPOTHÈSE — Le rôle **Responsable** (traitement des demandes de remboursement / retraits) n'apparaît
  pas nommément au tableau du cahier M3-§2 (qui ne distingue que Client/Gestionnaire/Administrateur) ;
  retenu par analogie avec `vente × rembourser` (`spec-vente.md` §3) pour ne pas confier ce droit
  sensible au Gestionnaire par défaut — **à arbitrer avec M8**.

## 4. Comportements & règles
Chaque comportement trace une **RG-M3** (source `cahier-detaille.html`, panel `p-m3`, ou **nouvelle**
règle de ce lot) et/ou une **US-L8**.

### 4.1 Vitrine white-label & catalogue en ligne (US-L8-01, RG-M3-01/08)
- **RG-M3-01** — La vitrine est **white-label par établissement** : identité visuelle (logo, couleurs),
  langues et canaux propres, **sans marque de l'éditeur**.
- **RG-M3-08** — **Prix et disponibilité** sont affichés **en temps réel** sur toute la boutique et
  l'app, sans décalage avec le catalogue M1 ni le stock/la jauge du créneau.
- **Catalogue** — Filtres (catégorie, activité, public) et tri (prix, popularité, disponibilité) ; le
  catalogue **ne montre** que les produits **publiés** et **visibles au canal `en_ligne`** (RG-M1-07/09,
  `spec-offre.md`).
- **Bandeau promos** et mises en avant issues des promotions M1 éligibles au canal en ligne ; sélecteur
  de langue ; panier persistant (visible depuis n'importe quel écran de la vitrine).
- **Connexion / création de compte / FranceConnect** accessibles depuis l'en-tête (§4.4).
- ⚠ **Accessibilité** — En tant que **surface publique**, la vitrine est soumise à **RGAA/WCAG 2.2 AA**
  (constitution §4 point 5) ; aucune US ne détaille ce point au-delà du principe, à couvrir au plan
  technique/design.

### 4.2 Fiche produit & choix de créneau timed-entry (US-L8-02, RG-M3-02/08)
- **RG-M3-02** — Un produit **timed-entry impose le choix d'un créneau** avant l'ajout au panier ; la
  **liste des créneaux disponibles** est fournie par le module `reservation` (`Creneau`, `Ressource`,
  cf. `spec-reservation.md` §5) — L8 **consomme**, ne redéfinit pas la notion de créneau/capacité.
- **Disponibilité « Reste : n »** — Compteur temps réel ; pour un produit timed-entry, c'est le **stock
  du créneau choisi** (`Creneau.capacité` moins occupation) qui est affiché ; pour un produit simple,
  c'est le **stock M1** (`RG-M1-10`).
- **Quantité / nombre de personnes** — Bornée par le « Reste : n » affiché ; jamais dépassable.
- **Créneau à public réservé** (scolaire, club…) — Non visible/non réservable en ligne, cohérent
  `RG-M5-08` (`spec-reservation.md`) : un créneau réservé n'apparaît **pas** dans le sélecteur.

### 4.3 Panier & réservation temporaire du stock (US-L8-03, RG-M3-03/16, décision « Abandon de panier »)
- **RG-M3-03** — L'ajout au panier crée une **réservation temporaire du stock** du produit et/ou du
  créneau, qui **expire** si la commande n'est pas payée dans le **délai imparti**. Le délai est un
  **paramètre configurable « X minutes » par établissement** (décision actée), avec **libération
  automatique du stock à échéance**.
- **RG-M3-16 (nouvelle)** — À l'expiration du panier, une **relance e-mail/push** est envoyée si un
  contact est connu (compte identifié ou e-mail déjà saisi à l'étape d'identification), rappelant le
  contenu du panier expiré (décision actée « Abandon de panier »).
- **Mécanique de la réservation temporaire** — ⚠ HYPOTHÈSE (point de coordination `reservation`/`M1`,
  non détaillé par le cahier) : l'ajout au panier **ne crée pas** encore une `Reservation` (module
  `reservation`) au sens plein — il **incrémente un compteur temporaire propre au panier**
  (`LignePanierEnLigne.expirationÀ`) qui **borne** la disponibilité affichée aux autres visiteurs.
  Ce n'est **qu'au paiement réussi** (§4.7/4.8) qu'une `Reservation` **confirmée** est créée dans le
  module `reservation` et qu'une `Vente` M2 est validée. Sans paiement dans le délai, **aucune**
  `Reservation` n'est jamais créée. Ce choix évite de polluer le module `reservation` de réservations
  jamais honorées, mais **doit être confirmé au plan technique** (verrouillage optimiste vs pessimiste
  du stock, risque de survente si plusieurs paniers concurrents dépassent la capacité affichée avant
  péremption — même risque déjà signalé pour le **stock pool M1** en L1/L2, `spec-vente.md` §7).
- **Vider le panier / modifier une ligne** — Modifiable/supprimable à tout instant avant paiement ;
  toute suppression d'une ligne timed-entry **libère immédiatement** le compteur temporaire associé.
- **Panier invité vs identifié** — Un panier existe **avant** toute identification (RG-M3-06) ; il est
  **rattaché** au `CompteClient` **si** l'utilisateur se connecte en cours de parcours, sans perte de
  contenu.

### 4.4 Identification — compte, invité, FranceConnect (US-L8-04, RG-M3-06/10/12)
- **RG-M3-06** — L'identification se fait **par compte OU via FranceConnect**, **sans création de
  compte obligatoire** pour un achat simple.
- **RG-M3-12 (nouvelle, décision actée « Achat invité vs compte »)** — L'**achat en invité est
  autorisé** pour un produit simple (billet, entrée datée…) ; en revanche, la **création d'un compte est
  imposée** pour l'achat d'un **abonnement** ou de tout produit portant la facette `sepa` (M1,
  `spec-offre.md` l.93) — un invité qui tente d'ajouter un tel produit au panier ou d'atteindre le
  paiement est **redirigé vers la création de compte** avant de poursuivre (§4.9).
- **RG-M3-10** — La **création de compte** applique un **contrôle anti-homonyme** via la **date de
  naissance** : deux comptes candidats avec même nom/prénom mais date de naissance différente ne sont
  **pas fusionnés automatiquement**.
- **Étape 1 du tunnel** — Trois voies : (a) **déjà client** (e-mail + mot de passe, avec **mot de passe
  oublié**) ; (b) **nouveau compte** (e-mail, mot de passe, civilité, nom/prénom ou raison sociale +
  société/association O/N, date de naissance) ; (c) **FranceConnect** (aucun mot de passe demandé,
  identifie **sans imposer** la création de compte).
- **FranceConnect** — ⚠ **Intégration externe non détaillée** dans les sources (protocole exact,
  scope de données demandé, gestion du callback/erreur, articulation avec un `CompteClient` existant
  portant le même e-mail) — cf. récapitulatif final, point à cadrer avec la DINUM/FranceConnect avant
  implémentation.
- **Un client identifié via FranceConnect sans compte** ne peut **pas** acheter d'abonnement/SEPA
  (RG-M3-12 s'applique quel que soit le mode d'identification : FranceConnect n'exempte pas de créer un
  `CompteClient` avec mot de passe pour un produit `sepa`) — ⚠ HYPOTHÈSE, non tranchée explicitement par
  le cahier, retenue par cohérence avec la décision actée (un mandat SEPA doit être rattaché à un
  compte durable, pas à une identification volatile par jeton FranceConnect).

### 4.5 Bénéficiaires par article & champs personnalisés (US-L8-05)
- **Étape 2 du tunnel** — Un **bénéficiaire** est affecté à **chaque article** de la commande
  (cohérent `RG-M4-02`, bénéficiaire ≠ payeur, `spec-crm.md`) ; le payeur (compte identifié ou invité)
  peut affecter un bénéficiaire différent de lui-même (ex. achat pour un enfant, un proche).
- **Champs personnalisés du produit** — Tout champ additionnel porté par le produit (M1) est présenté
  à cette étape (ex. taille, niveau, autorisation parentale — §4.6).
- **Bénéficiaire mineur** — Rattaché comme `Beneficiaire` d'une `Famille` (M4) si le payeur a un compte ;
  si le payeur est invité, le bénéficiaire mineur est saisi comme identité simple sur la ligne
  (nom/prénom/date de naissance), **sans** création automatique de fiche `Client` complète tant qu'aucun
  compte n'existe.

### 4.6 Consentement RGPD & mineur (US-L8-06, RG-M3-07/13)
- **RG-M3-07** — Le **consentement RGPD est requis avant le paiement** ; il est **horodaté et conservé**
  (aligné `Consentement`, `spec-crm.md` §5, canal = `boutique`).
- **RG-M3-13 (nouvelle, décision actée « Mineur / autorisation parentale »)** — L'autorisation parentale
  pour un bénéficiaire mineur se recueille par une **case à cocher simple** (consentement déclaratif),
  **sans signature électronique** ; elle est horodatée et rattachée à la ligne de commande concernée,
  au même titre que le consentement RGPD général.
- **Blocage** — Le bouton de paiement (étape 3) reste **désactivé/bloquant** tant que le consentement
  RGPD (et, le cas échéant, l'autorisation parentale par bénéficiaire mineur) n'est **pas coché**.

### 4.7 Paiement en ligne commuté PayFiP / CB (US-L8-07, RG-M3-11)
- **RG-M3-11 (nouvelle)** — Le **moyen de paiement en ligne** est **commuté par le régime de
  l'exploitant** (`ProfilExploitant.type`, `spec-compta.md` §4.1, RG-M6-01), à l'identique de la
  commutation déjà opérée côté encaissement/comptabilité :
  - **Régie directe / public** → **PayFiP** (solution DGFiP), redirection avec **référence de
    transaction** stockée sur la Commande, retour (OK/échec/annulé) traité, statut de la Commande mis à
    jour, **rapprochement automatique** (réutilise `RG-PAYFIP-03`, `spec-compta.md` §4.5).
  - **DSP / groupe privé** → **CB via PSP** (prestataire de paiement carte bancaire) — ⚠ **PSP non nommé
    par les sources** (cf. récapitulatif final : à choisir/contractualiser avant plan technique ; le
    comportement observable — redirection ou paiement intégré, retour OK/échec/annulé, référence de
    transaction, rapprochement — est retenu **par analogie stricte** avec le contrat PayFiP ci-dessus).
  - **Changer de profil exploitant** bascule automatiquement le moyen proposé en boutique, **sans
    ressaisie**, comme pour les exports/le plan de comptes (RG-M6-01).
- **Échec ou timeout** — **Aucun paiement n'est enregistré**, la Commande reste au statut `en_cours`, le
  panier et ses réservations temporaires restent valides **jusqu'à expiration du délai** (§4.3) : le
  client peut retenter le paiement sans reperdre sa place.
- **Commande partagée avec M2** — La Commande en ligne validée **est une Vente M2** (canal `en_ligne`,
  cf. `spec-vente.md` §2 « Click & Pay, hors périmètre L2, rattaché à M3 ») : L8 **compose** et
  **déclenche**, M2 en porte le **modèle canonique** (statuts, NF525, contre-passation).

### 4.8 Confirmation, billet QR/wallet, repli QR (US-L8-08, RG-M3-04/14)
- **RG-M3-04** — Le badge/billet est un **QR dynamique lié à l'appareil**, complété d'un **pass
  wallet**, et **fonctionne hors-ligne** côté tourniquet (consommé par le module Accès, `spec-acces.md`
  §4.2, `Support`/`Appairage`).
- **RG-M3-14 (nouvelle, décision actée « Wallet non supporté »)** — Si le **pass wallet n'est pas
  disponible** sur l'appareil du client, un **repli automatique en QR simple** (image/lien) et en
  **PDF téléchargeable** est proposé, **sans interrompre** la confirmation de commande.
- **Étape 4 du tunnel** — À la confirmation : (a) le/les **billets sont émis** (un par bénéficiaire/
  article), (b) **ajout au wallet** proposé si supporté, sinon repli QR/PDF, (c) **e-mail de
  confirmation** envoyé avec récapitulatif, billets et facture.
- **Immédiateté** — Le billet est **disponible immédiatement** après paiement, sans délai de traitement
  différé.
- **Réservation confirmée** — Pour un produit timed-entry, la confirmation **déclenche** la création
  d'une `Reservation` **confirmée** dans le module `reservation` (§4.3) et, le cas échéant, la
  **projection d'un `DroitAccès`** sur la fenêtre du créneau (`RG-M5-12`, `spec-reservation.md` §4.9).

### 4.9 Abonnement & mandat SEPA en ligne — compte obligatoire (US-L8-09, RG-M3-12/17)
- **RG-M3-17 (nouvelle, généralise RG-M3-12)** — L'achat d'un **abonnement** portant la facette `sepa`
  (M1) **impose** un `CompteClient` **actif** (mot de passe créé, pas de session FranceConnect seule,
  cf. §4.4) ; le **mandat SEPA** collecté (IBAN, RUM, date de signature) est rattaché au **payeur**
  identifié — géré par le module partagé **`App\Sepa`** (`specs/sepa/plan-sepa.md`), **non redéfini
  ici** : L8 **déclenche** la collecte (formulaire IBAN → tokenisation via `TokenisationIbanInterface`)
  et **affiche** l'état du mandat en espace client (§4.10) ; le moteur de remise/reprélèvement reste
  propriété du module `App\Sepa`.
- **Refus explicite** — Une tentative d'achat d'abonnement en pur parcours invité est **bloquée** avec un
  message explicite invitant à créer un compte, **avant** l'étape de paiement.

### 4.10 Espace client web (US-L8-10, écran M3-04)
- **Historique** — Commandes et billets/QR téléchargeables ; chaque billet payé est **immédiatement
  disponible** en QR et en facture (aligné `RG-M2-06`/ticket, `spec-vente.md`).
- **Abonnements & prélèvements SEPA** — Mandats, échéances, historique de collecte (référence au module
  `App\Sepa` §4.9), état des impayés le cas échéant (référence croisée avec les moteurs anti-impayés
  verticaux, ex. `specs/sport-fitness/spec-sport.md`, hors périmètre L8).
- **Famille** — Gestion des bénéficiaires rattachés (réutilise `RG-M4-02`, `spec-crm.md`).
- **Justificatifs / factures** — Téléchargement ; **gestion des consentements RGPD** : le client peut
  consulter et **retirer** ses consentements à tout moment (aligné `RG-M4-07`, `spec-crm.md`).
- **Demande de remboursement** — Point d'entrée vers le formulaire (§4.12).

### 4.11 App mobile client (US-L8-11, RG-M3-04/05, écran M3-05)
- **Mes billets & badge** — QR dynamique lié à l'appareil + pass wallet, **fonctionne hors-ligne** côté
  tourniquet (RG-M3-04) : le badge doit être **valide sans réseau** au moment du passage, cohérent avec
  le fonctionnement hors-ligne du module Accès (`RG-ACC-05`, `spec-acces.md`).
- **Mon abonnement** — Services inclus restants (quota consommé/restant, référence `ServiceInclus`, M1).
- **Ma carte** — Passages restants (référence carte multi-entrées, `RG-M1-04/13`).
- **RG-M3-05** — Une **réservation de cours** depuis l'app **décompte le quota de la formule** sur la
  **semaine calendaire** (`RG-M1-12`, sans report) ; **au-delà**, elle **bascule en vente à l'unité**
  (réutilise le mécanisme du module `reservation`, `RG-M5-02`, `spec-reservation.md` §4.3).
- **Recharger** — Porte-monnaie virtuel (PMV, `RG-M4-03`, `spec-crm.md`) ou carte, en réutilisant le
  moyen de paiement en ligne commuté (§4.7).
- **Notifications push** — Confirmations d'achat, rappels de séance (créneau réservé), **expiration de
  panier** (RG-M3-16).

### 4.12 Demande de remboursement en ligne (US-L8-12, RG-M3-15, décision actée « Remboursement en ligne »)
- **RG-M3-15 (nouvelle)** — **Aucun remboursement n'est automatique en ligne** : toute demande passe par
  un **formulaire** (motif, commande/ligne concernée, pièces justificatives éventuelles), déposé par le
  client depuis son espace ; elle est ensuite **traitée par un opérateur habilité** (`boutique ×
  traiter_remboursement`, §3), qui l'**accepte** (déclenche un **avoir** M2, `RG-M2-07`,
  `spec-vente.md` §4.7) ou la **refuse** (motif communiqué au client).
- **Annulation → avoir** — Cohérent avec la décision actée côté M2 : toute annulation traitée **génère
  un avoir**, jamais un rendu d'argent automatique en ligne.
- **Traçabilité** — Chaque demande est horodatée, motivée et rattachée à la Commande d'origine et à
  l'opérateur qui la traite (réutilise `RG-SOCLE-07`).

### 4.13 Retrait / click & collect d'un support physique (US-L8-13, RG-M3-18) — ⚠ nouvelle capacité
- **RG-M3-18 (nouvelle, ⚠ HYPOTHÈSE)** — Le cahier §M3 ne détaille **aucun parcours de retrait
  physique** ; cette règle est **introduite par cet agent** en réponse à la demande explicite du
  commanditaire (« click&collect/retrait »), pour couvrir le cas d'un produit **nécessitant un support
  physique non substituable par un QR** (ex. bracelet RFID étanche piscine — `RG-ACC` §4.2, carte
  multi-entrées plastifiée) acheté en ligne :
  - À la confirmation de commande (§4.8), un **billet QR provisoire** (preuve d'achat, non appairé à un
    support physique) est émis immédiatement, cohérent avec l'exigence « billet disponible
    immédiatement » (§4.8).
  - Le client **retire** le support physique à un **point de retrait** (guichet, borne autonome)
    identifié à l'achat, en présentant le QR provisoire ou un **code de retrait** ; l'agent (ou la
    borne autonome, `spec-acces.md` §4.2, mode `autonome`) **appaire** alors le support physique au
    droit d'accès, qui **remplace** le QR provisoire pour l'usage courant.
  - Tant que le retrait n'a pas eu lieu, le **QR provisoire reste utilisable** au tourniquet
    (repli, cohérent §4.8) — ⚠ HYPOTHÈSE : cette continuité n'est **pas garantie par les sources**
    pour les supports dont l'usage **exige physiquement** le bracelet (ex. accès bassin), auquel cas
    **le retrait est un préalable bloquant** à la première utilisation ; comportement à confirmer
    verticale par verticale (piscine notamment, `specs/L6-piscine/spec-piscine.md`).
- **Hors périmètre** — La production/logistique du support physique (stock de cartes/bracelets vierges,
  approvisionnement du point de retrait) n'est **pas** spécifiée ici.

### 4.14 Connecteurs OTA — inventaire contrôlé & reversements (US-L8-14, RG-M3-09)
- **RG-M3-09** — Les **connecteurs OTA** (plateformes revendeurs externes) **partagent l'inventaire**
  du même produit/créneau **sous contrôle** (décrément du **même compteur réel**, pas d'un stock séparé
  — cohérent `RG-MUS-04`, `specs/musee/spec-musee.md` §4.7) et **déclenchent les reversements**
  associés (tarif net + commission par partenaire).
- **Propriété fonctionnelle** — Ce module (L8/M3) est le **propriétaire fonctionnel générique** de la
  capacité « connecteur OTA » : `PartenaireOTA`, `AllocationQuotaOTA`, `ReservationOTA` — déjà décrits
  et implémentés côté verticale musée (`specs/musee/spec-musee.md` §4.7/4.8, RG-MUS-04, décision actée
  « Sur-vente OTA ») **avant** l'existence de cette spec transverse. **À réconcilier** : les objets
  `PartenaireOTA`/`AllocationQuotaOTA`/`ReservationOTA` définis par `spec-musee.md` §5 devraient être
  **génériques à L8** (rattachés à `Vitrine.connecteursOTA`) et **spécialisés** par chaque verticale qui
  en a besoin (sur-vente/no-show OTA = spécificité musée, décrite dans `spec-musee.md`, non redéfinie
  ici) — cf. récapitulatif final, point à trancher lors d'un futur remaniement des specs.
- **Activation par établissement** — Le Gestionnaire active/désactive un connecteur OTA par établissement
  (§3, `boutique × gerer_connecteur_ota`) ; l'inventaire alloué à un partenaire **reste dans le même
  pool** que la vente directe (RG-M3-09).
- **Protocole technique** — ⚠ **non spécifié dans ce dépôt** (cf. §2 Exclu, récapitulatif final).

## 5. Objets de données
Les types PHP sont indicatifs (spec = comportement observable). Tout objet est rattaché à un
**Établissement** via le socle (`RG-SOCLE-01`) ; identifiants = **UUID** (constitution §3). Les objets
M1 (Produit, Promotion, Stock), M2 (Vente/Commande, Paiement, Billet/Support, Avoir), `reservation`
(Ressource, Creneau, Reservation, ListeAttente), M4 (Client, Famille, Beneficiaire, Consentement, PMV),
M6 (ProfilExploitant, MoyenPaiement, BordereauPayFiP) et `App\Sepa` (MandatSepa, ConfigCreancierSepa)
sont **référencés, non redéfinis**.

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| **Vitrine** | id | uuid | PK, 1 par établissement | RG-M3-01 |
| | établissement | ref (socle) | requis | RG-SOCLE-01 |
| | logo, couleurs, langues[] | fichier, palette, liste | — | white-label |
| | canauxActifs | set {en_ligne, app} | requis | filtre l'affichage |
| | délaiExpirationPanierMinutes | int > 0 | paramétrable par établissement | RG-M3-03, décision actée |
| | promosMisesEnAvant[] | ref Promotion (M1)[] | filtrées canal en_ligne | RG-M1-07 |
| | connecteursOTA[] | ref ConnecteurOTA[] | — | RG-M3-09, §4.14 |
| **PanierEnLigne** | id | uuid | PK | RG-M3-03 |
| | compteClient | ref CompteClient? | optionnel (invité possible) | §4.4 |
| | sessionInvité | ref SessionClient? | requis si pas de compte | — |
| | statut | enum {ouvert, expiré, transformé_en_commande} | défaut = ouvert | §4.3 |
| | dateCréation, dateExpiration | datetime | dateExpiration = dateCréation + délai établissement | RG-M3-03/16 |
| **LignePanierEnLigne** | id, panier | uuid, ref PanierEnLigne | PK | — |
| | produit | ref Produit (M1) | requis | RG-M1-01 |
| | quantité | int ≥ 1 | ≤ « Reste : n » affiché | §4.2 |
| | créneau | ref Creneau (module `reservation`)? | requis si produit timed-entry | RG-M3-02 |
| | bénéficiaire | identité simple ou ref Beneficiaire (M4) | requis à l'étape 2 | §4.5 |
| | champsPersonnalisés | map(champ → valeur) | selon produit | §4.5 |
| | expirationÀ | datetime | = `PanierEnLigne.dateExpiration` | réservation temporaire, ⚠ HYPOTHÈSE §4.3 |
| **CommandeEnLigne** *(= Vente M2, canal `en_ligne`, référencée non redéfinie)* | id | uuid | PK | `spec-vente.md` §5 « Vente/Ticket » |
| | canal | enum incluant `en_ligne` | requis | distingue de la caisse M2 |
| | compteClient | ref CompteClient? | optionnel (invité possible pour achat simple) | RG-M3-06/12 |
| | statutTunnel | enum {panier, identifié, payé, confirmé} | défaut = panier | §6 États & cycle de vie |
| | moyenPaiementEnLigne | ref MoyenPaiementEnLigne | requis au paiement | RG-M3-11 |
| **MoyenPaiementEnLigne** | id, commande | uuid, ref CommandeEnLigne | PK | RG-M3-11 |
| | type | enum {payfip, cb_psp} | commuté par `ProfilExploitant.type` | RG-M6-01, RG-PAYFIP-03 |
| | référenceTransaction | string | requis | stockée sur la Commande |
| | statutRetour | enum {ok, échec, annulé, en_attente} | requis | maj statut Commande |
| **BilletQR** *(= BilletWallet du cahier, aligné `Billet/Support` M2 + `Support` Accès)* | id | uuid | PK | RG-M3-04 |
| | commande, ligne | ref | requis | traçabilité |
| | bénéficiaire | ref | requis | 1 billet par bénéficiaire/article |
| | qrDynamique | string, lié appareil | requis | RG-M3-04 |
| | passWallet | ref?, disponible | optionnel | RG-M3-04 |
| | repliQR | bool | true si wallet indisponible | RG-M3-14 |
| | validité | fenêtre datetime | héritée du produit/créneau | — |
| | statutRetraitPhysique | enum {non_applicable, à_retirer, retiré}? | requis si support physique | RG-M3-18, §4.13 |
| **CompteClient** | id | uuid | PK, 1:1 avec Client (M4) | cahier §4 |
| | email | string, unique | requis | identifiant de connexion |
| | motDePasseHash | string | requis sauf FranceConnect seul | — |
| | franceConnectId | string? | optionnel | RG-M3-06, ⚠ intégration à cadrer |
| | civilité, nom, prénom | string | requis si personne physique | — |
| | sociétéOuAssociation | bool | requis | bascule raison sociale |
| | dateNaissance | date | requis | RG-M3-10, contrôle anti-homonyme |
| | clientRef | ref Client (M4) | requis, 1:1 | rattachement fiche CRM |
| | statut | enum {actif, invité_temporaire} | défaut selon parcours | §4.4 |
| **SessionClient** | id | uuid | PK | — |
| | compteClient | ref CompteClient? | vide si invité pur | — |
| | type | enum {connecté, invité, franceconnect} | requis | §4.4 |
| | token, expiration | string, datetime | requis | authentification |
| **ConsentementTunnel** *(instance de `Consentement`, M4, canal = boutique)* | id, compteClient/session | uuid, ref | requis | RG-M3-07 |
| | type | enum {rgpd, autorisation_parentale} | requis | RG-M3-07/13 |
| | horodatage | datetime | requis | bloquant avant paiement |
| **DemandeRemboursement** | id | uuid | PK | RG-M3-15 |
| | commande | ref CommandeEnLigne | requis | — |
| | motif, piècesJustificatives[] | texte, fichier[] | motif requis | déposée par le client |
| | statut | enum {reçue, en_cours, acceptée, refusée} | défaut = reçue | — |
| | dateDemande, dateTraitement, traitéPar | datetime/ref Utilisateur | requis si traitée | `boutique × traiter_remboursement` |
| | avoirRattaché | ref Avoir (M2)? | requis si `acceptée` | RG-M2-07 |
| **RetraitClickCollect** *(⚠ HYPOTHÈSE, RG-M3-18)* | id, commande, ligne | uuid, ref | requis | §4.13 |
| | pointRetrait | ref (guichet/borne) | requis | — |
| | codeRetrait | string | requis | présenté au retrait |
| | statut | enum {à_retirer, retiré} | défaut = à_retirer | — |
| | dateRetrait, traitéPar | datetime, ref Utilisateur? | requis si `retiré` | `boutique × traiter_retrait` |
| **ConnecteurOTA** | id, vitrine | uuid, ref Vitrine | PK | RG-M3-09 |
| | partenaire | ref PartenaireOTA *(défini par `spec-musee.md` §5, à généraliser — §4.14)* | requis | — |
| | actif | bool | activable/désactivable | §4.14 |
| | quotaAlloué, tarifNet, commission | int, decimal, decimal/% | requis | RG-M3-09 |

## 6. États & cycle de vie
```
Panier → Identifié → Payé → Confirmé (billets émis)
```
```
Réservation temporaire (panier) → [paiement réussi] → Reservation confirmée (module reservation)
Réservation temporaire (panier) → [expiration] → libérée (aucune Reservation créée)
```
```
DemandeRemboursement : reçue → en_cours → acceptée (avoir M2) | refusée
```
```
RetraitClickCollect : à_retirer → retiré
```

## 7. Critères d'acceptation
- **CA-1 (US-L8-01, RG-M3-01/08)** — *Étant donné* deux établissements distincts, *quand* un visiteur
  ouvre leurs vitrines respectives, *alors* chacune reflète **son propre** logo/couleurs/langues, **sans
  marque de l'éditeur**, et affiche **prix et disponibilité en temps réel** issus du catalogue M1.
- **CA-2 (US-L8-02, RG-M3-02)** — *Étant donné* un produit **timed-entry**, *quand* le client tente de
  l'ajouter au panier **sans choisir de créneau**, *alors* l'ajout est **refusé** ; *quand* il choisit un
  créneau avec de la place, *alors* l'ajout réussit et le « Reste : n » du créneau **diminue**
  visiblement pour les autres visiteurs.
- **CA-3 (US-L8-03, RG-M3-03, décision actée)** — *Étant donné* un panier avec une ligne timed-entry,
  *quand* le délai configurable (« X minutes ») **expire sans paiement**, *alors* le stock/la place est
  **libéré(e) automatiquement** et **aucune** `Reservation` n'est créée ; une **relance e-mail/push**
  est envoyée si un contact est connu (RG-M3-16).
- **CA-4 (US-L8-04, RG-M3-06)** — *Étant donné* le tunnel de commande, *quand* le client choisit
  **FranceConnect**, *alors* il est identifié **sans qu'aucune création de compte** ni mot de passe ne
  lui soit demandé ; *quand* il choisit de **rester invité**, *alors* il peut poursuivre un **achat
  simple** sans compte.
- **CA-5 (US-L8-04, RG-M3-10)** — *Étant donné* une création de compte avec un nom/prénom déjà présents
  en base mais une **date de naissance différente**, *quand* le compte est créé, *alors* **aucune
  fusion automatique** n'a lieu (deux fiches distinctes coexistent).
- **CA-6 (US-L8-06, RG-M3-07)** — *Étant donné* le tunnel arrivé à l'étape paiement, *quand* la case de
  **consentement RGPD n'est pas cochée**, *alors* le bouton de paiement reste **bloqué** ; *quand* elle
  est cochée, *alors* le consentement est **horodaté et conservé** et le paiement devient accessible.
- **CA-7 (US-L8-05, RG-M4-02)** — *Étant donné* un panier de 3 articles, *quand* le client passe l'étape
  bénéficiaires, *alors* **chaque article** porte **un bénéficiaire affecté**, avant de pouvoir avancer
  à l'étape paiement.
- **CA-8 (US-L8-06, RG-M3-13, décision actée)** — *Étant donné* une ligne avec un **bénéficiaire
  mineur**, *quand* le payeur poursuit vers le paiement, *alors* une **case d'autorisation parentale**
  (consentement simple, sans signature électronique) est **requise et horodatée** en plus du
  consentement RGPD général.
- **CA-9 (US-L8-07, RG-M3-11)** — *Étant donné* un établissement en **régie directe**, *quand* le client
  paie, *alors* il est redirigé vers **PayFiP** et le retour (OK/échec/annulé) met à jour le statut de
  la Commande ; *étant donné* un établissement **DSP/privé**, *quand* le client paie, *alors* le
  paiement s'effectue via le **PSP CB** configuré, selon le même contrat observable (référence de
  transaction, retour, rapprochement).
- **CA-10 (US-L8-07)** — *Étant donné* un paiement en **échec ou timeout**, *alors* **aucun règlement**
  n'est enregistré, la Commande reste `en_cours`, et le panier/ses réservations temporaires **restent
  valides jusqu'à expiration du délai** (le client peut retenter).
- **CA-11 (US-L8-08, RG-M3-04)** — *Étant donné* un paiement réussi, *alors* le/les **billets QR** sont
  **disponibles immédiatement**, un **e-mail de confirmation** est envoyé, et une `Reservation`
  **confirmée** est créée dans le module `reservation` pour chaque ligne timed-entry.
- **CA-12 (US-L8-08, RG-M3-14, décision actée)** — *Étant donné* un appareil **sans support wallet**,
  *quand* la commande est confirmée, *alors* un **repli automatique en QR + PDF** est proposé, **sans**
  interrompre la confirmation.
- **CA-13 (US-L8-09, RG-M3-12/17, décision actée)** — *Étant donné* un visiteur **invité** qui ajoute un
  produit **abonnement/SEPA** au panier, *quand* il atteint l'étape de paiement, *alors* il est
  **bloqué et redirigé** vers la création de compte ; *quand* un **titulaire de compte** achète ce même
  produit, *alors* la **collecte du mandat SEPA** (IBAN, RUM) est déclenchée et **rattachée au payeur**.
- **CA-14 (US-L8-10)** — *Étant donné* un client identifié consultant son espace, *alors* **chaque
  billet payé** est immédiatement visible en **QR et en facture**, et il peut **consulter/retirer** ses
  consentements RGPD à tout moment.
- **CA-15 (US-L8-11, RG-M3-04)** — *Étant donné* l'app mobile **sans réseau**, *quand* le client présente
  son badge au tourniquet, *alors* le passage est **validé hors-ligne** (cohérent `RG-ACC-05`) grâce au
  QR dynamique déjà téléchargé sur l'appareil.
- **CA-16 (US-L8-11, RG-M3-05)** — *Étant donné* un bénéficiaire disposant encore de son **quota de
  formule** sur la semaine calendaire, *quand* il réserve un cours depuis l'app, *alors* la réservation
  est **confirmée sans encaissement** et le quota est **décompté** ; *étant donné* un quota **épuisé**,
  *alors* une **vente à l'unité** est proposée avant confirmation.
- **CA-17 (US-L8-12, RG-M3-15, décision actée)** — *Étant donné* une commande payée, *quand* le client
  dépose une **demande de remboursement** via le formulaire, *alors* **aucun remboursement automatique**
  n'a lieu ; *quand* un opérateur habilité l'**accepte**, *alors* un **avoir M2** est généré et
  rattaché ; *quand* il la **refuse**, *alors* un motif est communiqué au client.
- **CA-18 (US-L8-13, RG-M3-18, ⚠ nouvelle)** — *Étant donné* un produit nécessitant un support physique,
  *quand* la commande est confirmée, *alors* un **billet QR provisoire** est émis immédiatement ; *quand*
  le client se présente au point de retrait avec son **code de retrait**, *alors* le support physique
  est **appairé** au droit d'accès et remplace le QR provisoire.
- **CA-19 (US-L8-14, RG-M3-09)** — *Étant donné* un créneau avec un **quota alloué à un partenaire OTA**,
  *quand* une vente OTA est confirmée, *alors* elle **décrémente le même inventaire réel** que la vente
  directe (pas de stock séparé), et alimente le **calcul du reversement** (tarif net + commission).

## 8. Cas limites
- **Panier expiré avant identification** — Aucun contact connu : la **relance e-mail/push** (RG-M3-16)
  n'est **pas possible** ; ⚠ HYPOTHÈSE : le panier est simplement perdu, sans notification, comportement
  non détaillé par les sources.
- **Paiement échoué ou expiré** — Libère **immédiatement** le stock réservé (produit et créneau),
  cohérent avec l'exigence explicite du cahier §7 (« Un paiement échoué ou expiré libère immédiatement le
  stock réservé »).
- **Jauge/créneau plein au moment du paiement (concurrence)** — ⚠ HYPOTHÈSE : si deux paniers
  concurrents ont réservé temporairement plus de places que la capacité réelle (risque signalé §4.3),
  seul le **premier paiement validé** obtient la place ; le second paiement, s'il aboutit malgré tout,
  doit être **détecté et compensé** (remboursement/avoir automatique déclenché **côté système**, ce qui
  **contredit potentiellement** la décision « aucun remboursement automatique » RG-M3-15 — **point de
  friction non tranché par les sources**, à arbitrer en priorité avant implémentation).
- **Créneau complet au moment de l'ajout au panier** — Seule l'**inscription à la liste d'attente** du
  module `reservation` est proposée (`RG-M5-01`, `spec-reservation.md`), pas d'ajout au panier direct ;
  ⚠ HYPOTHÈSE : le parcours d'achat depuis une **promotion de liste d'attente** (paiement après
  promotion automatique) n'est pas détaillé par le cahier M3, à préciser avec le module `reservation`.
- **Rupture de stock d'un produit simple pendant le tunnel** — Cohérent avec le risque déjà signalé côté
  M1/M2 (stock pool, `spec-vente.md` §7) : la ligne concernée est **bloquée** avant paiement, avec
  message explicite.
- **Échec/absence de retour FranceConnect** — ⚠ HYPOTHÈSE : conduite (réessai, repli vers
  identification classique) non détaillée par les sources.
- **E-mail de compte déjà utilisé via un autre canal (caisse M2)** — ⚠ HYPOTHÈSE : le rattachement
  d'une Commande en ligne à un `Client` (M4) déjà créé au guichet suit la même règle « une valeur déjà
  saisie prévaut » que `RG-M4-01` (`spec-crm.md` §4) ; à confirmer.
- **Mineur seul sans représentant identifiable** — ⚠ HYPOTHÈSE, hérité de `spec-crm.md` §7 : la case
  d'autorisation parentale (RG-M3-13) suppose un adulte payeur ; le cas d'un mineur agissant seul comme
  payeur (carte cadeau, argent de poche) n'est pas traité par les sources.
- **Demande de remboursement sur un billet déjà consommé (passage validé)** — ⚠ HYPOTHÈSE : non
  détaillée par le cahier ; retenue par cohérence : la demande reste **recevable** (formulaire), mais
  l'opérateur qui la traite dispose de cette information (passage validé ou non, via le journal Accès,
  `spec-acces.md` §4.9) pour motiver son acceptation/refus.
- **Retrait click & collect non honoré (support jamais retiré)** — ⚠ HYPOTHÈSE : ni relance, ni délai de
  péremption du retrait ne sont spécifiés (règle non couverte par le cahier, capacité elle-même
  introduite par cet agent, §4.13) — à définir au produit.
- **Connecteur OTA désynchronisé (double vente sur la même place)** — Comportement déjà tranché **côté
  verticale musée** (priorité au 1ᵉʳ confirmé, récupération du quota des no-show OTA, décision actée
  `specs/musee/spec-musee.md` §4.8) ; ⚠ ce comportement n'est **pas généralisé** ici au socle L8 (retenu
  comme spécifique musée tant que d'autres verticales n'expriment pas le même besoin) — cf. §4.14.
- **Utilisateur staff sans affectation sur l'établissement de la vitrine** — Aucun accès (hérité du
  socle, `RG-SOCLE-05`).

## 9. Dépendances
- **Dépend de : socle L0** (`specs/L0-socle/spec-socle.md`) — hiérarchie Groupe/Région/Établissement/
  Espace (`RG-SOCLE-01`) à laquelle se rattache la `Vitrine` ; permissions `module × action` réutilisées
  sur le module **`boutique`** (`RG-SOCLE-02/03/04`) pour les acteurs staff ; hébergement en France et
  RGPD (constitution §4) ; **RGAA/WCAG 2.2 AA** applicable à la vitrine, surface publique (constitution
  §4 point 5) ; journal d'audit append-only (`RG-SOCLE-07`) pour les traitements de remboursement/
  retrait et les modifications de vitrine.
- **Dépend de : M1 · Offre & Tarification** (L1, `specs/L1-offre/spec-offre.md`) — catalogue publié et
  **visible au canal `en_ligne`** (RG-M1-07/09), prix résolu (grille/saison, RG-M1-01), promotions
  éligibles au canal (RG-M1-04), stock (RG-M1-10), facette `sepa` de la Formule (l.93) qui déclenche
  RG-M3-12/17. L8 **consomme**, ne redéfinit aucun de ces objets.
- **Dépend de : module `reservation`** (`specs/reservation/spec-reservation.md`) — `Ressource`,
  `Creneau`, capacité et jauge (RG-M5-01/03), `Reservation`/`ListeAttente` (RG-M5-01/02/06), décompte du
  **quota de formule en semaine calendaire** (RG-M5-02, RG-M1-12) consommé par RG-M3-05, projection
  optionnelle d'un `DroitAccès` sur la fenêtre du créneau (RG-M5-12) déclenchée à la confirmation
  (§4.8). L8 **déclenche** la création d'une `Reservation` confirmée au paiement réussi, **ne
  réimplémente pas** le moteur de créneau/jauge/liste d'attente.
- **Dépend de : M2 · Vente & Caisse** (L2, `specs/L2-vente/spec-vente.md`) — la **Commande en ligne est
  une Vente M2** (canal `en_ligne`, déjà signalée hors périmètre L2 §2/§8 « Click & Pay », rattachée
  ici) ; **Billet/Support**, **Avoir** (RG-M2-07) et **contre-passation NF525** sont le modèle canonique
  réutilisé pour `BilletQR`/`DemandeRemboursement`. L8 **compose et déclenche**, M2 **encaisse et
  trace** côté modèle de données partagé.
- **Dépend de : module Accès** (L3, `specs/L3-acces/spec-acces.md`) — le `BilletQR`/pass wallet émis par
  L8 **devient** un `Support` appairé à un `DroitAccès` (RG-ACC, §4.2) ; la validation physique au
  tourniquet, le fonctionnement hors-ligne (RG-ACC-05) et le comptage sont **entièrement délégués** à
  ce module, non redéfinis ici.
- **Dépend de : M4 · CRM** (L5, `specs/L5-crm/spec-crm.md`) — le `CompteClient` est **1:1** avec un
  `Client` (M4) ; `Famille`/`Beneficiaire` (RG-M4-02), `PMV` (RG-M4-03, moyen de recharge en ligne),
  `Consentement` (RG-M4-07) sont **consommés**, non redéfinis. La création de compte en ligne (RG-M3-10)
  **crée ou rattache** un `Client` M4.
- **Dépend de : M6 · Compta & Régie** (L4, `specs/L4-compta/spec-compta.md`) — la commutation
  `ProfilExploitant.type` (RG-M6-01) pilote le choix **PayFiP vs CB/PSP** (RG-M3-11) ; **PayFiP**
  (RG-PAYFIP-03) est **réutilisé tel quel** ; le référentiel `MoyenPaiement` reste **propriété M6**.
- **Dépend de : module `App\Sepa`** (`specs/sepa/plan-sepa.md`) — collecte et gestion du **mandat SEPA**
  (RG-M3-17), tokenisation IBAN, génération pain.008 bi-régime ; L8 **déclenche** la collecte au moment
  de l'achat d'abonnement en ligne, ne réimplémente pas le moteur de remise/reprélèvement.
- **Référencé par (consommateurs de cette capacité, à réconcilier) :** `specs/musee/spec-musee.md` §4.7/
  4.8 (`PartenaireOTA`, `AllocationQuotaOTA`, `ReservationOTA`, sur-vente/no-show OTA) — objets définis
  **avant** l'existence de cette spec transverse ; L8 en devient le **propriétaire fonctionnel
  générique** (§4.14), la spécificité « sur-vente/récupération de quota » restant décrite côté musée.

---

## Points ouverts / hypothèses (récapitulatif)

### Absence de source officielle (à faire trancher/valider par le produit)
1. **US-L8-01 à 14 sont des stories définies par cet agent** — `backlog.html` ne comporte aucun onglet
   `L8` ni aucune story `US-L8-*` (même situation que M5/`reservation`, cf. `spec-reservation.md`
   point 1). À faire **valider, renuméroter et chiffrer** officiellement avant développement.
2. **`RG-M3-11` à `18` sont de nouvelles règles**, absentes du panel `p-m3` du cahier (qui ne va que
   jusqu'à `RG-M3-10`) : elles **formalisent** des décisions actées (onglet ★, table « M3 · Boutique &
   App client ») déjà mentionnées mais non numérotées comme `RG`, ou **introduisent** des capacités
   demandées explicitement par le commanditaire (paiement commuté PayFiP/CB, SEPA en ligne, click &
   collect). **À faire valider** que rien n'a été indûment ajouté au socle.

### ⚠ INTÉGRATIONS EXTERNES — à cadrer avant tout plan technique
3. **FranceConnect** (§4.4) — protocole exact (OIDC FranceConnect, scope de données demandées, gestion
   du callback/erreur, articulation avec un `CompteClient` existant portant le même e-mail) **non
   détaillé** par les sources ; à cadrer avec la DINUM avant implémentation.
4. **PSP CB** pour le régime DSP/privé (§4.7) — **aucun prestataire n'est nommé** par les sources (qui
   ne détaillent que **PayFiP** côté public, `spec-compta.md` §4.5) ; le comportement observable côté
   privé est retenu **par analogie stricte** avec PayFiP (redirection/paiement intégré, retour OK/
   échec/annulé, référence de transaction, rapprochement automatique) — à choisir/contractualiser
   (Stripe, PayPlug, Systempay…) et à cadrer techniquement avant développement.
5. **Connecteur technique OTA** (§4.14) — protocole d'échange, format, fréquence de synchronisation
   avec les plateformes revendeurs **non spécifiés dans ce dépôt** ; déjà signalé comme gap de
   dépendance par `specs/musee/spec-musee.md` §8 point 2. Cette spec pose la **capacité fonctionnelle**
   générique (`ConnecteurOTA`, RG-M3-09) mais **pas** le protocole lui-même.

### ⚠ HYPOTHÈSE fonctionnelle (comportement retenu par défaut, à confirmer)
6. **Mécanique de la réservation temporaire de panier** (§4.3) — retenue comme un **compteur temporaire
   propre à la boutique**, distinct d'une `Reservation` (module `reservation`) qui n'est créée qu'au
   paiement réussi ; risque de survente concurrente si plusieurs paniers dépassent la capacité affichée
   avant péremption (même risque déjà signalé pour le **stock pool M1** en L1/L2) — **à confirmer au
   plan technique**.
7. **Conflit entre remboursement automatique en cas de survente concurrente et la décision « aucun
   remboursement automatique »** (§7, cas limites) — **point de friction non tranché par les sources**,
   à arbitrer en priorité.
8. **FranceConnect n'exempte pas de créer un `CompteClient` avec mot de passe** pour un produit
   `sepa`/abonnement (§4.4) — retenu par cohérence avec la décision actée, non tranché explicitement.
9. **Continuité du QR provisoire pour les supports physiques exigés (bracelet piscine)** (§4.13) — le
   retrait pourrait être un **préalable bloquant** plutôt qu'un simple remplacement différé, à confirmer
   verticale par verticale.
10. **Réconciliation `PartenaireOTA`/`AllocationQuotaOTA`/`ReservationOTA`** entre `spec-musee.md`
    (définition initiale, verticale) et cette spec (propriété fonctionnelle générique, §4.14) — à
    trancher lors d'un futur remaniement des specs, sans perte du comportement déjà spécifié côté musée
    (sur-vente, récupération de quota des no-show OTA).
11. **Relance d'un panier expiré avant identification** (§8, cas limites) — aucun contact connu, pas de
    notification possible, non détaillé par les sources.
12. **Parcours d'achat depuis une promotion de liste d'attente** (§8, cas limites) — non détaillé par le
    cahier M3, à préciser avec le module `reservation`.
13. **Retrait click & collect non honoré** (§8, cas limites) — ni relance ni péremption spécifiées ; la
    capacité elle-même (§4.13) est une extension introduite par cet agent, hors du texte du cahier M3.
