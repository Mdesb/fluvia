# Spec — Verticale Musée / Culture (`Musée` / lot post-MVP, hors ordre L0→L7)

- **Lot / module :** Verticale **Musée** (V2, cahier §« Plan de développement » — lot **L16**, au-dessus
  du socle **M1–M8** déjà spécifié, dont la **Réservation & no-show générique** — `specs/reservation/`,
  en cours de spécification).
- **Stories couvertes :** **US-MUSEE-01 à US-MUSEE-11** — ⚠ **HYPOTHÈSE** : la verticale musée est
  absente du `backlog.html` (les panels `p-l0` à `p-l7` s'arrêtent à la piscine/back-office ; le musée
  n'apparaît que comme ligne de plan « L16 · verticales Patinoire · Sport · Padel · Musée », sans détail
  de user stories). Ces user stories sont **définies par cet agent** à partir du panel `p-musee` du
  cahier détaillé (§1 « Positionnement », §2 « Fonctions & écrans spécifiques ») et des décisions
  actées ★ « 🏛️ Musée ». **À faire valider et numéroter officiellement** dans le backlog avant
  développement (même remarque que pour `specs/sport-fitness/spec-sport.md`, US-SPORT-01 à 11).
- **Règles de gestion :** RG-MUS-01 à RG-MUS-06 (cahier `cahier-detaille.html`, panel `p-musee`, §3) +
  décisions actées ★ « 🏛️ Musée » (4 points : Expo à forte affluence, Guide indisponible dans la
  langue, Gratuités scolaires, Sur-vente OTA — aucune marquée ✱ modifié) + règles socle réutilisées :
  RG-ACC-01/03/04/05 (`specs/L3-acces/spec-acces.md`), RG-M1-01/02/03/07/09 (`specs/L1-offre/spec-offre.md`),
  RG-M2-04/05 (`specs/L2-vente/spec-vente.md`), RG-M4-02 (`specs/L5-crm/spec-crm.md`), RG-SOCLE-01 à 07
  (`specs/L0-socle/spec-socle.md`) + règles génériques de réservation (créneaux, jauge par créneau,
  liste d'attente) — ⚠ **`specs/reservation/spec-reservation.md` n'existe pas encore dans le dépôt à la
  date de rédaction** (voir §2 et §8, même situation que M5 pour `spec-piscine.md`/`spec-sport.md`).
- **Statut :** brouillon — dépend d'une spec socle (Réservation générique) non encore livrée ; plusieurs
  mécanismes (délestage, connecteur OTA technique) ne sont posés qu'en comportement observable.

## 1. Objectif
Permettre à un musée/site culturel de vendre une **entrée horodatée** (timed-entry) sur une **exposition
à jauge**, d'organiser des **visites guidées multilingues** avec un **guide qualifié comme ressource**,
d'accueillir des **groupes et scolaires** avec **gratuités et paiement différé**, de **distribuer son
inventaire via des partenaires OTA** sans se sur-vendre, et de faire vivre un **pass annuel « Amis du
musée »** — le tout en **paramétrant** le socle existant (M1 catalogue, réservation/créneaux, contrôle
d'accès L3, M2 vente, M6 fiscalité/TVA culturelle 10 %) plutôt qu'en réinventant un moteur. Principe du
cahier : *« tout ce qui suit décrit uniquement le paramétrage propre au musée »* (cahier §1).

## 2. Périmètre
Cette spec **ne redéfinit pas** les mécanismes génériques ; elle décrit **ce qui est propre au musée**
au-dessus d'eux, en distinguant explicitement les deux familles ci-dessous (cahier §1, tableau
« Brique socle / Ce que la verticale musée configure »).

### 2.1 [RÉUTILISE SOCLE] — paramétré, non redéfini ici
- **Timed-entry / créneaux horaires, jauge par créneau, liste d'attente** → module générique
  **Réservation & no-show** (`specs/reservation/`, en cours de spec). Le musée **impose** le créneau à
  l'achat pour toute exposition à jauge (RG-MUS-01) et **réutilise** la liste d'attente générique pour
  les visites guidées sans guide qualifié disponible (§4.4).
- **Contrôle d'accès, FMI, contrôle mobile** → **L3** (`specs/L3-acces/spec-acces.md`). Le musée
  **spécialise** la notion d'espace d'accès pour poser un **sous-quota par salle** au-delà de la jauge
  d'entrée du créneau, contrôlé par le **contrôle mobile** (A-04) déjà spécifié en L3 (§4.2).
- **Vente / caisse, panier, paiement, ticket, remboursement/avoir** → **M2** (`specs/L2-vente/spec-vente.md`).
- **Catalogue produit, tarifs, formules, promotions, cycle de publication** → **M1**
  (`specs/L1-offre/spec-offre.md`). Exposition = spécialisation de **Produit** ; Pass annuel = spécialisation
  de **Formule** d'abonnement (droits d'accès, RG-M1-03).
- **Fiche client, famille, adhérent nominatif** → **M4/CRM** (`specs/L5-crm/spec-crm.md`), pour le porteur
  du pass annuel.
- **Fiscalité culturelle (TVA 10 %), PCA, régie/DSP** → **M6** (`specs/L4-compta/spec-compta.md`, cahier
  §M6 « ventilation par taux : 10 % culturel/musée »).

### 2.2 [SPÉCIFIQUE MUSÉE] — inclus dans cette spec
- **Sous-quota par salle**, au-delà de la jauge d'entrée du créneau, avec **délestage** en cas de
  saturation — US-MUSEE-02, décision actée « Expo à forte affluence ».
- **Visites guidées** : guide = ressource qualifiée par langue, capacité propre distincte de la jauge
  d'entrée — US-MUSEE-03, RG-MUS-02.
- **Guide indisponible dans la langue demandée** : liste d'attente (générique) ou **bascule audioguide +
  remise** — US-MUSEE-04, décision actée.
- **Gratuités scolaires** : consomment le quota de jauge + **contingent de gratuités dédié paramétrable**
  — US-MUSEE-05, RG-MUS-03 + décision actée.
- **Groupes & scolaires** : dossier de réservation anticipée, **paiement différé** (bon de commande /
  mandat administratif) — US-MUSEE-06, RG-MUS-03.
- **Distribution OTA** : allocation de quota par partenaire, inventaire décrémenté en temps réel,
  reversements (tarif net + commission) — US-MUSEE-07, RG-MUS-04.
- **Sur-vente OTA** : priorité au premier billet confirmé + récupération du quota des no-show OTA —
  US-MUSEE-08, décision actée.
- **Expositions temporaires** : vente bornée aux dates début/fin — US-MUSEE-09, RG-MUS-06.
- **Pass annuel « Amis du musée »** : accès illimité collection permanente, mais soumis au choix d'un
  créneau pour toute expo à jauge — US-MUSEE-10, RG-MUS-05.
- **Audioguides** : produit optionnel multilingue, support de la bascule US-MUSEE-04 — US-MUSEE-11.

