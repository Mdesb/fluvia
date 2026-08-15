# Spec — Verticale Centre de Padel (`Padel` / lot post-MVP, V2 L16, hors ordre L0→L7)

- **Lot / module :** Verticale **Padel** (V2, plan de dev §L16 « Patinoire · Sport · Padel · Musée »),
  au-dessus du socle **M1–M8** déjà spécifié — priorité produit : **le terrain est une ressource
  réservable à l'heure**, tout le reste n'est que paramétrage (cahier `p-padel` §1).
- **Stories couvertes :** **US-PADEL-01 à US-PADEL-10** — ⚠ **HYPOTHÈSE** : comme les verticales Sport
  et Musée, Padel est **absente de `backlog.html`** (les lots documentés s'arrêtent à L7). Ces user
  stories sont **définies par cet agent** à partir du panel `p-padel` du cahier détaillé (§1
  « Positionnement », §2 « Fonctions & écrans spécifiques ») et des décisions actées ★ « 🎾 Padel ».
  **À faire valider et numéroter officiellement** dans le backlog avant développement.
- **Règles de gestion :** RG-PADEL-01 à RG-PADEL-07 (`cahier-detaille.html`, panel `p-padel`, §3) +
  décisions actées ★ « 🎾 Padel » (5 points, dont 2 marqués ✱ modifiés par rapport à la recommandation
  initiale) + règles socle **réutilisées, non redéfinies** : réservation de ressource / no-show /
  paiement partagé / récurrence (`specs/reservation/spec-reservation.md` — ⚠ **en cours de rédaction,
  n'existe pas encore dans ce dépôt à la date de cette spec**, cf. §8), RG-M1-01/03/06/07 (`spec-offre.md`),
  RG-M2-02/03/04 (`spec-vente.md`), RG-M4-02 (`spec-crm.md`), RG-ACC-01/02/05 (`spec-acces.md`),
  RG-SOCLE-01 à 07 (`spec-socle.md`).
- **Statut :** brouillon — ⚠ dépend d'une spec socle non encore écrite (`specs/reservation/`) ; plusieurs
  points d'articulation (grille tarifaire intraday, pilotage éclairage) à confirmer avant figement
  (cf. §8 et récapitulatif final).

## 1. Objectif
Permettre à un centre de padel de vendre la **réservation d'un terrain à l'heure** en s'appuyant
**entièrement** sur le moteur générique de réservation de ressource du socle (créneau, no-show/
annulation tardive, paiement partagé, récurrence — déjà génériques), et de n'ajouter que ce qui est
**propre au padel** : une tarification pleine/creuse × membre, le **matching de joueurs** (partie
ouverte à compléter jusqu'à 4, **toujours maintenue à 3** si le 4ᵉ manque), un **niveau de jeu validé
par le club**, des **tournois/ligues**, la location de matériel, les cours avec/sans coach, et le
pilotage automatique de l'**éclairage** du terrain sur la fenêtre réservée. Cahier §1 : « on ne
réinvente rien, on paramètre ».

## 2. Périmètre
- **Inclus :**
  - **Réservation d'un terrain à l'heure** (60/90 min), instance du moteur générique de réservation de
    ressource, **configurée** pour la ressource « terrain de padel » — US-PADEL-01, cahier §2
    « Réservation de terrain à l'heure ».
  - **Tarification pleine/creuse × statut membre/non-membre**, appliquée automatiquement à chaque
    réservation — US-PADEL-01, RG-PADEL-02.
  - **Partie ouverte (matching de joueurs)** : une réservation à 2 s'ouvre pour se compléter jusqu'à 4,
    filtrage par niveau, complétion partielle **toujours maintenue à 3** avec surcoût réparti —
    US-PADEL-02/03, RG-PADEL-03, décision actée « Partie ouverte 3/4 ».
  - **Niveau de jeu** déclaré par le joueur puis **validé par le club** — US-PADEL-04, décision actée
    « Niveau de jeu ».
  - **Tournois & ligues** : format poules/tableau, inscription par paire, frais, terrains bloqués,
    saisie des scores, classement — US-PADEL-05/06/07, cahier §2 « Tournois & ligues ».
  - **Articulation récurrence ↔ tournoi** : un tournoi qui bloque un terrain occupé par une réservation
    récurrente déclenche le **report automatique** générique du socle sur un autre terrain, à défaut
    **validation manuelle** — US-PADEL-07, décision actée « Récurrence vs tournoi » (réutilise le
    mécanisme générique de récurrence, la verticale ne fait que le **configurer** sur la ressource
    terrain).
  - **Location de raquettes & balles** rattachée à la réservation — US-PADEL-08, cahier §2.
  - **Réservation avec/sans coach** (cours particulier ou collectif) — US-PADEL-09, cahier §2.
  - **Éclairage automatique du terrain**, relais piloté par la fenêtre de réservation (allumage/
    extinction), avec repli manuel si défaut — US-PADEL-10, RG-PADEL-05.
  - **Configuration** de l'annulation tardive/no-show (délai franc, montant/%, exonération membre) et
    du paiement partagé, **au niveau padel** (valeurs de paramètres), sans redéfinir le mécanisme —
    RG-PADEL-04/06, décisions actées.
- **Exclu (pour l'instant), que la verticale *référence* seulement :**
  - Le **moteur générique de réservation de ressource** (créneau, disponibilité, chevauchement,
    calendrier multi-ressources) → **`specs/reservation/`** (en cours de rédaction ailleurs). Le padel
    **instancie** ce moteur pour la ressource « terrain », il ne le redéfinit pas.
  - Le **no-show / annulation tardive** (délai franc paramétrable → facturation, cf. décision actée) →
    **générique réservation socle**. Le padel se contente de **paramétrer** les valeurs (délai, montant,
    exonération) ; le comportement (déclenchement, facturation, trace) est décrit dans
    `spec-reservation.md`, pas ici.
  - Le **paiement partagé entre participants** (répartition du montant, encaissement individuel,
    **organisateur solidaire** du reste à payer en cas de défaillance) → **générique réservation socle**
    (décision actée « Paiement partagé défaillant »). Le padel **consomme** ce mécanisme pour répartir
    le prix du créneau entre les joueurs, sans le redéfinir.
  - La **récurrence** (créneau fixe hebdomadaire, priorité, report/suspension) → **générique réservation
    socle**. Le padel configure un créneau récurrent sur un terrain, le mécanisme de report/priorité est
    hérité tel quel.
  - Le **moteur de catalogue/tarification** (types de produit, grille produit × type de tarif × saison,
    formules d'adhésion membre) → **M1** (`spec-offre.md`, RG-M1-01/03/07). La verticale **instancie**
    une grille terrain au-dessus de M1 ; ⚠ voir §4.2 — le découpage **pleine/creuse intrajournalier**
    n'est **pas** un axe du modèle `GrilleTarifaire` actuel de M1 (produit × type de tarif × **saison**
    = plage de **dates**, pas d'heures), ce qui constitue un **point d'extension à valider avec M1**.
  - La **vente/caisse, le paiement scindé, les TPE** → **M2** (`spec-vente.md`). Le padel **consomme**
    le panier/paiement M2 pour l'encaissement des parts de créneau, du matériel loué et des frais de
    tournoi.
  - Le **compte joueur, la fiche client/famille, le statut membre** → **M4/CRM** (`spec-crm.md`,
    RG-M4-02). La verticale **consomme** le `Client`/`Beneficiaire` existant (joueur), ne le redéfinit
    pas ; le **niveau de jeu** est une donnée **propre au padel** rattachée à ce compte (§4.4).
  - Le **contrôle d'accès badge** (topologie, validation au tourniquet, hors-ligne/synchro) →
    **L3 Accès** (`spec-acces.md`, RG-ACC-01/02/05). Le badge terrain s'ouvre sur la **fenêtre de
    réservation** (`DroitAccès` projeté depuis la réservation, mécanisme déjà décrit pour d'autres
    verticales) ; la verticale ne redéfinit **pas** ce mécanisme. Seul le **relais d'éclairage** est
    une capacité **nouvelle**, non couverte par L3 (qui pilote des tourniquets/lecteurs, pas des
    actionneurs lumière) — cf. §4.8.
  - L'**UI (front)**, l'**authentification, les rôles/permissions, le journal d'audit** → **socle L0**
    (`spec-socle.md`), réutilisés et non redéfinis ici.

## 3. Acteurs & droits
Les permissions réutilisent le modèle du socle (`RG-SOCLE-02/03/04/05`) : couple `module × action` sur
le module **`padel`**, portées par l'**établissement actif** ; l'UI **masque** ce qui n'est pas autorisé
(`RG-SOCLE-04`). Source : cahier `p-padel` §1/§2 (aucun tableau « Acteurs & droits » dédié dans le
cahier pour cette verticale — le découpage ci-dessous est **dérivé** du texte fonctionnel).

| Acteur | Peut | Ne peut pas | Permission (module × action) |
|---|---|---|---|
| **Joueur / Adhérent** (compte Client final, réutilise M4/M3) | Réserver un terrain (seul ou en partie ouverte), rejoindre une partie ouverte filtrée par niveau, payer sa part, déclarer/mettre à jour son niveau **proposé**, s'inscrire à un tournoi (par paire), louer du matériel, réserver avec/sans coach, consulter son historique de parties et son statut d'accès | Valider son propre niveau, forcer l'ouverture d'un terrain hors fenêtre réservée, bloquer un terrain pour un tournoi, modifier la grille tarifaire | `padel × reserver_soi`, `padel × partie_rejoindre_soi`, `padel × niveau_declarer_soi`, `padel × tournoi_inscrire_soi` |
| **Gestionnaire de club / Agent d'accueil** | Créer/gérer un terrain, **valider le niveau de jeu** déclaré par un joueur, créer et gérer un tournoi (format, poules, terrains bloqués), saisir les scores, gérer le stock de matériel loué (raquettes/balles, caution), forcer une réouverture d'accès/éclairage (motif requis) en cas de défaut relais, arbitrer une validation manuelle récurrence vs tournoi | Modifier la politique anti-no-show (paramètres) ; révoquer un mandat/paiement sans motif tracé | `padel × niveau_valider`, `padel × tournoi_gerer`, `padel × materiel_gerer`, `padel × acces_forcer`, `padel × lire` |
| **Coach** (rôle optionnel, réutilise un compte Utilisateur du socle) | Consulter son planning de cours, être rattaché comme ressource liée à une réservation « avec coach » | Modifier la grille tarifaire, gérer un tournoi | `padel × coach_lire_soi` |
| **Administrateur** | Paramétrer la **grille tarifaire pleine/creuse × membre/non-membre** par centre, paramétrer l'**échelle de niveau** utilisée, paramétrer les valeurs du **no-show/annulation tardive** (délai franc, montant, exonération membre) consommées par le moteur générique de réservation, configurer le **relais d'éclairage** par terrain (identifiant, mode de repli) | Modifier une écriture comptable déjà générée (M6) | `padel × parametrer`, `padel × configurer_eclairage` |
| **Système** | Calculer le tarif automatique (plage × statut) à la réservation, gérer la complétion d'une partie ouverte (2→3→4, maintien à 3), piloter l'allumage/extinction du relais d'éclairage sur la fenêtre réservée, propager le statut d'accès au module L3, appliquer le report automatique récurrence ↔ tournoi (générique réservation) | Décider hors des règles paramétrées (aucune dérogation automatique) | *(acteur technique — pas de permission humaine)* |

- ⚠ HYPOTHÈSE — Les noms de permissions `padel × …` ne sont **pas nommés littéralement** dans les
  sources (le cahier ne fournit pas de tableau « Acteurs & droits » pour la verticale padel, à la
  différence des modules M1-M8 et du module Accès) ; découpage dérivé du texte fonctionnel §1/§2 du
  panel `p-padel`, **à arbitrer avec M8** comme pour les autres verticales (même hypothèse que
  `spec-sport.md` §3).
- ⚠ HYPOTHÈSE — Le rôle « Coach » n'a **pas d'écran dédié** décrit par le cahier (le cahier mentionne
  seulement « dispo coach = ressource liée ») ; le comportement observable minimal retenu est qu'un
  coach est une **ressource réservable** au même titre qu'un terrain (créneau à ne pas chevaucher),
  sans permissions étendues au-delà de la consultation de son propre planning — **à confirmer**.

## 4. Comportements & règles
Chaque comportement trace une **RG-PADEL** (source : `cahier-detaille.html`, panel `p-padel`) et/ou une
**décision actée** ★ « 🎾 Padel » (source : `cahier-detaille.html`, panel `p-decisions`, qui **fait
foi** et n'est pas re-tranchée, constitution §6). Chaque règle est étiquetée **[RÉUTILISE SOCLE]**
(comportement déjà générique, le padel ne fait que le paramétrer/consommer) ou **[SPÉCIFIQUE PADEL]**
(comportement propre à cette verticale, non couvert ailleurs).

### 4.1 Terrain & réservation à l'heure (US-PADEL-01, RG-PADEL-01)
- **[RÉUTILISE SOCLE]** — **RG-PADEL-01** — Un **terrain** est une **ressource réservable par créneau**
  (60 ou 90 min) ; deux réservations ne peuvent **chevaucher** la même fenêtre sur le même terrain. Ce
  mécanisme (disponibilité, chevauchement, calendrier par ressource) est le **moteur générique de
  réservation de ressource** du socle (`specs/reservation/`) — le padel **instancie** ce moteur en
  déclarant le type de ressource « terrain de padel » avec deux **durées standard** (60/90 min) et un
  **calendrier multi-terrains** par établissement (cahier §2 « Réservation de terrain à l'heure »).
- **[SPÉCIFIQUE PADEL]** — Champs propres portés par le terrain : `type` (indoor/outdoor), `durées
  autorisées` (60 et/ou 90 min, paramétrable par terrain), `relaisÉclairage` (§4.8).
- **[SPÉCIFIQUE PADEL]** — Une réservation de terrain padel porte de **1 à 4 joueurs** (cahier §2) ; en
  dessous de 4, elle peut être déclarée **partie ouverte** pour se compléter (§4.3).

### 4.2 Tarification pleine/creuse × membre (US-PADEL-01, RG-PADEL-02)
- **[SPÉCIFIQUE PADEL, extension de M1]** — **RG-PADEL-02** — Le prix d'un créneau dépend de la
  **plage horaire** (heure pleine / heure creuse) **ET** du **statut** du réservant (membre /
  non-membre) ; la grille est **paramétrable par centre**.
- ⚠ HYPOTHÈSE — Le modèle générique `GrilleTarifaire` de M1 (`RG-M1-01`, `spec-offre.md` §5) résout un
  prix sur le triplet **produit × type de tarif × saison**, la « saison » étant une **plage de dates**
  (`dateDébut`/`dateFin`), pas une plage **horaire intrajournalière**. La distinction pleine/creuse
  padel n'a donc **pas d'axe équivalent** dans le modèle M1 actuel. La spec retient une **extension**
  minimale — une `PlageHoraire` (libellé, heure début/fin, jours applicables) jouant le rôle d'un
  **axe de tarification supplémentaire propre au padel**, combinée au **statut membre/non-membre**
  (dérivé de l'adhésion M1/M4 du joueur) — **à valider avec M1** avant implémentation (cf. §8, risque
  de duplication de modèle).
- **[SPÉCIFIQUE PADEL]** — Le tarif est **calculé automatiquement** à la réservation (plage horaire du
  créneau × statut du réservant), sans saisie manuelle (cahier §2).

### 4.3 Partie ouverte / matching de joueurs (US-PADEL-02/03, RG-PADEL-03, décision actée)
- **[SPÉCIFIQUE PADEL]** — **RG-PADEL-03** — Une **partie ouverte** se complète jusqu'à **4 joueurs
  maximum** ; le filtrage à l'inscription se fait par **niveau de jeu déclaré** (§4.4).
- **[SPÉCIFIQUE PADEL]** — Une réservation créée par un **organisateur** (1 ou 2 joueurs déjà inscrits)
  peut être marquée **ouverte**, avec un **niveau visé** (valeur ou fourchette) ; elle apparaît alors
  dans une liste de parties à rejoindre, filtrable par niveau, date, terrain.
- **[SPÉCIFIQUE PADEL]** — Chaque joueur qui rejoint une partie ouverte **paie sa part** à l'inscription
  (consomme le **paiement partagé** générique réservation, §4.10).
- **Décision actée — « Partie ouverte 3/4 »** ✱ *(diffère de la recommandation initiale)* — Une partie
  qui n'atteint **que 3 joueurs** au moment du créneau (le 4ᵉ n'a pas été trouvé ou s'est désisté) est
  **toujours maintenue à 3** : elle **n'est jamais annulée ni remboursée** pour ce seul motif, et le
  **surcoût** (part du 4ᵉ non pourvue) est **réparti entre les 3 joueurs présents**.
  - ⚠ HYPOTHÈSE — Le **mode de répartition** du surcoût (équitable entre les 3, ou uniquement à la
    charge de l'organisateur) n'est pas précisé par les sources ; la spec retient une **répartition
    équitable entre les 3 joueurs présents** (cohérent avec le principe du paiement partagé générique,
    « chaque part est encaissée individuellement »), **à confirmer**.
  - ⚠ HYPOTHÈSE — Le cas **en dessous de 3** (seuls 2 joueurs, voire 1, au moment du créneau) n'est pas
    couvert par la décision actée (qui ne tranche que le seuil « 3/4 ») ; la spec retient par défaut
    que la réservation **suit alors le régime générique de no-show/annulation** (§4.9) sur les places
    non pourvues, la partie restant jouable si ≥ 2 joueurs sont présents — **à confirmer avec le
    métier** (une partie à 2 reste un match valide en padel simple, mais l'usage visé ici est le double).

### 4.4 Niveau de jeu (US-PADEL-04, décision actée)
- **[SPÉCIFIQUE PADEL]** — Un joueur **déclare** un niveau (auto-évaluation, échelle paramétrable par
  établissement).
- **Décision actée — « Niveau de jeu »** ✱ *(diffère de la recommandation initiale, qui envisageait un
  niveau auto-déclaré ou dérivé des résultats de tournoi)* — Le niveau **effectif**, utilisé pour le
  **filtrage des parties ouvertes** (§4.3) et l'**inscription aux tournois** (§4.5), doit être
  **validé par le club** (rôle « Gestionnaire de club », §3). Un niveau **proposé mais non validé** ne
  peut pas être utilisé comme critère de filtrage/éligibilité — il reste visible comme « en attente de
  validation ».
- ⚠ HYPOTHÈSE — L'**échelle exacte** (numérique 1-10, catégories) n'est pas fixée par les sources ; la
  spec retient une échelle **numérique paramétrable par établissement** (borne min/max configurable),
  cohérente avec l'usage padel courant, **à confirmer**.
- ⚠ HYPOTHÈSE — Le **déclenchement** de la validation (le club valide-t-il à la demande du joueur, ou
  peut-il modifier un niveau à tout moment, ex. après observation en tournoi) n'est pas détaillé ; la
  spec retient que le **Gestionnaire de club** peut valider **ou ajuster** un niveau **à tout moment**,
  toute modification étant **tracée** (qui, quand, ancienne/nouvelle valeur — réutilise `RG-SOCLE-07`).

### 4.5 Tournois & ligues (US-PADEL-05/06/07)
- **[SPÉCIFIQUE PADEL]** — Un `Tournoi` porte un **format** (poules ou tableau à élimination), une
  **catégorie**, un **niveau requis**, des **frais d'inscription**, des **dates**, et un ensemble de
  **terrains bloqués** sur la période (cahier §2 « Tournois & ligues »).
  - L'inscription se fait **par paire** (2 joueurs), paiement des frais réutilisant M2/paiement partagé
    entre les 2 membres de la paire si souhaité.
  - Le format « poules » **génère automatiquement** les poules (répartition des paires inscrites) ;
    ⚠ HYPOTHÈSE — l'**algorithme de génération** (tirage aléatoire, tête de série par niveau) n'est pas
    précisé par les sources, retenu par défaut comme **aléatoire équilibré par niveau déclaré/validé**,
    **à confirmer**.
  - La **saisie des scores** met à jour un **classement automatique** (poules) ou fait avancer le
    **tableau** (élimination directe).
- **[RÉUTILISE SOCLE, configuré]** — Les **terrains bloqués** d'un tournoi sont des **réservations de
  ressource** créées par le système sur la période du tournoi, au même titre que toute autre
  réservation ; elles suivent le mécanisme générique de blocage/priorité du socle réservation.

### 4.6 Récurrence vs tournoi (US-PADEL-07, RG-PADEL-07, décision actée)
- **[RÉUTILISE SOCLE]** — **RG-PADEL-07** — Une **réservation récurrente** (créneau fixe hebdomadaire)
  est **prioritaire** sur les nouvelles demandes, sauf **blocage tournoi validé** qui la **suspend** sur
  la date concernée. Ce comportement de priorité/suspension est **générique au moteur de récurrence**
  du socle réservation, le padel ne fait que l'**activer** sur la ressource terrain.
- **Décision actée — « Récurrence vs tournoi »** — Quand un tournoi bloque un terrain occupé par une
  récurrence, la récurrence est **reportée automatiquement sur un autre terrain** disponible au même
  horaire ; **à défaut** de terrain disponible équivalent, une **validation manuelle** est demandée au
  Gestionnaire de club. Ce comportement (report automatique de la récurrence, validation manuelle en
  repli) est **générique** au moteur de récurrence du socle réservation (déjà acté comme tel dans le
  périmètre de cette mission) ; le padel n'en définit **que le déclencheur** (blocage tournoi).

### 4.7 Location de matériel (US-PADEL-08)
- **[SPÉCIFIQUE PADEL, s'appuie sur M1/M2]** — Une **raquette** ou un lot de **balles** est un
  **article catalogue** (produit M1, type « location », `spec-offre.md`), **rattaché à la réservation**
  du créneau au moment de l'ajout au panier (M2).
- Champs propres à la location : `type` de raquette, `quantité`, `caution` éventuelle, `statut de
  retour` (rendu / non rendu).
- ⚠ HYPOTHÈSE — Le **traitement d'une caution non restituée** (retenue, montant, délai) n'est pas
  détaillé par le cahier pour le padel ; par cohérence avec le traitement analogue en patinoire
  (« Patins non rendus/cassés → grille de retenue paramétrable + trace comptable en régie », décision
  actée patinoire, hors périmètre de cette spec), la spec retient le **même principe** — une **grille
  de retenue paramétrable** — **à confirmer** pour le padel spécifiquement.

### 4.8 Réservation avec/sans coach (US-PADEL-09)
- **[SPÉCIFIQUE PADEL]** — Une réservation de terrain peut être **avec coach** ou **sans coach** ; si
  avec coach, un **tarif majoré** s'applique (grille M1) et la **disponibilité du coach** est vérifiée
  comme une **ressource liée** — le créneau du coach ne doit **pas chevaucher** une autre réservation
  « avec coach » sur la même fenêtre (même mécanique de non-chevauchement que pour un terrain, §4.1).
- Champs propres : `coach`, `avecCoach` (bool), `nbJoueurs`, `durée`. Les cours/stages au catalogue
  relèvent de M1 (référencé, non redéfini).

### 4.9 Éclairage automatique du terrain (US-PADEL-10, RG-PADEL-05)
- **[SPÉCIFIQUE PADEL — capacité nouvelle, non couverte par L3]** — **RG-PADEL-05** — L'**éclairage**
  et l'**accès badge** du terrain s'activent **uniquement sur la fenêtre réservée** (début → fin), y
  compris une **tolérance d'entrée paramétrable**.
  - Le volet **accès badge** réutilise le mécanisme générique de `DroitAccès` et de fenêtre de validité
    du module L3 (`spec-acces.md` §4.3/§5, `RG-ACC-01`) — le padel ne le redéfinit pas, il **projette**
    la réservation de terrain en `DroitAccès` sur la fenêtre réservée.
  - Le volet **éclairage** est en revanche une **capacité nouvelle** : le module L3 tel que spécifié
    pilote des **lecteurs/tourniquets**, pas des **actionneurs d'éclairage**. La verticale padel
    introduit donc un **relais d'éclairage** (1:1 avec un terrain), piloté par le **début/fin de la
    fenêtre réservée** : allumage à l'heure de début, extinction à l'heure de fin.
  - **Repli manuel** — En cas de **défaut du relais** (panne, absence de réponse), un **repli manuel**
    est proposé au Gestionnaire de club (commande manuelle depuis l'écran de supervision), et
    l'incident est **tracé** (`EvenementEclairage.statut = échec_repli_manuel`).
  - ⚠ HYPOTHÈSE — Le **protocole/API du relais d'éclairage** (domotique filaire, IoT, contact sec piloté
    par automate) n'est pas précisé par les sources ; hors périmètre fonctionnel de cette spec
    (comportement observable = allumage/extinction synchronisés à la fenêtre réservée, avec repli
    manuel tracé si échec), à cadrer au plan technique.

### 4.10 No-show / annulation tardive — configuration padel (US-PADEL-01, RG-PADEL-06, décision actée)
- **[RÉUTILISE SOCLE — configuration seulement]** — **RG-PADEL-06** — Un **no-show** ou une
  **annulation tardive** peut être **facturé** selon une règle **paramétrable** (délai franc,
  montant/%, exonération membre). **Décision actée** : « Délai franc paramétrable (ex. 24 h) →
  facturation ; en deçà exonéré. » Ce comportement (détection du no-show, déclenchement de la
  facturation, trace, avoir éventuel) est **entièrement générique** au moteur de réservation socle
  (`specs/reservation/`, cf. §2 « Exclu »). Le padel se limite à **fournir les valeurs de paramètres**
  suivantes, propres à l'établissement padel : `délaiFranc` (ex. 24 h), `montantOuPourcentage`,
  `exonérationMembre` (bool).
- ⚠ Cette règle **ne redéfinit pas** le comportement d'annulation/no-show — voir `spec-reservation.md`
  (à venir) pour le détail du déclenchement et de la trace comptable.

### 4.11 Paiement partagé — usage padel (US-PADEL-02/03, RG-PADEL-04, décision actée)
- **[RÉUTILISE SOCLE]** — **RG-PADEL-04** — Le **paiement partagé** répartit le montant du créneau
  entre les joueurs présents ; chaque part est **encaissée individuellement** et **tracée** sur la
  réservation. **Décision actée — « Paiement partagé défaillant »** : la réservation reste
  **confirmée** même si un joueur ne règle pas sa part, et l'**organisateur** de la réservation est
  **solidaire** du reste à payer. Ce mécanisme est **entièrement générique** au moteur de réservation
  socle ; le padel **consomme** ce comportement pour répartir le prix d'un créneau entre 1 à 4 joueurs
  (y compris le cas « partie maintenue à 3 » de §4.3, où le surcoût du 4ᵉ manquant est réparti entre
  les 3 présents — **extension padel de la répartition standard**, cf. §4.3).

## 5. Objets de données
Les types PHP sont indicatifs (spec = comportement observable). Tout objet est rattaché à un
**Établissement** via le socle (`RG-SOCLE-01`) ; identifiants = **UUID** (constitution §3). Les objets
socle référencés (Réservation/CréneauRessource/RèglePaiementPartagé/RègleNoShow du moteur générique
`specs/reservation/`, Produit/Formule/GrilleTarifaire M1, Panier/Paiement M2, Client/Beneficiaire M4,
DroitAccès/Support L3) sont **référencés, non redéfinis** — ⚠ le moteur `specs/reservation/` n'existe
pas encore dans ce dépôt à ce jour ; les champs ci-dessous supposent un contrat minimal (`ressource`,
`fenêtre début/fin`, `statut`, `participants`, `paiementsPartagés[]`, `règleNoShow`) qui **devra être
réconcilié** avec la spec réservation une fois écrite (cf. §8).

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| **Terrain** | id | uuid | PK | ressource réservable, RG-PADEL-01 |
| | nom, type | string, enum {indoor, outdoor} | requis | cahier §4 |
| | duréesAutorisées | set {60, 90} (min) | ≥ 1 | paramétrable par terrain |
| | relaisEclairage | ref RelaisEclairageTerrain? | optionnel | §4.9 |
| | actif | bool | défaut = true | — |
| **PlageHoraire** (⚠ extension M1, §4.2) | id, établissement | uuid, ref Etablissement | requis | axe de tarification padel |
| | libellé | enum {pleine, creuse} | requis | RG-PADEL-02 |
| | heureDébut, heureFin | heure, heure | début < fin | par jour de semaine |
| | joursApplicables | set {lun..dim} | ≥ 1 | — |
| **GrilleTarifaireTerrain** | (terrain\|type, plageHoraire, statutJoueur) | tuple | clé | RG-PADEL-02 |
| | statutJoueur | enum {membre, non_membre} | requis | dérivé de l'adhésion M1/M4 |
| | prix | decimal ≥ 0 | requis | par durée (60/90) |
| **Réservation** (ref, socle réservation — non redéfini) | ressource | ref Terrain | requis | instancie le moteur générique |
| | fenêtre {début, fin} | datetime, datetime | fin > début | RG-PADEL-01 |
| | joueurs[] | ref Client (M4) | 1..4 | cahier §2 |
| | statut | enum {confirmée, ouverte, maintenue_à_3, annulée, no_show} | requis | §4.3/§4.10 |
| | avecCoach | bool | défaut = false | §4.8 |
| **PartieOuverte** | id, réservation | uuid, ref Réservation | 1:1 | RG-PADEL-03 |
| | niveauVisé | {min, max} ou valeur | optionnel | filtrage §4.3 |
| | placesRestantes | int, 0..3 | dérivé | — |
| | statut | enum {ouverte, complète, maintenue_à_3} | requis | décision actée §4.3 |
| **InscriptionPartie** | id, partie | uuid, ref PartieOuverte | requis | — |
| | joueur | ref Client (M4) | requis | — |
| | part montant | decimal > 0 | dérivé | paiement partagé (§4.11) |
| **NiveauJoueur** | id, joueur | uuid, ref Client (M4) | 1:1 | US-PADEL-04 |
| | niveau | numérique (échelle paramétrable) | requis | ⚠ échelle non fixée |
| | statut | enum {proposé, validé} | défaut = proposé | décision actée §4.4 |
| | validéPar, dateValidation | ref Utilisateur?, datetime? | requis si `validé` | §4.4 |
| | historique[] | append-only (ancienne/nouvelle valeur, auteur, date) | — | traçabilité (`RG-SOCLE-07`) |
| **Tournoi** | id, nom | uuid, string | PK | cahier §4 |
| | format | enum {poules, tableau} | requis | US-PADEL-05 |
| | catégorie, niveauRequis | string, référence NiveauJoueur | optionnel | — |
| | fraisInscription | decimal ≥ 0 | requis | par paire |
| | dateDébut, dateFin | date, date | fin ≥ début | — |
| | terrainsBloqués[] | ref Réservation[] (créées par le système) | — | §4.5 |
| | statut | enum {ouvert_inscriptions, en_cours, terminé} | requis | — |
| **InscriptionTournoi** (Paire) | id, tournoi | uuid, ref Tournoi | requis | — |
| | joueur1, joueur2 | ref Client (M4) | requis | inscription par paire |
| | statutPaiement | enum {payé, en_attente} | requis | frais (§4.5) |
| **MatchTournoi** | id, tournoi | uuid, ref Tournoi | requis | poule ou tableau |
| | paireA, paireB | ref InscriptionTournoi | requis | — |
| | terrain, créneau | ref Terrain, ref Réservation | requis | terrain bloqué |
| | score, vainqueur | string, ref InscriptionTournoi? | saisi après match | US-PADEL-06 |
| **ClassementTournoi** | tournoi | ref Tournoi | dérivé | calculé des `MatchTournoi` |
| **LocationMateriel** | id, réservation | uuid, ref Réservation | requis | US-PADEL-08 |
| | article | ref Produit (M1, type location) | requis | raquette/balles |
| | quantité | int > 0 | requis | — |
| | caution | decimal ≥ 0 | optionnel | ⚠ grille de retenue à confirmer |
| | statutRetour | enum {en_cours, rendu, non_rendu} | défaut = en_cours | §4.7 |
| **RelaisEclairageTerrain** | id, terrain | uuid, ref Terrain | 1:1 | §4.9 |
| | identifiantRelais | string | requis | ⚠ protocole non précisé |
| | modeRepli | enum {manuel} | requis | §4.9 |
| | statut | enum {opérationnel, en_défaut} | défaut = opérationnel | — |
| **EvenementEclairage** | id, terrain, réservation | uuid, ref Terrain, ref Réservation | requis | traçabilité §4.9 |
| | action | enum {allumage, extinction} | requis | — |
| | horodatage | datetime | requis | — |
| | statut | enum {ok, échec_repli_manuel} | requis | §4.9 |

## 6. Critères d'acceptation
- **CA-1 (US-PADEL-01, RG-PADEL-01/02)** — *Étant donné* un terrain disponible, *quand* un joueur
  réserve un créneau de 90 min en heure pleine et n'est pas membre, *alors* la réservation est créée
  sur le moteur générique (aucun chevauchement possible sur ce terrain/créneau) et le **tarif** appliqué
  correspond automatiquement à la combinaison **plage horaire pleine × non-membre** de la grille du
  centre, sans saisie manuelle.
- **CA-2 (US-PADEL-01, RG-PADEL-01)** — *Étant donné* un terrain déjà réservé sur 19h-20h30, *quand* un
  autre joueur tente de réserver ce même terrain sur une fenêtre chevauchante, *alors* la réservation est
  **refusée** (chevauchement, comportement générique du moteur de réservation).
- **CA-3 (US-PADEL-02, RG-PADEL-03)** — *Étant donné* une réservation à 2 joueurs marquée **ouverte**
  avec un niveau visé, *quand* un troisième puis un quatrième joueur dont le niveau **validé**
  correspond au filtre rejoignent la partie, *alors* la partie passe **complète (4/4)**, chaque joueur
  a payé sa part, et une notification de complétion est envoyée aux 4 joueurs.
- **CA-4 (US-PADEL-03, décision actée « Partie ouverte 3/4 »)** — *Étant donné* une partie ouverte à
  l'heure du créneau avec seulement **3 joueurs** présents (4ᵉ non trouvé), *alors* la partie est
  **maintenue** (statut `maintenue_à_3`, **jamais annulée pour ce seul motif**), et le **surcoût**
  correspondant à la part du 4ᵉ manquant est **réparti entre les 3 joueurs présents** et encaissé.
- **CA-5 (US-PADEL-04, décision actée « Niveau de jeu »)** — *Étant donné* un joueur ayant déclaré un
  niveau `proposé`, *quand* il tente de rejoindre une partie ouverte filtrée par niveau, *alors* son
  niveau **non validé n'est pas éligible** au filtrage ; *quand* un Gestionnaire de club **valide** ce
  niveau, *alors* le statut passe à `validé`, l'action est **tracée** (auteur, date, ancienne/nouvelle
  valeur), et le joueur devient éligible aux parties/tournois filtrés sur ce niveau.
- **CA-6 (US-PADEL-05, cahier §2)** — *Étant donné* un tournoi en format « poules » avec des paires
  inscrites et payées, *quand* le Gestionnaire de club lance la génération, *alors* les **poules sont
  créées automatiquement**, et les **terrains nécessaires sont bloqués** (réservations système) sur les
  créneaux du tournoi.
- **CA-7 (US-PADEL-06)** — *Étant donné* un match de tournoi joué, *quand* le score est saisi, *alors*
  le **vainqueur** est déterminé, le **classement** (poules) ou l'**avancement** (tableau) est
  **recalculé automatiquement** et visible sans action supplémentaire.
- **CA-8 (US-PADEL-07, décision actée « Récurrence vs tournoi »)** — *Étant donné* une réservation
  récurrente hebdomadaire sur un terrain que le club vient de bloquer pour un tournoi validé, *quand*
  le blocage est confirmé, *alors* le système **reporte automatiquement** l'occurrence de la récurrence
  concernée sur un **autre terrain disponible** au même horaire ; *quand* aucun terrain équivalent n'est
  disponible, *alors* une **demande de validation manuelle** est adressée au Gestionnaire de club et
  l'occurrence reste **en attente** jusqu'à décision.
- **CA-9 (US-PADEL-08, RG-PADEL-04)** — *Étant donné* une réservation de terrain, *quand* un joueur
  ajoute la location de 2 raquettes au panier, *alors* l'article est **rattaché à la réservation**, une
  **caution** est appliquée si paramétrée, et le statut de retour est suivi (`en_cours` → `rendu` /
  `non_rendu`) après le créneau.
- **CA-10 (US-PADEL-09)** — *Étant donné* une réservation « avec coach » sur un créneau donné, *quand*
  le coach est déjà engagé sur une autre réservation « avec coach » chevauchante, *alors* la réservation
  est **refusée** (non-chevauchement de la ressource coach) ; sinon elle est acceptée avec un **tarif
  majoré**.
- **CA-11 (US-PADEL-10, RG-PADEL-05)** — *Étant donné* un terrain équipé d'un relais d'éclairage et une
  réservation confirmée 19h-20h30, *quand* l'heure de début arrive, *alors* le relais **allume**
  automatiquement l'éclairage (`EvenementEclairage.action = allumage, statut = ok`) ; *quand* l'heure de
  fin arrive, *alors* le relais **éteint** automatiquement ; *quand* le relais est **en défaut**, *alors*
  un **repli manuel** est proposé au Gestionnaire de club et l'incident est tracé
  (`statut = échec_repli_manuel`).
- **CA-12 (RG-PADEL-05, accès)** — *Étant donné* la même réservation, *alors* le **badge du joueur** est
  valide **uniquement sur la fenêtre réservée** (+ tolérance d'entrée paramétrable), réutilisant le
  mécanisme générique de `DroitAccès` du module L3 (`spec-acces.md` §4.3), sans redéfinition ici.
- **CA-13 (RG-PADEL-06, décision actée, configuration)** — *Étant donné* un centre paramétré avec un
  **délai franc de 24 h** et une **exonération membre**, *quand* un non-membre annule à 12 h du
  créneau, *alors* le no-show/annulation tardive **générique du socle réservation** est déclenché avec
  ces valeurs (facturation) ; *quand* c'est un **membre exonéré** qui annule dans les mêmes conditions,
  *alors* **aucune facturation** n'est déclenchée — le comportement de détection/facturation lui-même
  n'est **pas** défini par cette spec (cf. `spec-reservation.md`).
- **CA-14 (RG-PADEL-04, décision actée « Paiement partagé défaillant », configuration)** — *Étant
  donné* une réservation à 4 joueurs dont l'un ne règle pas sa part avant le créneau, *alors* la
  réservation reste **confirmée** (mécanisme générique) et l'**organisateur** de la réservation est
  **solidaire** du reste à payer — comportement hérité du socle réservation, seul l'usage (répartition
  du prix du créneau padel) est propre à cette verticale.

## 7. Cas limites
- **Grille tarifaire pleine/creuse sans axe équivalent dans M1** — Le modèle `GrilleTarifaire` générique
  (produit × type de tarif × **saison** = plage de dates) ne porte **pas nativement** de plage
  **horaire** ; ⚠ HYPOTHÈSE : la spec introduit `PlageHoraire` comme axe **propre au padel** (§4.2),
  à réconcilier avec M1 avant implémentation — **principal risque de duplication de modèle** de cette
  spec (cf. §8).
- **Partie ouverte qui ne dépasse jamais 2 joueurs** — Non couvert par la décision actée (qui ne tranche
  que le seuil 3/4) ; ⚠ HYPOTHÈSE : suit le régime générique de no-show sur les places non pourvues,
  la partie restant jouable si ≥ 2 joueurs (§4.3) — à confirmer avec le métier.
- **Niveau contesté par un joueur après validation** — Aucune procédure d'appel/contestation décrite par
  les sources ; ⚠ HYPOTHÈSE : traité comme une simple **ré-validation** par un rôle habilité (pas de
  workflow de contestation formalisé dans ce lot), à l'image du traitement retenu pour le motif légitime
  de résiliation en `spec-sport.md` §7.
- **Tournoi annulé après blocage de terrains récurrents déjà reportés** — ⚠ HYPOTHÈSE : les sources ne
  précisent pas si un report de récurrence est **automatiquement annulé** en retour si le tournoi qui l'a
  déclenché est lui-même annulé (le terrain d'origine redevient disponible) ; retenu par défaut que la
  récurrence **reste** sur le terrain de report jusqu'à intervention manuelle (pas de « retour arrière »
  automatique), à confirmer.
- **Coach indisponible (maladie, absence)** — ⚠ HYPOTHÈSE : les sources ne détaillent pas de comportement
  spécifique padel (à la différence de M5 « Encadrant absent → remplacement automatique, à défaut
  annulation + notification », décision actée générique planning) ; la spec retient par défaut que ce
  cas **réutilise** ce mécanisme générique planning (remplacement proposé, sinon annulation notifiée),
  à confirmer que le coach padel est bien modélisé comme un « encadrant » au sens M5.
- **Caution matériel non restituée** — ⚠ HYPOTHÈSE : traitement (grille de retenue paramétrable, trace
  comptable) retenu par analogie avec la patinoire, non explicitement décrit pour le padel (§4.7).
- **Relais d'éclairage définitivement hors service** — Le repli manuel reste disponible indéfiniment
  (§4.9) ; aucune procédure de désactivation automatique du terrain n'est décrite par les sources —
  ⚠ HYPOTHÈSE : le terrain reste réservable (l'éclairage n'est pas une condition de réservabilité), seul
  le confort est dégradé, à confirmer.
- **Deux organisateurs successifs sur une même partie ouverte transférée** — Non traité par les sources
  (ex. l'organisateur initial annule sa propre inscription mais la partie reste ouverte pour les autres) ;
  hors périmètre de cette spec, hérite du comportement générique de gestion de participants du socle
  réservation.

## 8. Dépendances
- **Dépend de : socle réservation de ressource** (`specs/reservation/spec-reservation.md`) — ⚠ **cette
  spec n'existe pas encore dans ce dépôt à la date de rédaction** (en cours de rédaction en parallèle,
  cf. mission). Le padel **dépend intégralement** de ce moteur pour : la réservation d'un terrain comme
  ressource (créneau, chevauchement, calendrier), le **no-show/annulation tardive** (§4.10), le
  **paiement partagé** (§4.11) et la **récurrence** (§4.6). Cette spec padel **anticipe** un contrat
  minimal (ressource, fenêtre, statut, participants, paiements partagés, règle no-show) qui **devra être
  réconcilié** avec `spec-reservation.md` une fois publiée — **c'est le principal risque de cette spec**
  (cf. récapitulatif final, point 1).
- **Dépend de : socle L0** (`specs/L0-socle/spec-socle.md`) — hiérarchie Groupe/Région/Établissement/
  Espace (`RG-SOCLE-01`) à laquelle se rattachent `PlageHoraire`, `GrilleTarifaireTerrain` et
  `RelaisEclairageTerrain` (par établissement/terrain) ; permissions `module × action` réutilisées sur
  le module **`padel`** (`RG-SOCLE-02/03/04`) ; journal d'audit append-only (`RG-SOCLE-07`) sur lequel
  s'appuient la traçabilité du niveau de jeu, des tournois, des locations et des événements d'éclairage.
- **Dépend de : M1 · Offre & Tarification** (L1, `specs/L1-offre/spec-offre.md`) — la `Réservation`
  terrain consomme une **grille tarifaire** dérivée du modèle `Produit`/`GrilleTarifaire` (`RG-M1-01`) ;
  ⚠ voir §4.2/§7 — l'axe **plage horaire intrajournalière** n'existe pas nativement dans M1, la spec
  padel introduit `PlageHoraire` comme **extension à valider**. Le statut **membre/non-membre** dérive
  d'une **Formule d'adhésion** M1 (`RG-M1-03`). La **location de matériel** (§4.7) instancie un
  `Produit` de type location M1 sans le redéfinir.
- **Dépend de : M2 · Vente & Caisse** (L2, `specs/L2-vente/spec-vente.md`) — l'encaissement des parts de
  créneau (paiement partagé, §4.11), des locations de matériel et des frais de tournoi passe par le
  **panier/paiement** générique M2 (`RG-M2-02/03/04`), non redéfini ici.
- **Dépend de : M4 · CRM** (`specs/L5-crm/spec-crm.md`) — le joueur est un `Client`/`Beneficiaire`
  existant (`RG-M4-02`) ; le **niveau de jeu** (§4.4) et l'historique de parties sont des données
  **propres au padel** rattachées à ce compte, sans dupliquer la fiche client.
- **Dépend de : L3 · Contrôle d'accès** (`specs/L3-acces/spec-acces.md`) — le badge terrain **projette**
  la fenêtre de réservation sur un `DroitAccès` (RG-ACC-01), réutilisant intégralement le mécanisme
  générique de validation/fenêtre de L3 ; le padel **ne redéfinit pas** ce mécanisme. Le **relais
  d'éclairage** (§4.9) est en revanche une **capacité nouvelle**, non couverte par L3 (qui pilote des
  lecteurs/tourniquets, pas des actionneurs) — extension propre au padel, à spécifier techniquement au
  plan (protocole relais) hors périmètre fonctionnel de cette spec.
- **Référencé, non redéfini :** M5 Planning & Réservation pour l'analogie « encadrant absent » du coach
  (§7, cas limite) — module non encore spécifié dans ce dépôt (même point ouvert que `spec-piscine.md`
  et `spec-sport.md`) ; M6 Compta & Régie pour la trace comptable des cautions matériel et des
  facturations no-show (référencé, non détaillé ici).

---

## Points ouverts / hypothèses (récapitulatif)

### Absence de source officielle (à faire trancher/valider par le produit)
1. **US-PADEL-01 à 10 sont des stories définies par cet agent** — aucune US dédiée « padel » n'existe
   dans `backlog.html` (verticale V2 non encore backloguée, comme Sport et Musée). À faire **valider,
   renuméroter et chiffrer** officiellement avant tout développement (§0 en-tête).
2. **`specs/reservation/spec-reservation.md` n'existe pas encore dans ce dépôt** — cette spec padel
   **dépend intégralement** du moteur générique de réservation de ressource (créneau, no-show, paiement
   partagé, récurrence) qu'elle ne fait qu'instancier/configurer. Le contrat anticipé (§5, §8) devra être
   **réconcilié** dès que cette spec socle sera rédigée — **c'est le risque le plus important** de cette
   spec.
3. **La grille tarifaire pleine/creuse × membre n'a pas d'axe équivalent dans le modèle M1 actuel**
   (produit × type de tarif × **saison de dates**, pas de plage **horaire**) — extension `PlageHoraire`
   proposée par cette spec (§4.2), à valider avec M1 avant implémentation.

### ⚠ HYPOTHÈSE fonctionnelle (comportement retenu par défaut, à confirmer)
4. **Répartition du surcoût « partie maintenue à 3 »** — retenue **équitable entre les 3 joueurs
   présents**, non précisée par la décision actée (§4.3).
5. **Partie ouverte qui ne dépasse jamais 2 joueurs** — non couverte par la décision actée (qui ne
   tranche que 3/4) ; retenue comme suivant le régime générique de no-show, partie jouable si ≥ 2
   joueurs (§4.3, §7).
6. **Échelle du niveau de jeu** — retenue numérique paramétrable par établissement, non fixée par les
   sources (§4.4).
7. **Déclenchement de la validation du niveau par le club** — retenu comme possible à tout moment par le
   Gestionnaire de club, tracé, non détaillé par les sources (§4.4).
8. **Algorithme de génération des poules** — retenu aléatoire équilibré par niveau, non précisé par les
   sources (§4.5).
9. **Traitement d'une caution matériel non restituée** — retenu par analogie avec la patinoire (grille de
   retenue paramétrable), non explicitement décrit pour le padel (§4.7, §7).
10. **Protocole/API du relais d'éclairage** — non précisé, hors périmètre fonctionnel, à cadrer au plan
    technique (§4.9).
11. **Rôle et permissions du Coach** — pas d'écran dédié décrit par le cahier ; retenu comme ressource
    réservable avec consultation de son propre planning uniquement (§3).
12. **Comportement en cas de coach indisponible** — retenu par analogie avec la règle générique
    « encadrant absent » de M5 (remplacement automatique, sinon annulation notifiée), non confirmé pour
    le padel (§7).
13. **Retour arrière d'un report de récurrence** si le tournoi qui l'a déclenché est annulé — non
    tranché par les sources, retenu par défaut sans retour automatique (§7).