### 2.3 Exclu (pour l'instant), que la verticale *référence* seulement
- La **mécanique générique de créneau/jauge/liste d'attente** (génération de créneaux, calcul de
  disponibilité, règle de no-show générique, annulation) → **module Réservation générique**. ⚠
  **HYPOTHÈSE / GAP DE DÉPENDANCE** — aucune spec `spec-reservation.md` n'existe encore dans le dépôt à
  la date de rédaction ; cette spec musée **modélise en §5** les objets **spécifiques musée**
  (`CréneauExpo`, `VisiteGuidée`) comme des **spécialisations provisoires** des concepts génériques
  attendus (Créneau, JaugeCréneau, ListeAttente), **à réconcilier dès que la spec Réservation sera
  livrée** — même situation que L6/`spec-piscine.md` vis-à-vis de M5.
- Le **connecteur technique OTA** (protocole d'échange, format, fréquence de synchronisation avec les
  plateformes revendeurs) → cahier §M3 (« connecteurs OTA avec contrôle d'inventaire et reversements »,
  RG-M3-09), mais **aucune spec `spec-boutique.md`/M3 n'existe encore** dans le dépôt (M3 = lot V1 L8,
  non encore construit). Cette spec pose le **comportement observable** attendu côté inventaire/priorité/
  no-show (US-MUSEE-07/08) ; l'**intégration technique par plateforme** (Tiqets, Weezevent, FNAC-Spectacles,
  billetterie de site tiers…) reste **hors périmètre**, à cadrer au plan technique avec chaque partenaire.
- Le **contrôle d'accès physique lui-même au tourniquet d'entrée** (validation du billet horodaté,
  anti-passback, hors-ligne/synchro) → **L3**, déjà spécifié, réutilisé tel quel.
- La **définition des produits/tarifs/TVA/PCA** eux-mêmes → **M1/M6**, réutilisés (le musée **instancie**
  au-dessus, il ne redéfinit pas le moteur).
- L'**UI (front)**, l'**authentification, les rôles/permissions, le journal d'audit** → **socle L0**
  (`spec-socle.md`), réutilisés et non redéfinis.
- Le **statut socle du guide** (utilisateur socle affecté vs profil RH distinct de la Réservation
  générique) — ⚠ HYPOTHÈSE, voir §3.

## 3. Acteurs & droits
Les permissions réutilisent le modèle du socle (`RG-SOCLE-02/03/04/05`) : couple `module × action`,
portées par l'**établissement/espace actif**. La verticale introduit le module **`musee`** pour ses
objets propres (salles/sous-quotas, visites guidées, contingent de gratuités, partenaires OTA, pass
annuel) et **réutilise** les modules **`offre`** (catalogue, `spec-offre.md`), **`vente`** (encaissement,
`spec-vente.md`), **`acces`** (FMI, contrôle mobile, `spec-acces.md`), **`crm`** (adhérent, `spec-crm.md`)
et le futur module **`reservation`** (créneaux/liste d'attente) sans les redéfinir. Source : cahier §1
(tableau brique socle) et §2 (panel `p-musee`).

| Acteur | Peut | Ne peut pas | Permission (module × action) |
|---|---|---|---|
| **Gestionnaire d'offre culturelle** | Instancier/éditer les expositions (dates, jauge globale, TVA 10 %, réutilise `offre`) ; configurer les **salles et leurs sous-quotas** ; paramétrer la **politique de délestage** ; définir le **contingent de gratuités** par exposition/créneau ; créer/éditer le **pass annuel** (formule, réutilise `offre`) ; créer/éditer le produit **audioguide** | Superviser la jauge en temps réel ; affecter un guide à un créneau ; allouer un quota OTA | `offre × creer`, `offre × modifier` (réutilisées L1), `musee × configurer` |
| **Agent d'accueil / caisse** | Vendre un billet horodaté avec sélection de créneau obligatoire (réutilise `vente`) ; encaisser une visite guidée ou un audioguide ; enregistrer un dossier groupe/scolaire (effectif, gratuités, mode de paiement différé) ; adhérer/renouveler un pass annuel | Configurer salles/sous-quotas/contingents ; allouer des quotas OTA ; affecter un guide | `vente × creer`, `vente × encaisser` (réutilisées L2), `musee × gerer_dossier_groupe`, `musee × gerer_pass` |
| **Agent de contrôle mobile (salle)** | Scanner l'entrée/sortie d'une salle (réutilise `acces × controler`) ; visualiser et appliquer la **politique de délestage** en cas de saturation ; signaler une salle pleine à la supervision | Modifier le sous-quota configuré ; encaisser | `acces × controler` (réutilisée L3), `musee × superviser_salle` |
| **Coordinateur de visites guidées** | Planifier une visite (thème, langue, guide, capacité, point RDV) ; affecter un guide qualifié ; gérer la liste d'attente/bascule audioguide en cas d'indisponibilité linguistique | Configurer les sous-quotas de salle ; allouer un quota OTA | `musee × gerer_visite`, `reservation × gerer` ⚠ HYPOTHÈSE (module `reservation` non encore figé, cf. §2.3) |
| **Guide** | Consulter son planning et ses qualifications linguistiques ; contrôler en mobilité (réutilise `acces × controler`) | Modifier le planning, affecter un autre guide | `musee × lire`, `acces × controler` (réutilisée L3) |
| **Gestionnaire de distribution OTA** | Créer/éditer un partenaire OTA (quota alloué, tarif net, commission) ; consulter l'état des reversements ; arbitrer un conflit de sur-vente OTA (visibilité, pas de contournement de la priorité au 1ᵉʳ confirmé) | Modifier la jauge d'un créneau, les salles, les visites guidées | `musee × gerer_ota` |
| **Administrateur** | Tout ce qui précède + forcer la libération d'un quota OTA en cas d'incident (action journalisée) + gérer le référentiel des qualifications linguistiques des guides | — | `musee × gerer` (surensemble), `securite × gerer` (délégation, socle) |
| **Système** | Décrémenter le quota de créneau/salle à la réservation (RG-MUS-01) ; recalculer le sous-quota de salle à chaque passage mobile ; appliquer la priorité au 1ᵉʳ billet confirmé en cas de conflit OTA ; récupérer le quota d'un no-show OTA après le créneau ; décompter le contingent de gratuités scolaires | Vendre au-delà du quota d'un créneau/d'une salle ; attribuer une place OTA au-delà du quota alloué au partenaire | *(acteur technique — pas de permission humaine)* |
| **Client (en ligne/guichet)** | Acheter un billet horodaté, réserver une visite guidée dans la langue disponible, s'inscrire en liste d'attente ou basculer vers l'audioguide, adhérer à un pass annuel, consulter son quota de créneau restant | Réserver au-delà de la jauge du créneau/de la salle ; voir les réservations d'autrui | `reservation × reserver_soi` ⚠ HYPOTHÈSE (réutilisée du futur module Réservation), `musee × lire` |
| **Lecture seule** | Consulter le tableau de bord jauge/sous-quotas, l'agenda des guides, l'état des reversements OTA | Toute action d'écriture | `musee × lire`, `acces × lire`, `offre × lire` |

- ⚠ HYPOTHÈSE — Les noms de permissions `musee × configurer / gerer_dossier_groupe / gerer_pass /
  superviser_salle / gerer_visite / gerer_ota / gerer / lire` déclinent le tableau ci-dessus selon le
  modèle `module × action` du socle ; ils ne sont **pas nommés littéralement** dans les sources (le
  cahier ne détaille pas de tableau de droits fins pour le panel `p-musee`, contrairement à M1-M8). À
  **arbitrer avec M8** (même réserve que pour `piscine`/`sport` en L6/verticale Sport, cf. leurs specs §3).
- ⚠ HYPOTHÈSE — Le rôle **Guide** : utilisateur socle avec affectation établissement (retenu, par analogie
  avec l'**Encadrant MNS/BNSSA** de `spec-piscine.md` §3) vs profil RH distinct porté par le futur module
  Réservation générique. À confirmer dès que ce module sera spécifié.
- ⚠ HYPOTHÈSE — La permission `reservation × …` (réserver, gérer, liste d'attente) est **anticipée** ici
  par cohérence de nommage avec le futur module ; son périmètre exact sera fixé par
  `specs/reservation/spec-reservation.md`.

## 4. Comportements & règles
Chaque comportement trace une **RG-MUS** (source : `cahier-detaille.html`, panel `p-musee`, §3), une
**décision actée** (★, panel « 🏛️ Musée ») et/ou une **US-MUSEE** (définie §1). Les décisions actées
**font foi** et ne sont pas re-tranchées (constitution §6).

### 4.1 Timed-entry & jauge par exposition (US-MUSEE-01, RG-MUS-01) — [RÉUTILISE SOCLE, paramétré]
- **RG-MUS-01** — L'achat d'un billet pour une **exposition à jauge** impose la **sélection d'un
  créneau horaire**. Le **stock du créneau** (par expo **et** par tranche) est **décrémenté à la
  réservation** ; à quota nul, le créneau est **complet et non sélectionnable**.
- Ceci est une **application concrète** de la mécanique générique de créneau/jauge du futur module
  **Réservation** (⚠ non encore spécifié, §2.3) : le musée **impose** que toute exposition dont le champ
  `aJauge = vrai` **ne soit vendable qu'accompagnée d'un créneau**, sans exception guichet/en-ligne.
- L'écran de sélection affiche des **pastilles de disponibilité** (libre / tendu / complet), cohérentes
  avec la jauge recalculée en temps réel (cahier §2 « Timed-entry & jauge par exposition »).
- ⚠ HYPOTHÈSE — Le **seuil des paliers « tendu »** (ex. < 20 % de places restantes) n'est pas chiffré
  dans les sources ; retenu comme **paramétrable par établissement**, cohérent avec la règle de
  simplicité (constitution §2).

### 4.2 Sous-quota par salle & délestage (US-MUSEE-02, décision actée « Expo à forte affluence ») — [SPÉCIFIQUE MUSÉE]
- **Décision actée** — Une exposition à forte affluence combine **jauge par tranche horaire** (§4.1,
  niveau créneau/expo) **et** un **sous-quota par salle** (niveau plus fin, contrôlé par le **contrôle
  mobile**, cahier L3 écran A-04) **et** une **politique de délestage**.
- Une **salle** peut porter un **seuil d'occupation propre**, **indépendant** de la jauge du créneau
  d'entrée : un visiteur peut avoir un billet valide pour le créneau tout en se voyant **temporairement
  retenu à l'entrée d'une salle saturée** (délestage).
- Le comptage de salle **réutilise** le mécanisme de contrôle mobile L3 (`spec-acces.md` §4.10, écran
  A-04) : un agent scanne l'entrée/la sortie d'une salle avec son terminal mobile, ce qui
  **incrémente/décrémente** l'occupation de la salle, au même titre qu'un passage à un point d'accès
  fixe (réutilise `RG-ACC-01` — validation de passage — et le principe FMI par espace `RG-ACC-04`,
  spécialisé ici à la granularité **salle**).
- **Délestage** — Lorsque le sous-quota d'une salle est atteint, la **politique de délestage** configurée
  s'applique (ex. file d'attente à l'entrée de la salle gérée par l'agent mobile, redirection vers un
  autre point du parcours, simple alerte visuelle). Une **sortie** libère immédiatement une place.
- ⚠ HYPOTHÈSE — Le **mode d'application** du sous-quota (blocage strict façon POSS piscine `RG-PISC-01`
  vs simple alerte avec régulation humaine) n'est **pas tranché** par les sources : la décision actée
  mentionne « sous-quota + contrôle mobile + délestage » sans préciser si le blocage est **automatisé**
  (verrou physique impossible en intérieur de musée, sans tourniquet par salle) ou **piloté par
  l'agent** (retenu par défaut, cohérent avec l'absence de matériel de contrôle physique aux portes
  intérieures d'un musée). **À confirmer avec l'exploitant** avant paramétrage.
- ⚠ HYPOTHÈSE — Le **mécanisme concret de délestage** (file d'attente sur place / redirection de
  parcours / notification seule) n'est **pas détaillé** au-delà du principe acté ; retenu comme **policy
  paramétrable par salle**, cf. objet `PolitiqueDelestage` (§5).

### 4.3 Visites guidées — guide = ressource qualifiée (US-MUSEE-03, RG-MUS-02) — [SPÉCIFIQUE MUSÉE]
- **RG-MUS-02** — Une **visite guidée** ne peut être confirmée que si un **guide qualifié dans la
  langue demandée** est disponible sur le créneau ; sa **capacité propre** est **distincte** de la
  jauge d'entrée de l'exposition.
- Une visite porte : **thème**, **langue**, **guide affecté**, **capacité**, **point de rendez-vous**
  (cahier §2 « Visites guidées »).
- La **confirmation** d'une réservation de visite guidée vérifie **deux conditions indépendantes** :
  (1) une place dans la **capacité de la visite** (US-MUSEE-03) et (2) — en amont, lors de la
  planification — un **guide qualifié affecté** dans la langue proposée. La capacité de la visite **ne
  décrémente pas** la jauge d'entrée de l'expo (celle-ci est décomptée séparément par le billet
  d'entrée sous-jacent, §4.1) — les deux quotas coexistent.
- L'agenda du guide est **filtrable par langue** (cahier §2, écran).

### 4.4 Guide indisponible dans la langue — liste d'attente ou bascule audioguide (US-MUSEE-04, décision actée) — [SPÉCIFIQUE MUSÉE, s'appuie sur liste d'attente RÉUTILISÉE]
- **Décision actée** — Si aucun guide qualifié n'est disponible dans la langue demandée sur le créneau
  souhaité, le client se voit proposer **deux issues** (au choix, non exclusives) :
  1. **Liste d'attente** — inscription sur la **mécanique générique de liste d'attente** du futur module
     Réservation (⚠ non encore spécifiée, §2.3), avec **notification** en cas de libération/ajout de
     créneau dans la langue demandée.
  2. **Bascule audioguide + remise** — le client peut **basculer** vers un **audioguide** disponible dans
     la langue demandée (§4.11), avec une **remise** appliquée automatiquement sur le tarif visite
     guidée initialement visé (`BasculeAudioguide`, §5).
- Le **refus sec** (sans alternative proposée) n'est **pas** la conduite retenue — la décision actée
  écarte explicitement cette option par rapport aux trois évoquées en question ouverte du cahier (refus
  sec / liste d'attente / bascule audioguide).
- ⚠ HYPOTHÈSE — **Qui arbitre** l'annulation et le remboursement si le client ne souhaite **ni** la liste
  d'attente **ni** la bascule audioguide (ex. il avait déjà payé la visite guidée) : la question posée
  par le cahier (« qui arbitre l'annulation et le remboursement ? ») n'est **pas tranchée** par la
  décision actée, qui ne couvre que le **choix proposé au client**. Retenue par défaut : **annulation
  standard** via le circuit générique M2 (`RG-M2-07`, avoir/remboursement tracé), **sans automatisme**
  particulier musée. **À confirmer avec l'exploitant.**
- ⚠ HYPOTHÈSE — Le **taux/montant de la remise** appliquée à la bascule audioguide n'est **pas chiffré**
  ; retenu comme **paramétrable par établissement**.

### 4.5 Gratuités scolaires — consommation du quota + contingent dédié (US-MUSEE-05, RG-MUS-03, décision actée) — [SPÉCIFIQUE MUSÉE]
- **RG-MUS-03** — Les **gratuités** (scolaires, accompagnateurs) et le **paiement différé** (bon de
  commande / mandat) s'appliquent aux dossiers groupes & scolaires ; les **gratuités consomment
  néanmoins le quota de jauge** (créneau, §4.1).
- **Décision actée « Gratuités scolaires »** — Les entrées gratuites **consomment le quota de jauge**
  **au même titre que les entrées payantes** *et* décomptent en parallèle un **contingent de gratuités
  dédié, paramétrable** par établissement/exposition/créneau — pour **ne pas assécher la vente grand
  public**, conformément à la question ouverte du cahier explicitement tranchée par la décision.
- Une **gratuité** décrémente donc **deux compteurs simultanément** : (1) le **quota du créneau/expo**
  (RG-MUS-01) et (2) le **contingent de gratuités dédié** (`ContingentGratuite`, §5). Les deux doivent
  disposer de disponibilité pour que la gratuité soit accordée.
- ⚠ HYPOTHÈSE — La **conduite lorsque le contingent de gratuités dédié est épuisé** (alors que le quota
  de jauge du créneau, lui, reste disponible) n'est **pas précisée** par les sources : refus sec ? bascule
  automatique vers une entrée payante scolaire réduite ? mise en attente ? Retenue par défaut : **refus
  explicite** avec message dédié, cohérent avec le comportement générique « refus + message explicite »
  déjà retenu ailleurs dans le socle (ex. RG-ACC-02 carte épuisée). **À confirmer avec l'exploitant.**

### 4.6 Groupes & scolaires — dossier, paiement différé (US-MUSEE-06, RG-MUS-03) — [SPÉCIFIQUE MUSÉE]
- Un **dossier groupe/scolaire** porte : **établissement**, **effectif**, **accompagnateurs**,
  **gratuités**, **date d'option** (réservation provisoire avant confirmation, cahier §2 « Groupes &
  scolaires »).
- Le **paiement différé** (bon de commande administratif ou mandat) est **supporté** pour ce type de
  dossier — contrairement à la vente immédiate du guichet/en ligne (M2). Le **statut de paiement** du
  dossier est suivi indépendamment de la confirmation de la réservation de créneau/salle.
- Le dossier **coordonne** un ou plusieurs **guides** si des visites guidées sont associées (cahier §2 :
  « coordination guide(s) »).
- ⚠ HYPOTHÈSE — Le **mécanisme générique de paiement différé** (bon de commande / mandat administratif)
  n'est **pas spécifié transversalement** dans M2/M6 à ce jour (le socle M2 traite l'encaissement
  immédiat en caisse, `spec-vente.md` §4.1) ; cette spec retient un **statut de dossier propre au
  musée** (`DossierGroupeScolaire.statutPaiement`, §5), **à harmoniser avec M2/M6** lorsque le
  mécanisme générique de facturation différée / titre de recette public sera spécifié (parallèle avec
  « Groupes & scolaires » du plan de développement, lot V1 L14 : « devis, Chorus Pro, gratuités/ratios »).
- ⚠ HYPOTHÈSE — La **date d'option** (durée de réservation provisoire avant expiration si le dossier
  n'est pas confirmé) n'est **pas chiffrée** ; retenue comme **paramétrable par établissement**.

### 4.7 Distribution OTA — inventaire contrôlé & reversements (US-MUSEE-07, RG-MUS-04) — [SPÉCIFIQUE MUSÉE]
- **RG-MUS-04** — La vente OTA conserve le **contrôle de l'inventaire** : le **quota alloué** au
  partenaire est **décrémenté sur l'inventaire réel commun** (même stock que la vente directe), et
  chaque vente alimente le calcul des **reversements** (tarif net + commission).
- Un **partenaire OTA** porte : **nom**, **quota alloué**, **tarif net**, **commission**, historique de
  **reversements** (cahier §2 « Distribution OTA »).
- L'**allocation de quota** par partenaire est un **sous-ensemble** du quota du créneau/expo, **pas un
  stock séparé** : une vente OTA décrémente le **même compteur** de disponibilité que la vente directe
  (« anti sur-vente », cahier §2).
- L'écran d'**allocation de quotas** et l'**état des reversements** sont exposés au gestionnaire de
  distribution (cahier §2, écran).

### 4.8 Sur-vente OTA — priorité au 1ᵉʳ confirmé & récupération du no-show (US-MUSEE-08, décision actée) — [SPÉCIFIQUE MUSÉE]
- **Décision actée « Sur-vente OTA »** — En cas de **désynchronisation d'un partenaire** (deux ventes
  concurrentes sur la **même place** d'un créneau tendu), la **priorité** revient au **premier billet
  confirmé** (horodatage serveur faisant foi, comparable à une **clé d'idempotence**/estampille
  temporelle, cf. `Passage.idempotenceClé` en L3 pour l'esprit de la mécanique).
- Le **second billet en conflit** est **refusé côté OTA** (le partenaire doit gérer le remboursement de
  son client final selon ses propres CGV — hors périmètre applicatif musée) ou, si l'architecture le
  permet, **basculé automatiquement** sur le créneau/la disponibilité la plus proche — ⚠ HYPOTHÈSE, non
  détaillée par la décision actée, qui ne tranche que la **priorité**, pas la **conduite de résolution
  côté second client**.
- **Récupération du quota des no-show OTA** — Un billet OTA **non consommé** (aucun passage validé) au
  terme du créneau (+ marge éventuelle, RG-ACC-01 marges) est identifié comme **no-show** et son **quota
  est récupéré** et remis à disposition (vente directe ou autre allocation OTA) **après le créneau**.
- ⚠ HYPOTHÈSE — Le **délai exact de constatation du no-show** (immédiatement à la fin du créneau ? après
  une marge de tolérance héritée de L3 ?) n'est **pas chiffré** ; retenue une **constatation à la
  clôture du créneau + marge standard héritée de l'équipement d'accès** (`RG-ACC-01`).

### 4.9 Expositions temporaires — vente bornée aux dates (US-MUSEE-09, RG-MUS-06) — [SPÉCIFIQUE MUSÉE, s'appuie sur Produit RÉUTILISÉ]
- **RG-MUS-06** — Une exposition temporaire n'est vendable qu'entre ses **dates de début et de fin** ;
  hors période, **aucun créneau n'est proposé**.
- Une exposition porte : **dates début/fin**, **jauge globale**, **jauge par tranche** (créneaux, §4.1),
  et une **TVA culturelle 10 %** (cahier §2, spécialisation de la **catégorie comptable** du Produit,
  `RG-M1-05`).
- La **fermeture automatique** de la vente hors période **réutilise** le cycle de publication générique
  M1 (`RG-M1-09` : publication conditionnée) : une exposition dont la date courante est hors
  `[dateDébut, dateFin]` **n'expose plus de créneau vendable**, sans intervention manuelle nécessaire.

### 4.10 Pass annuel « Amis du musée » (US-MUSEE-10, RG-MUS-05) — [SPÉCIFIQUE MUSÉE, s'appuie sur Formule RÉUTILISÉE]
- **RG-MUS-05** — Un **pass annuel valide** donne accès à la **collection permanente** sans nouveau
  paiement, mais reste soumis au **choix d'un créneau** pour **toute exposition à jauge** (RG-MUS-01).
- Un pass porte : **adhérent** (nominatif, rattaché à une fiche M4/CRM), **date d'échéance**,
  **avantages** (coupe-file, tarifs préférentiels sur expos temporaires/visites/boutique), **QR
  nominatif** (cahier §2 « Pass & adhésions annuels »).
- Le pass est une **spécialisation de Formule d'abonnement** (M1, `RG-M1-03`) : droit d'accès
  **illimité** sur la collection permanente, **sans** droit d'accès automatique aux expositions
  temporaires à jauge — celles-ci **restent soumises** à la réservation d'un créneau (RG-MUS-01), même
  sans paiement supplémentaire pour le porteur du pass.
- Le **renouvellement** et l'affichage de la **carte de membre QR** réutilisent les mécanismes génériques
  d'abonnement M1 (renouvellement auto/manuel, `spec-offre.md` §5 « Formule ») et de support d'accès L3
  (`Support`, type QR/wallet).

### 4.11 Audioguides (US-MUSEE-11) — [SPÉCIFIQUE MUSÉE, produit boutique RÉUTILISÉ]
- L'**audioguide** est un **produit boutique optionnel** (M1), décliné par **langue disponible**,
  rattachable à un billet d'entrée ou à une visite (cahier §2 « Types de billets »).
- Il constitue l'**issue de repli** de la règle §4.4 (guide indisponible dans la langue demandée) : la
  **bascule audioguide** applique une **remise** sur le tarif de la visite guidée initialement
  souhaitée (`BasculeAudioguide`, §5).
- ⚠ HYPOTHÈSE — Le **catalogue de langues disponibles en audioguide** (nombre, langues rares) n'est pas
  chiffré dans les sources ; retenu comme **paramétrable par établissement**, cohérent avec l'absence de
  logique métier codée en dur (constitution §4.4).

## 5. Objets de données
Les types PHP sont indicatifs (spec = comportement observable). Tout objet est rattaché à un
**Établissement/Espace** via le socle (`RG-SOCLE-01`) ; identifiants = **UUID** (constitution §3). Les
objets M1 (`Produit`, `Formule`), M2 (`Commande`, `Panier`, `Paiement`), L3 (`EspaceAccès`, `JaugeFmi`,
`Passage`, `Support`), M4 (`Client`, `Famille`, `Beneficiaire`) et le futur module Réservation (`Créneau`,
`JaugeCréneau`, `ListeAttente`) sont **référencés, non redéfinis** — voir `spec-offre.md` §5,
`spec-vente.md` §5, `spec-acces.md` §5, `spec-crm.md` §5.

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| **Exposition** *(spécialisation de Produit, M1)* | id | uuid | PK | RG-M1-01/02, RG-MUS-06 |
| | libellé | string (i18n) | requis | cahier §4 |
| | dateDébut, dateFin | date | début < fin | RG-MUS-06 |
| | aJauge | bool | requis | pilote l'obligation de créneau (RG-MUS-01) |
| | jaugeGlobale | int > 0 | requis si aJauge | cahier §4 |
| | tva | decimal | défaut 10 % (culturel) | cahier §1, ventilation M6 |
| **CréneauExpo** *(spécialisation provisoire de Créneau, module Réservation ⚠ non encore spécifié)* | id | uuid | PK | RG-MUS-01 |
| | expositionRef | ref Exposition | requis | — |
| | date, tranche | date, {début,fin} heure | requis | cahier §2 |
| | quota, quotaRestant | int ≥ 0 | quotaRestant ≤ quota | décrément à la réservation (RG-MUS-01) |
| | statut | enum {libre, tendu, complet} | dérivé | pastilles disponibilité (cahier §2) |
| **Salle** | id, nom | uuid, string | requis | rattachée à un Espace (socle), §4.2 |
| | expositionRef | ref Exposition? | optionnel | salle rattachée ou non à une expo précise |
| **SousQuotaSalle** | id, salleRef | uuid, ref | 1 Salle ↔ 0..1 SousQuotaSalle | décision actée §4.2 |
| | seuilOccupation | int > 0 | requis | paramétrable |
| | occupationCourante | int ≥ 0 | dérivé (entrées − sorties du contrôle mobile) | réutilise le principe FMI (RG-ACC-04) |
| | mode | enum {blocage, alerte} | ⚠ HYPOTHÈSE défaut = alerte | non tranché par les sources (§4.2) |
| **PolitiqueDelestage** | id, salleRef | uuid, ref | 1-1 | ⚠ HYPOTHÈSE, mécanique non détaillée (§4.2) |
| | mode | enum {file_attente_sur_place, redirection_parcours, alerte_seule} | paramétrable | défaut ⚠ non chiffré |
| **Guide** *(ressource, ⚠ statut socle hypothèse §3)* | id | uuid | PK | US-MUSEE-03 |
| | utilisateurRef | ref Utilisateur (socle) | requis | ⚠ HYPOTHÈSE, voir §3 |
| **QualificationLangueGuide** | id, guideRef | uuid, ref | requis | US-MUSEE-03/04 |
| | langue | ref Référentiel langues | requis | filtre agenda (§4.3) |
| **VisiteGuidée** | id | uuid | PK | RG-MUS-02 |
| | thème | string | requis | cahier §2 |
| | langue | ref Référentiel langues | requis | doit matcher une QualificationLangueGuide du guide affecté |
| | guideRef | ref Guide? | requis pour confirmation | RG-MUS-02 |
| | créneauExpoRef | ref CréneauExpo | requis | rattache la visite à un créneau d'entrée |
| | capacité, placesRestantes | int > 0, int ≥ 0 | placesRestantes ≤ capacité | **distincte** de la jauge d'entrée (RG-MUS-02) |
| | pointRDV | string | requis | cahier §2 |
| **ListeAttenteVisite** *(application de ListeAttente générique, module Réservation ⚠ non spécifié)* | id, visiteGuidéeRef | uuid, ref | requis | US-MUSEE-04 |
| | clientRef, rang, dateInscription | ref Client, int, datetime | requis | file générique réutilisée |
| **BasculeAudioguide** | id | uuid | PK | US-MUSEE-04/11 |
| | visiteGuidéeRefInitiale | ref VisiteGuidée? | optionnel (contexte de la demande initiale) | — |
| | audioguideRef | ref Audioguide | requis | produit de repli |
| | tauxRemise | decimal (0–100 %) | paramétrable | ⚠ HYPOTHÈSE non chiffré (§4.4) |
| **ContingentGratuite** | id | uuid | PK | décision actée §4.5 |
| | périmètre | enum {exposition, créneau} | requis | paramétrable par établissement |
| | expositionRef / créneauExpoRef | ref | requis selon périmètre | — |
| | quotaGratuitésDédié, quotaConsommé | int ≥ 0 | quotaConsommé ≤ quotaGratuitésDédié | décompté en parallèle du quota de jauge (RG-MUS-03) |
| **DossierGroupeScolaire** | id | uuid | PK | US-MUSEE-06, RG-MUS-03 |
| | établissement | string | requis | cahier §2 |
| | effectif, accompagnateurs | int > 0, int ≥ 0 | requis | — |
| | gratuités[] | ref Gratuite[] | — | consomme ContingentGratuite + jauge (§4.5) |
| | dateOption | date? | ⚠ HYPOTHÈSE durée non chiffrée | réservation provisoire |
| | statutPaiement | enum {en_option, bon_commande_émis, mandat_émis, payé} | ⚠ HYPOTHÈSE, § 4.6 | paiement différé |
| | guidesAffectés[] | ref Guide[] | — | coordination (cahier §2) |
| **Gratuite** | id, dossierRef | uuid, ref | requis | RG-MUS-03 |
| | motif | enum {élève, accompagnateur} | requis | — |
| | créneauExpoRef | ref CréneauExpo | requis | consomme le quota (§4.5) |
| **PartenaireOTA** | id, nom | uuid, string | requis | RG-MUS-04 |
| | tarifNet, commission | decimal, decimal | ≥ 0 | cahier §2 |
| **AllocationQuotaOTA** | id, partenaireRef | uuid, ref | requis | RG-MUS-04 |
| | créneauExpoRef | ref CréneauExpo | requis | sous-ensemble du quota du créneau (§4.7) |
| | quotaAlloué, quotaConsommé | int ≥ 0 | quotaConsommé ≤ quotaAlloué | anti sur-vente (RG-MUS-04) |
| **ReservationOTA** | id, allocationRef | uuid, ref | requis | US-MUSEE-08 |
| | horodatageConfirmation | datetime | requis | clé de priorité 1ᵉʳ confirmé (§4.8) |
| | statut | enum {confirmée, refusée_conflit, no_show, consommée} | défaut = confirmée | §4.8 |
| **Reversement** | id, partenaireRef | uuid, ref | requis | RG-MUS-04 |
| | période, montant | (début,fin), decimal | — | tarif net + commission |
| **PassAnnuel** *(spécialisation de Formule, M1)* | id | uuid | PK | RG-MUS-05 |
| | adhérentRef | ref Beneficiaire (M4) | requis, nominatif | cahier §2 |
| | échéance | date | requis | RG-MUS-05 |
| | avantages[] | set {coupe_file, tarif_préférentiel} | — | cahier §2 |
| | supportRef | ref Support (L3, QR) | requis | carte de membre QR nominatif |
| **Audioguide** *(produit boutique, M1)* | id | uuid | PK | US-MUSEE-11 |
| | langues[] | set (ref Référentiel langues) | ≥ 1 | ⚠ HYPOTHÈSE catalogue non chiffré (§4.11) |

## 6. Critères d'acceptation
- **CA-1 (US-MUSEE-01, RG-MUS-01)** — *Étant donné* une exposition à **jauge** (`aJauge = vrai`),
  *quand* un client tente d'acheter un billet, *alors* la **sélection d'un créneau** (tranche horaire)
  est **imposée**, le **stock du créneau** (par expo et par tranche) est **décrémenté à la
  réservation**, et *quand* le quota atteint zéro, *alors* le créneau devient **complet** et **non
  sélectionnable**.
- **CA-2 (US-MUSEE-02, décision actée)** — *Étant donné* une salle dotée d'un **sous-quota**, *quand*
  l'occupation (comptée par le **contrôle mobile**) atteint le **seuil**, *alors* la **politique de
  délestage** configurée s'applique (file d'attente / redirection / alerte) ; *quand* une sortie de
  salle est scannée, *alors* l'occupation **décrémente immédiatement** et un nouvel entrant redevient
  admissible, **indépendamment** de la jauge du créneau d'entrée (déjà validée en amont).
- **CA-3 (US-MUSEE-03, RG-MUS-02)** — *Étant donné* une demande de **visite guidée** dans une **langue**
  donnée, *quand* un **guide qualifié dans cette langue** est disponible sur le créneau, *alors* la
  réservation peut être **confirmée** dans la limite de la **capacité propre** de la visite,
  **indépendante** de la jauge d'entrée de l'exposition.
- **CA-4 (US-MUSEE-04, décision actée)** — *Étant donné* une demande de visite guidée **sans guide
  qualifié disponible** dans la langue demandée, *quand* le client est informé de l'indisponibilité,
  *alors* il se voit proposer soit l'**inscription en liste d'attente**, soit la **bascule vers un
  audioguide** dans la langue demandée **avec remise**, sans refus sec par défaut.
- **CA-5 (US-MUSEE-05, RG-MUS-03, décision actée)** — *Étant donné* une **gratuité scolaire** (élève ou
  accompagnateur) rattachée à un dossier groupe, *quand* elle est accordée, *alors* elle **décrémente à
  la fois** le **quota de jauge du créneau** (comme une entrée payante) **et** le **contingent de
  gratuités dédié** de l'exposition/du créneau ; *quand* le contingent dédié est **épuisé**, *alors* la
  gratuité est **refusée avec message explicite**, même si le quota de jauge global reste disponible.
- **CA-6 (US-MUSEE-06, RG-MUS-03)** — *Étant donné* un dossier groupe/scolaire, *quand* il est créé,
  *alors* il porte **établissement, effectif, accompagnateurs, gratuités et date d'option**, et permet
  un **paiement différé** (bon de commande / mandat) sans bloquer la réservation de créneau/salle avant
  encaissement effectif.
- **CA-7 (US-MUSEE-07, RG-MUS-04)** — *Étant donné* un **partenaire OTA** avec un **quota alloué** sur
  un créneau, *quand* une vente OTA est confirmée, *alors* elle **décrémente le même inventaire réel**
  que la vente directe (pas de stock séparé), et **alimente le calcul du reversement** (tarif net +
  commission).
- **CA-8 (US-MUSEE-08, décision actée)** — *Étant donné* une **désynchronisation** entre deux ventes
  concurrentes sur la **même place** d'un créneau OTA tendu, *quand* les deux confirmations arrivent,
  *alors* la **priorité** est donnée au **billet dont l'horodatage de confirmation est le plus
  ancien** ; *quand* un billet OTA n'est **pas consommé** (aucun passage validé) au terme du créneau,
  *alors* il est marqué **no-show** et son **quota est récupéré** et remis à disposition.
- **CA-9 (US-MUSEE-09, RG-MUS-06)** — *Étant donné* une exposition temporaire, *quand* la date courante
  est **hors** de `[dateDébut, dateFin]`, *alors* **aucun créneau n'est proposé à la vente**, sur aucun
  canal.
- **CA-10 (US-MUSEE-10, RG-MUS-05)** — *Étant donné* un **pass annuel valide**, *quand* le porteur
  souhaite accéder à la **collection permanente**, *alors* l'accès est accordé **sans nouveau
  paiement** ; *quand* il souhaite accéder à une **exposition à jauge**, *alors* il **doit néanmoins
  sélectionner un créneau** (CA-1), sans paiement supplémentaire mais avec **décrément du quota du
  créneau** au même titre qu'un billet payant.
- **CA-11 (US-MUSEE-11)** — *Étant donné* un billet ou une visite, *quand* le client ajoute un
  **audioguide**, *alors* il choisit une **langue disponible** dans le catalogue paramétré par
  l'établissement ; *quand* l'audioguide est utilisé comme **bascule** (CA-4), *alors* la **remise**
  paramétrée s'applique automatiquement sur le tarif de la visite guidée initialement visée.

## 7. Cas limites
- **Expo à forte affluence, créneau vendu mais salle saturée** — Régulé par le **sous-quota de salle +
  contrôle mobile + délestage** (décision actée, §4.2, CA-2) ; ⚠ le **mode d'application** (blocage
  strict vs alerte/régulation humaine) et le **mécanisme précis de délestage** ne sont **pas détaillés**
  au-delà du principe acté — à préciser avec l'exploitant avant configuration.
- **Guide indisponible dans la langue** — Liste d'attente **ou** bascule audioguide + remise (décision
  actée, §4.4, CA-4) ; ⚠ **qui arbitre l'annulation/remboursement** si le client refuse les deux options
  et **le taux de remise exact** de la bascule **ne sont pas tranchés** par les sources.
- **Contingent de gratuités scolaires épuisé alors que le quota de jauge est disponible** — ⚠
  **non tranché** par les sources (la décision actée pose le principe du contingent dédié sans en
  préciser la conduite à l'épuisement) ; retenu par défaut **refus explicite** (§4.5), à confirmer avec
  l'exploitant.
- **Sur-vente OTA sur créneau tendu** — Priorité au **1ᵉʳ billet confirmé** ; récupération du quota des
  **no-show OTA** (décision actée, §4.8, CA-8) ; ⚠ la **conduite côté second client refusé** (remboursement
  automatique côté partenaire, proposition d'un autre créneau) et le **délai exact de constatation du
  no-show** ne sont **pas détaillés**.
- **Dossier groupe/scolaire non confirmé au-delà de la date d'option** — ⚠ **non tranché** : la
  spec suppose une **libération automatique** du créneau/des places réservées provisoirement, par
  analogie avec l'abandon de panier générique M3 (« stock réservé 15 min puis libéré », cahier §M3,
  décision actée) — mais **aucune décision actée spécifique musée** ne couvre ce délai groupe
  (généralement plus long qu'un panier individuel). **À préciser avec l'exploitant.**
- **Pass annuel + exposition temporaire hors jauge** (exposition sans `aJauge`) — Non couvert
  explicitement par RG-MUS-05 (qui ne parle que des expos **à jauge**) ; ⚠ HYPOTHÈSE : une exposition
  temporaire **sans jauge** est incluse dans l'accès illimité du pass au même titre que la collection
  permanente, par cohérence avec le principe « le pass donne accès sans nouveau paiement sauf contrainte
  de créneau » — **à confirmer**.
- **Visite guidée sans créneau d'entrée associé sur une exposition à jauge** — ⚠ HYPOTHÈSE : la
  réservation d'une visite guidée sur une exposition à jauge **suppose implicitement** la réservation
  simultanée d'un billet d'entrée horodaté sur le même créneau (RG-MUS-01) — l'articulation exacte
  (vente couplée automatique vs deux actes d'achat distincts à faire coïncider manuellement) n'est **pas
  détaillée** par les sources.
- **Audioguide en rupture dans une langue** (matériel physique limité) — Hors périmètre RG applicatif :
  question de **stock matériel physique**, potentiellement rattachable au `Stock` générique M1
  (`RG-M1-10`), non détaillée spécifiquement pour l'audioguide par les sources.
- **Établissement soumis à une réglementation de sécurité ERP sur l'occupation de salle** (comme la
  POSS piscine) — ⚠ Si le sous-quota de salle répond à une **contrainte réglementaire de sécurité**
  (et non seulement de confort de visite), le **mode blocage strict** façon `RG-PISC-01` devrait
  s'appliquer plutôt que l'alerte par défaut retenue en §4.2 — **point réglementaire non levé par les
  sources**, à valider avec l'exploitant/la commission de sécurité avant mise en service (même réserve
  que `spec-piscine.md` §7).

## 8. Dépendances
- **Dépend de : socle L0** (`specs/L0-socle/spec-socle.md`) — hiérarchie Groupe/Région/Établissement/
  Espace (`RG-SOCLE-01`) à laquelle se rattachent salles, expositions et créneaux ; permissions
  `module × action` réutilisées et étendues au module **`musee`** (`RG-SOCLE-02/03/04`) ; journal
  d'audit append-only (`RG-SOCLE-07`) pour les allocations OTA et modifications de sous-quota.
- **Dépend de : M1 · Offre & Tarification** (`specs/L1-offre/spec-offre.md`) — modèle générique de
  **Produit** instancié par `Exposition` (dates/jauge globale, RG-M1-01/02/09) ; **Formule**
  instanciée par `PassAnnuel` (droit d'accès illimité, RG-M1-03) ; **Audioguide** = produit boutique
  standard ; catégorie comptable pour la TVA culturelle 10 % (RG-M1-05).
- **Dépend de : M2 · Vente & Caisse** (`specs/L2-vente/spec-vente.md`) — encaissement des billets,
  visites guidées, audioguides et adhésions pass annuel (panier, paiement scindé) ; remboursement/avoir
  générique (`RG-M2-07`) réutilisé pour les cas limites §7 (guide indisponible sans issue acceptée).
- **Dépend de : L3 · Contrôle d'accès** (`specs/L3-acces/spec-acces.md`) — validation de passage au
  tourniquet d'entrée (`RG-ACC-01`), jauge FMI (`RG-ACC-04`) spécialisée à la granularité **salle**
  (§4.2), **contrôle mobile** (écran A-04) réutilisé tel quel pour le scan des salles, `Support`/QR
  pour le pass annuel nominatif. **L3 n'est pas redéfini** ; le musée en **paramètre l'usage**.
- **Dépend de : M4/CRM** (`specs/L5-crm/spec-crm.md`) — `Beneficiaire` porteur du **pass annuel**
  nominatif (`RG-M4-02`) ; le musée rattache le pass à un bénéficiaire, sans redéfinir la fiche famille.
- **Dépend de : M6 · Compta & Régie** (`specs/L4-compta/spec-compta.md`) — TVA culturelle 10 % (ventilation
  par taux, cahier §M6) ; comptabilisation des **reversements OTA** ; **paiement différé** des dossiers
  groupes/scolaires (bon de commande / mandat administratif public), à harmoniser avec le futur lot
  « Groupes & scolaires » (plan V1, lot L14 : Chorus Pro, gratuités/ratios).
- **Dépend de (référencé, non spécifié dans le dépôt) : module Réservation & no-show générique**
  (`specs/reservation/spec-reservation.md`, **en cours de spécification**) — concepts génériques de
  **créneau**, **jauge par créneau**, **liste d'attente**, au-dessus desquels ce document construit
  `CréneauExpo`, `VisiteGuidée`, `ListeAttenteVisite` (§5) ; **aucune spec `spec-reservation.md`
  n'existe encore** dans `specs/` à la date de rédaction — **risque de divergence** à traiter en
  priorité lors de la spécification de ce module (même situation documentée pour M5 dans
  `spec-piscine.md` §8 et `spec-sport.md` §8).
- **Dépend de (référencé, non spécifié dans le dépôt) : M3 · Boutique & connecteurs OTA** — le **volet
  technique** du connecteur (protocole, format d'échange, fréquence de synchro avec chaque plateforme)
  relève de M3 (cahier §M3, RG-M3-09), **non encore spécifié** (lot V1 L8). Le musée pose le
  **comportement observable** attendu (inventaire partagé, priorité, no-show) ; l'intégration technique
  par plateforme est **hors périmètre**.

---

## Points ouverts / hypothèses (récapitulatif)
1. **⚠ GAP DE DÉPENDANCE — Absence de spec Réservation générique (priorité haute)** : `CréneauExpo`,
   `VisiteGuidée`, `ListeAttenteVisite` sont modélisés en §5 comme des **spécialisations provisoires**
   de concepts génériques (Créneau/JaugeCréneau/ListeAttente) faute de `spec-reservation.md` livrée à ce
   jour ; à **réconcilier en priorité** dès sa spécification (§2.3, §8).
2. **⚠ GAP DE DÉPENDANCE — Connecteur technique OTA (M3) non spécifié** : le comportement observable
   (inventaire partagé, priorité 1ᵉʳ confirmé, récupération no-show) est posé ici, mais l'intégration
   technique par plateforme relève d'un futur `spec-boutique.md`/M3 non encore livré (§2.3, §8).
3. **⚠ HYPOTHÈSE — Backlog absent** : les 85 US du MVP (`backlog.html`) ne couvrent pas le musée ; les
   11 US-MUSEE de cette spec sont **définies par l'agent** à partir du cahier §« Musée » et des
   décisions actées, à faire valider/numéroter officiellement (§1).
4. **⚠ HYPOTHÈSE — Mode d'application du sous-quota de salle** : blocage strict (façon POSS piscine) vs
   alerte + régulation humaine — non tranché, retenu « alerte » par défaut faute de matériel de contrôle
   physique aux portes intérieures (§4.2, §7).
5. **⚠ HYPOTHÈSE — Mécanique précise du délestage** : file d'attente sur place / redirection de
   parcours / alerte seule — principe acté, mécanisme non détaillé (§4.2, §5, §7).
6. **⚠ HYPOTHÈSE — Arbitrage annulation/remboursement si guide indisponible et audioguide/liste d'attente
   refusés par le client** : non tranché par la décision actée (§4.4, §7).
7. **⚠ HYPOTHÈSE — Taux de remise de la bascule audioguide** : paramétrable, non chiffré (§4.4, §5).
8. **⚠ HYPOTHÈSE — Conduite à l'épuisement du contingent de gratuités scolaires** (quota de jauge encore
   disponible) : retenu refus explicite par défaut, non tranché par les sources (§4.5, §7).
9. **⚠ HYPOTHÈSE — Conduite côté second client refusé en cas de conflit de sur-vente OTA**, et **délai
   exact de constatation du no-show** : non détaillés au-delà du principe de priorité acté (§4.8, §7).
10. **⚠ HYPOTHÈSE — Statut socle du Guide** : utilisateur socle avec affectation établissement (retenu,
    par analogie avec l'Encadrant MNS/BNSSA de `spec-piscine.md`) vs profil RH porté par le futur module
    Réservation (§3).
11. **⚠ HYPOTHÈSE — Mécanisme générique de paiement différé** (bon de commande / mandat administratif) :
    non spécifié transversalement en M2/M6 à ce jour ; statut de dossier propre retenu en attendant, à
    harmoniser avec le futur lot « Groupes & scolaires » (V1, L14) (§4.6, §8).
12. **⚠ HYPOTHÈSE — Durée de la date d'option d'un dossier groupe/scolaire non confirmé** : non
    chiffrée par les sources, comportement de libération automatique posé par analogie avec l'abandon de
    panier M3 (§7).
13. **⚠ HYPOTHÈSE — Noms des permissions `musee × …`** : dérivés du tableau Acteurs & droits selon le
    modèle socle, à figer avec M8 comme pour `piscine`/`sport` (§3).
14. **⚠ Point réglementaire — Sous-quota de salle vs sécurité ERP** : si la contrainte est réglementaire
    (et non de confort), le mode devrait être un **blocage strict** façon POSS piscine — non tranché par
    les sources, à valider avec l'exploitant/la commission de sécurité (§7).
