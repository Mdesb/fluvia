# Spec — Réservation & no-show générique (`M5` · capacité socle, module `reservation`)

- **Lot / module :** Socle **M5 Planning & Réservation** — capacité **générique**, transverse aux
  verticales (padel, patinoire, lignes d'eau piscine, musée, cours collectifs, salles, tables…).
  Ce n'est **pas** une verticale : les verticales **configurent** cette capacité (type de ressource,
  durée de créneau, règle de no-show, lien accès), elles ne la **redéfinissent** pas.
- **Stories couvertes :** **US-RES-01 à US-RES-14** — ⚠ **HYPOTHÈSE** : **M5 est hors ordre de
  construction MVP** du `backlog.html` (constitution §5 : L0→M1→M2→accès→M6→M4→Piscine→L7 ; M5 n'a
  **aucun lot dédié** et `backlog.html` ne contient **aucune** story `US-L*` portant sur le planning
  générique — seules `US-L6-03` et `US-L6-04/05/06` de la verticale Piscine **consomment** la notion
  de réservation/créneau sans la définir). Ces **US-RES sont donc définies par cet agent**, à partir
  du panel `p-m5` du cahier détaillé (règles `RG-M5-01` à `08`), du panel `p-padel` (`RG-PADEL-01` à
  `07` + décisions actées 🎾), du panel `p-musee` (décisions actées 🏛️) et des décisions transverses
  demandées explicitement par le commanditaire (no-show à délai franc, récurrence + report auto,
  paiement partagé, liste d'attente). **À faire valider et numéroter officiellement** dans le backlog
  avant développement.
- **Règles de gestion :** `RG-M5-01` à `RG-M5-08` (source `cahier-detaille.html`, panel `p-m5`,
  **généralisées** ici — « séance » → « créneau », « encadrant/espace » → « ressource ») + `RG-M5-09` à
  `RG-M5-12` (**nouvelles**, socle, **généralisant** des règles/décisions aujourd'hui déclarées
  seulement au niveau d'une verticale : `RG-PADEL-04/05/06/07`, décisions actées 🎾 Padel « Annulation
  tardive/no-show », « Paiement partagé défaillant », « Récurrence vs tournoi », et décision actée
  🏛️ Musée « Sur-vente OTA / récupération quota no-show »). Règles socle réutilisées : `RG-SOCLE-01`
  à `07` (`spec-socle.md`), `RG-M1-03/12` (`spec-offre.md`), `RG-M2-02/03/07` (`spec-vente.md`),
  `RG-M4-02/03` (`spec-crm.md`), `RG-ACC-01/02/05/06` (`spec-acces.md`).
- **Statut :** brouillon — ⚠ point d'architecture majeur non tranché : le **mécanisme de facturation
  d'un no-show sans encaissement préalable** (cf. §8 et récapitulatif final) doit être arbitré avec
  M2/M6 avant implémentation.

## 1. Objectif
Permettre à n'importe quel équipement de proposer une **ressource réservable par créneau** (terrain,
glace, ligne d'eau, salle, court, table, guide…), d'en gérer la **jauge**, la **liste d'attente**, la
**récurrence** et le **paiement** (plein ou partagé entre participants) — et de faire respecter, de
façon **générique et paramétrable**, la règle qui protège l'exploitant : au-delà d'un **délai franc**
avant le créneau, une annulation tardive ou une absence (**no-show**) est **facturée** ; en-deçà, elle
est **exonérée**. Les verticales (padel, patinoire, piscine, musée, cours…) ne font que **paramétrer**
cette capacité (type de ressource, durée, règle de facturation), sans réimplémenter le moteur.

## 2. Périmètre
- **Inclus :**
  - **Ressource réservable** de **type paramétrable** (terrain, glace, ligne d'eau, salle, court,
    table, guide/encadrant…), sa **capacité**, ses **disponibilités**, son caractère **partageable**
    (ressource mère / sous-ressources) — US-RES-01, RG-M5-03/05/08.
  - **Créneau** : occurrence réservable d'une ressource, capacité propre, statut, éventuel **public
    réservé** — US-RES-01/10, RG-M5-03/08.
  - **Réservation** : cycle de vie complet (confirmée, liste d'attente, annulée libre, annulée tardive
    facturée, no-show facturé, honorée), quota/jauge par créneau, décompte du **quota inclus** d'une
    formule (M1) ou bascule en **vente à l'unité** (M2) — US-RES-02/03, RG-M5-01/02.
  - **Liste d'attente** avec **promotion automatique** au désistement — US-RES-04, RG-M5-06.
  - **Récurrence** (motif hebdo/quotidien + fin, exceptions par occurrence) et **report automatique**
    sur une autre ressource en cas de conflit, sinon **validation manuelle** — US-RES-05/06,
    RG-M5-07/11.
  - **No-show / annulation tardive facturée** : **délai franc paramétrable**, au-delà exonéré, en deçà
    facturé (montant fixe ou %, exonérations paramétrables) — US-RES-07/08/13/14, RG-M5-09.
  - **Paiement partagé** entre plusieurs participants, **organisateur solidaire** du reste à payer —
    US-RES-09, RG-M5-10.
  - **Lien optionnel** entre une réservation et un **droit d'accès** (badge ouvert sur la fenêtre du
    créneau) — US-RES-12, RG-M5-12.
  - Écran calendrier (jour/semaine/liste), fiche ressource/créneau, écran de réservation
    guichet + en ligne, liste des inscrits/émargement — repris de `cahier-detaille.html` §3 (M5-01 à
    M5-05), généralisés.
- **Exclu (pour l'instant), que ce module *référence* seulement :**
  - Le **catalogue produit, la grille tarifaire, les formules et leur quota inclus** (`ServiceInclus`,
    `RG-M1-03/12`) → **M1** (`spec-offre.md`). Ce module **consomme** le tarif de référence et le
    quota, ne les redéfinit pas.
  - **L'encaissement de la vente à l'unité et la facturation d'un no-show** (moyens de paiement,
    session de caisse, ticket, avoir, contre-passation NF525) → **M2** (`spec-vente.md`). Ce module
    **déclenche** une vente/un avoir vers M2, ne réimplémente pas la caisse. ⚠ voir §8/§ récapitulatif
    — l'**exécution** d'un encaissement sans agent présent (no-show à distance) n'est **couverte par
    aucune spec de ce dépôt** à ce jour.
  - Les **écritures comptables**, la **PCA** (compte 487) → **M6** (`spec-compta.md`). Ce module
    **alimente** M6 via les ventes/avoirs générés par un no-show ou une consommation.
  - La **fiche client/famille, le porte-monnaie virtuel (PMV), bénéficiaire ≠ payeur** → **M4/CRM**
    (`spec-crm.md`). Ce module **consomme** le bénéficiaire/organisateur et son payeur.
  - Le **contrôle d'accès physique** (validation au tourniquet, badge, jauge FMI, hors-ligne) →
    **Accès** (`spec-acces.md`). Ce module **projette** un droit d'accès optionnel sur la fenêtre du
    créneau, ne redéfinit pas le mécanisme de validation.
  - Les **compétences métier fines d'une ressource humaine** (diplôme MNS/BNSSA, planning RH, paie) —
    traitées comme un **attribut générique** (`compétenceRequise`) de la Ressource ; le détail
    métier/réglementaire (validité diplôme, remplacement automatique d'un encadrant absent) relève de
    la **verticale** qui l'instancie (ex. piscine) et n'est **pas redéfini** ici au-delà de l'attribut.
  - Les **spécificités métier des verticales** (matching de joueurs padel, tournois/poules, location de
    matériel, éclairage automatique, timed-entry musée, casiers/cautions piscine) : cette spec ne
    modélise que le **socle** ; chaque verticale garde sa propre spec pour ses écrans/objets propres.
  - L'**UI (front)** ; l'**authentification, les rôles/permissions et le journal d'audit** → **socle
    L0** (`spec-socle.md`), réutilisés et non redéfinis ici.

## 3. Acteurs & droits
Les permissions réutilisent le modèle du socle (`RG-SOCLE-02/03/04/05`) : couple `module × action` sur
le module **`reservation`**, portées par l'**établissement actif** (ou l'**espace**, pour une ressource
qui y est rattachée) ; l'UI **masque** ce qui n'est pas autorisé (`RG-SOCLE-04`). Source : cahier `p-m5`
§2 (tableau générique repris) + `p-padel` §2 (organisateur/participants, non nommé explicitement dans
le cahier comme un rôle système — dérivé du texte fonctionnel).

| Acteur | Peut | Ne peut pas | Permission (module × action) |
|---|---|---|---|
| **Gestionnaire de planning** | Créer/modifier des ressources et des créneaux (unitaires ou récurrents), définir la récurrence et ses exceptions, paramétrer les règles d'annulation/no-show, ouvrir/fermer les inscriptions, gérer le public réservé d'un créneau | Encaisser une vente, modifier une grille tarifaire (M1) | `reservation × gerer_ressource`, `reservation × gerer_creneau`, `reservation × parametrer_annulation`, `reservation × lire` |
| **Opérateur de ressource** *(encadrant, coach, guide…)* | Consulter ses créneaux, ouvrir la liste des inscrits, émarger présents/absents, saisir un compte-rendu | Créer un créneau, réaffecter une ressource, annuler une réservation payée | `reservation × lire`, `reservation × emarger` |
| **Agent d'accueil / guichet** | Réserver une place au guichet pour un bénéficiaire, inscrire en liste d'attente, annuler dans/hors délai franc (avec déclenchement facturation), déclencher la vente à l'unité vers M2 | Créer/supprimer une ressource ou un créneau, changer une capacité, exonérer un no-show sans droit dédié | `reservation × reserver`, `reservation × annuler`, `reservation × lire` |
| **Client / Organisateur (en ligne ou app)** | Réserver une place pour lui-même et, s'il est organisateur, pour d'autres participants (paiement partagé), s'inscrire en liste d'attente, annuler **dans le délai franc**, consulter son quota restant et le statut de sa réservation | Voir les réservations d'autres clients, réserver un créneau complet/fermé, annuler **hors délai** sans être facturé, forcer une exonération | `reservation × reserver_soi`, `reservation × annuler_soi`, `reservation × lire_soi` |
| **Responsable / Administrateur** | Exonérer un no-show au cas par cas (motif tracé), forcer une réouverture de créneau, arbitrer un conflit de récurrence en validation manuelle | Contourner la traçabilité d'une exonération (toujours journalisée, `RG-SOCLE-07`) | `reservation × exonerer`, `reservation × forcer`, `reservation × arbitrer_recurrence` |
| **Système** | Bloquer un conflit de ressource à la création, promouvoir automatiquement la liste d'attente, faire basculer une réservation en no-show à l'issue du créneau, déclencher la vente/l'avoir de facturation, reporter automatiquement une récurrence en conflit selon la règle configurée | Décider hors des règles paramétrées (aucune dérogation automatique) | *(acteur technique — pas de permission humaine)* |

- ⚠ HYPOTHÈSE — Les noms de permissions `reservation × …` ne sont **pas nommés littéralement** dans les
  sources (le cahier fournit un tableau Acteurs & droits pour M5-piscine mais pas pour la capacité
  générique elle-même) ; découpage dérivé de `p-m5` §2 et généralisé, **à arbitrer avec M8**.
- ⚠ HYPOTHÈSE — Le rôle **Organisateur** (padel « partie ouverte », cahier `p-padel` §2) est un **rôle
  contextuel** porté par un `Client`/`Beneficiaire` (M4) sur **une réservation donnée**, pas un rôle
  socle distinct ; il porte la responsabilité solidaire du reste à payer (§4.7).

## 4. Comportements & règles
Chaque comportement trace une **RG-M5** généralisée (source `cahier-detaille.html`, panel `p-m5`) et/ou
une règle/décision **nouvelle au niveau socle** (généralisant `p-padel`/`p-musee`/décisions actées).

### 4.1 Ressource réservable — type paramétrable, capacité, ouverture (US-RES-01)
- Une **Ressource** est l'objet générique réservable : **terrain, glace, ligne d'eau, salle, court,
  table, guide, encadrant…**. Son **type** est une **valeur de configuration** (pas un `if` codé en
  dur, constitution §4 point 4) : chaque verticale déclare les types de ressource dont elle a besoin.
- **RG-M5-05** (généralisée) — Une **Ressource peut exiger une compétence/qualification** (attribut
  générique `compétenceRequise`, ex. « MNS », « BNSSA », « coach niveau 3 ») ; seules les ressources de
  type personnel dont la compétence est **valide** (non expirée) sont proposables/sélectionnables pour
  un Créneau qui l'exige. Le **détail réglementaire** (nature du diplôme, contrôle de validité) est
  **spécifié par la verticale** qui l'instancie, ce module ne porte que l'**attribut** et le **contrôle
  générique** (valide/non valide).
- **Ressource partageable / ressource mère** (`RG-M5-08` généralisée, §4.6) — Une Ressource peut être
  **partageable** : elle porte alors des **sous-ressources** (ex. un bassin ↔ ses lignes d'eau ; une
  salle ↔ ses tables) qui peuvent être réservées **indépendamment**, sous réserve du respect de la
  **jauge globale** de la ressource mère (§4.6).
- **Disponibilités** — La Ressource porte des plages de disponibilité et des indisponibilités
  ponctuelles (maintenance, congé…) qui bornent la création de Créneaux.

### 4.2 Créneau, capacité & jauge (US-RES-01/02, RG-M5-01/03)
- **RG-M5-03** (généralisée) — Un **conflit de ressource** (même ressource déjà affectée sur un
  créneau chevauchant) est **bloqué à la création** du créneau, avec un motif explicite.
- **RG-M5-01** (généralisée) — Le nombre de **Réservations confirmées** sur un créneau ne peut
  **dépasser sa capacité** ; au-delà, seule l'inscription en **liste d'attente** est proposée (§4.4).
- Un créneau peut porter un **tarif de référence** (repris de M1) qui sert de base à la vente à l'unité
  (§4.3) et au calcul du montant dû en cas de no-show (§4.7).
- ⚠ HYPOTHÈSE — La capacité d'un créneau peut **différer** de la capacité propre de sa ressource (ex.
  un cours collectif limité à 12 places sur un bassin qui en accueille 30) ; retenue comme un champ
  distinct, cohérent avec le cahier M5-02 (« Capacité : ≥ 1 »).

### 4.3 Réservation — décompte quota ou vente à l'unité (US-RES-03, RG-M5-02)
- **RG-M5-02** (généralisée) — Une réservation **décompte le quota inclus** d'une formule (M1,
  `RG-M1-12`, semaine calendaire, sans report) si le bénéficiaire en dispose ; **sinon**, elle
  déclenche une **vente à l'unité** traitée par M2 (au tarif de référence du créneau/de l'activité).
- Si la place est disponible **et** le quota présent, la réservation est **confirmée sans
  encaissement** ; si le quota est épuisé/absent, l'agent (ou le client en ligne) est renvoyé vers M2
  pour la vente à l'unité **avant** confirmation.
- Une **Activité** (concept catalogue, repris du cahier M5-02 : libellé, type, durée, niveau requis,
  compétence exigée, prix de référence) peut **générer** des Créneaux et **référence M1** pour le
  tarif ; c'est **le même objet** que celui référencé par `ServiceInclus.activité` (`spec-offre.md`
  §5, RG-M1-03) — ce module en est donc le **propriétaire fonctionnel**.

### 4.4 Liste d'attente — promotion automatique (US-RES-04, RG-M5-06)
- **RG-M5-06** — En cas de **désistement** sur un créneau complet, le **premier de la liste d'attente**
  est **promu automatiquement** en réservation confirmée et **notifié** ; aucune validation manuelle
  n'est requise (décision actée).
- **Musée — sur-vente / récupération de quota (généralisation, décision actée 🏛️)** — Le principe
  générique retenu : la priorité va au **premier confirmé** ; un no-show détecté sur un créneau tendu
  **libère sa place** et déclenche la même mécanique de promotion (le quota non consommé redevient
  disponible pour la liste d'attente **si le délai franc restant le permet** — ⚠ HYPOTHÈSE, cf. §7).

### 4.5 Récurrence & report automatique (US-RES-05/06, RG-M5-07/11)
- **RG-M5-07** (généralisée) — Une **réservation récurrente** (motif hebdo/quotidien + date de fin) se
  propage selon son motif ; chaque **occurrence** peut porter une **exception** (annulation,
  modification d'horaire/ressource) **sans affecter la série** (décision actée « à partir de cette
  occurrence » par défaut, avec possibilité de ne modifier qu'une seule occurrence).
- **RG-M5-11** (**nouvelle**, généralise `RG-PADEL-07` + décision actée 🎾 « Récurrence vs tournoi ») —
  Une réservation récurrente (créneau fixe) est **prioritaire** sur les nouvelles demandes ; en cas de
  **conflit** (événement/tournoi/exception bloquante validée sur la ressource), le système tente un
  **report automatique** de l'occurrence en conflit sur une **ressource alternative équivalente**
  (même type, même capacité minimale, même fenêtre horaire) ; **à défaut** de ressource alternative
  disponible, l'occurrence bascule en **validation manuelle** par un rôle habilité
  (`reservation × arbitrer_recurrence`), avec **notification** du bénéficiaire.

### 4.6 Ressource partageable — jauge propre par créneau + jauge globale (US-RES-11, RG-M5-08)
- **RG-M5-08** (généralisée) — Une **ressource partageable** (ex. bassin ↔ lignes d'eau) peut porter
  **simultanément plusieurs Créneaux** (ex. un créneau scolaire sur une ligne, un créneau public sur
  une autre) : chaque Créneau **décompte sa propre capacité**, et un **compteur de capacité de la
  ressource mère** est maintenu **en plus** de chaque compteur de créneau, pour éviter toute surcharge
  physique simultanée (généralise la décision actée M5 « Lignes d'eau partagées »).
- Un **Créneau à public réservé** (scolaire, club, groupe privé…) n'est **ni visible ni réservable en
  ligne** par le grand public (RG-M5-08).

### 4.7 No-show / annulation tardive — délai franc paramétrable (US-RES-07/08/13/14) `critique`
Règle générique demandée explicitement au socle, généralisée à partir de `RG-PADEL-06` et de la
décision actée 🎾 « Annulation tardive / no-show : délai franc paramétrable → facturation ; en deçà
exonéré » :

- **RG-M5-04** (généralisée) — L'**annulation par le bénéficiaire** est **libre et gratuite jusqu'à un
  délai franc paramétrable** avant le début du créneau (ex. 24 h) ; **au-delà** (annulation tardive)
  **ou en l'absence d'annulation avec absence constatée** (no-show), la réservation bascule dans le
  périmètre de facturation de `RG-M5-09`.
- **RG-M5-09** (**nouvelle**, généralise `RG-PADEL-06` + décision actée) — Une réservation dont
  l'annulation intervient **après le délai franc**, ou dont le bénéficiaire ne s'est **pas présenté**
  (no-show constaté, §4.8), est **facturée** selon une **règle paramétrable** (`RegleAnnulation`) :
  - **délai franc** (durée, ex. 24 h/48 h) — mesuré entre l'action (annulation ou constat de no-show)
    et le **début du créneau** ;
  - **montant** — fixe **ou** pourcentage du tarif de référence du créneau ;
  - **exonérations** — paramétrables (ex. membre exonéré, 1ʳᵉ occurrence tolérée, motif médical
    justifié…) ; toute exonération manuelle est **tracée** (auteur, motif) — `reservation × exonerer`.
  - **Portée de la règle** — paramétrable à plusieurs niveaux (établissement, type de ressource,
    ressource, activité/produit) ; ⚠ HYPOTHÈSE — l'**ordre de priorité en cas de règles multiples
    applicables** n'est pas fourni par les sources ; retenu par analogie avec la résolution de rôles
    hérités (M8, « la règle la plus spécifique l'emporte », cohérent avec « la plus restrictive
    l'emporte » du socle) — **à confirmer**.
- **Passé le délai, le quota ou la place est définitivement perdu** (décision actée du cahier M5, non
  contredite par la généralisation) : l'annulation tardive en ligne, **au-delà** du délai, est
  **refusée** (le bénéficiaire ne peut plus annuler lui-même — seul un agent/responsable habilité peut
  ensuite qualifier l'issue en annulation tardive facturée ou en no-show).

### 4.8 Constat de présence / no-show — émargement (US-RES-08, RG-M5-09)
- **Émargement manuel** (repris du cahier M5-05) — Un opérateur de ressource marque **Présent** /
  **Absent** en un geste, horodaté, modifiable jusqu'à la clôture du créneau ; un compte-rendu libre
  est optionnel.
- **Détection automatique via l'accès (nouveau, généralise le couplage padel « badge sur fenêtre
  réservée »)** — Si la réservation est **liée à un droit d'accès** (§4.9), un **passage validé**
  (`spec-acces.md`, RG-ACC-01/06) pendant la fenêtre du créneau **vaut confirmation de présence**
  (`présenceConfirmée = true`), sans émargement manuel.
- **Bascule automatique en no-show** — À l'**issue du créneau** (fin + marge paramétrable), si aucune
  présence n'a été confirmée (ni émargement manuel « présent », ni passage d'accès) et que la
  réservation n'a **pas été annulée dans le délai franc**, le **système** fait basculer automatiquement
  la réservation au statut **no-show**, ce qui déclenche `RG-M5-09` (facturation).
- ⚠ HYPOTHÈSE — La **marge** après la fin du créneau avant bascule automatique en no-show n'est pas
  chiffrée par les sources ; retenue comme **paramètre par établissement/activité**, valeur par défaut
  non fixée, à confirmer au paramétrage produit.

### 4.9 Lien optionnel vers un droit d'accès (US-RES-12, RG-M5-12)
- **RG-M5-12** (**nouvelle**, généralise `RG-PADEL-05` : « éclairage et accès badge du terrain
  s'activent uniquement sur la fenêtre réservée ») — Une Réservation **confirmée** peut **projeter**
  un `DroitAccès` (L3) dont la **fenêtre de validité** = **début → fin du créneau**, éventuellement
  assortie d'une **tolérance d'entrée paramétrable** (marge d'avance/retard, réutilise `RG-ACC-01`).
  Ce lien est **optionnel** : toutes les verticales n'ouvrent pas un accès physique sur une réservation
  (ex. une inscription à une liste d'attente musée sans passage physique dédié).
- Ce module **ne redéfinit pas** le mécanisme générique de contrôle d'accès (topologie, hors-ligne,
  jauge FMI — `spec-acces.md`), il en **consomme** la projection `DroitAccès` de la même façon qu'une
  vente M2 le fait pour un billet (`spec-vente.md` §4.6).
- ⚠ HYPOTHÈSE — Le pilotage d'un **automatisme physique annexe** (ex. relais d'éclairage du terrain
  padel) n'est **pas** un comportement de ce module socle ; il relève de la verticale qui l'exploite
  (déclenché par la même fenêtre de créneau), simplement **signalé** ici pour mémoire d'articulation.

### 4.10 Paiement partagé (US-RES-09, RG-M5-10)
- **RG-M5-10** (**nouvelle**, généralise `RG-PADEL-04` + décision actée « Paiement partagé
  défaillant ») — Une réservation peut être **payée en une fois** par l'organisateur **ou** répartie
  entre plusieurs **participants** : chaque **part** est encaissée **individuellement** et **tracée**
  sur la réservation (référence M2 pour l'encaissement effectif de chaque part).
- **Organisateur solidaire (décision actée)** — Si un participant **ne règle pas sa part**, la
  réservation reste **confirmée** (elle n'est **pas bloquée** dans l'attente du paiement complet) et
  l'**organisateur devient solidaire du reste à payer** : le reste dû lui est imputable (facturable au
  même titre qu'un no-show de sa propre part si le délai franc est dépassé sans régularisation, cf.
  `RG-M5-09`).
- **Complétion partielle** — ⚠ HYPOTHÈSE (par analogie avec la décision actée padel « Partie ouverte
  3/4 : toujours maintenue à 3, surcoût réparti entre les présents ») : lorsqu'une réservation à
  participants multiples se tient avec **moins de participants que prévu** (défection avant délai
  franc), le créneau **reste honoré** au nombre de présents, le **surcoût est réparti** entre les
  participants restants **selon une règle paramétrable** — comportement générique retenu par défaut,
  **à confirmer** verticale par verticale (une salle de sport pourrait préférer l'annulation pure).

## 5. Objets de données
Les types PHP sont indicatifs (spec = comportement observable). Tout objet est rattaché à un
**Établissement**/**Espace** via le socle (`RG-SOCLE-01`) ; identifiants = **UUID** (constitution §3).
Les objets M1 (Produit, Formule, ServiceInclus, TypeTarif), M2 (Vente, Avoir, Paiement), M4
(Client, Beneficiaire, PMV) et L3 (DroitAccès, Passage) sont **référencés, non redéfinis**.

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| **Ressource** | id | uuid | PK | RG-M5-03/05/08 |
| | établissement/espace | ref (socle) | requis | RG-SOCLE-01 |
| | type | string (config) | requis, paramétrable | terrain/glace/ligne_eau/salle/court/table/personnel… |
| | libellé | string | requis | — |
| | capacitéPropre | int ≥ 1 | requis | RG-M5-01 |
| | partageable | bool | défaut = false | RG-M5-08 |
| | ressourceMère | ref Ressource? | requis si sous-ressource | ex. ligne d'eau ↔ bassin |
| | compétenceRequise | string? | optionnel | RG-M5-05, attribut générique |
| | disponibilités[] | plages horaires | — | borne la création de Créneau |
| | indisponibilités[] | (début, fin, motif) | — | congé/maintenance |
| | actif | bool | défaut = true | — |
| **Activité** *(catalogue, propriété fonctionnelle de ce module, référencée par M1)* | id, libellé | uuid, string | PK | cahier M5-02 |
| | type, durée | string, duration | requis | hérité par défaut sur le Créneau |
| | niveauRequis | string? | optionnel | filtre la réservation |
| | compétenceExigée | ref (attribut Ressource)? | optionnel | RG-M5-05 |
| | produitTarifRéférence | ref Produit (M1)? | optionnel | tarif de la vente à l'unité (§4.3) |
| **Recurrence** | id | uuid | PK | RG-M5-07 |
| | motif | enum {hebdomadaire, quotidien, mensuel} | requis | — |
| | finRécurrence | date | requis | — |
| | joursSemaine[] | liste enum | requis si hebdo | — |
| | règleConflit | enum {report_auto, validation_manuelle} | défaut = report_auto | RG-M5-11 |
| | exceptions[] | ref Créneau[] (occurrences modifiées/annulées) | append-only | RG-M5-07, série non affectée |
| **Creneau** | id | uuid | PK | RG-M5-01/03 |
| | ressource | ref Ressource | requis | pas de chevauchement (RG-M5-03) |
| | activité | ref Activité? | optionnel | hérite durée/tarif |
| | début, fin | datetime, datetime | début < fin | — |
| | capacité | int ≥ 1 | requis, peut différer de `Ressource.capacitéPropre` | — |
| | statut | enum {planifié, complet, terminé, annulé} | défaut = planifié | cahier §6 |
| | publicRéservé | string? | optionnel | RG-M5-08, masque en ligne |
| | récurrence | ref Recurrence? | optionnel | — |
| **Reservation** | id | uuid | PK | RG-M5-01/02/09 |
| | créneau | ref Creneau | requis | — |
| | organisateur | ref Beneficiaire/Client (M4) | requis | payeur potentiellement distinct (RG-M4-02) |
| | statut | enum {confirmée, liste_attente, annulée_libre, annulée_tardive_facturée, no_show_facturé, honorée} | défaut = confirmée si place | cahier §6 |
| | dateCréation | datetime | requis | — |
| | modeDécompte | enum {quota_formule, vente_unité, gratuit} | requis | RG-M5-02 |
| | quotaConsommé | ref ServiceInclus (M1)? | requis si `quota_formule` | RG-M1-12 |
| | venteRattachée | ref Vente (M2)? | requis si `vente_unité` ou facturation | RG-M5-02/09 |
| | montantDû | decimal ≥ 0 | dérivé du tarif de référence | — |
| | dateLimiteAnnulation | datetime | = début créneau − délai franc de la `RegleAnnulation` applicable | RG-M5-04/09 |
| | présenceConfirmée | bool | défaut = false | §4.8, manuel ou auto-accès |
| | dateConfirmationPrésence, sourcePrésence | datetime?, enum {émargement_manuel, passage_accès}? | requis si `présenceConfirmée` | §4.8 |
| **ParticipantReservation** | id | uuid | PK | RG-M5-10 |
| | réservation | ref Reservation | requis | — |
| | personne | ref Beneficiaire (M4) | requis | — |
| | estOrganisateur | bool | défaut = false | 1 seul par réservation |
| | partMontant | decimal ≥ 0 | requis | somme des parts = montantDû |
| | statutPaiement | enum {payé, en_attente, imputé_organisateur} | défaut = en_attente | RG-M5-10 |
| **ListeAttente** | id | uuid | PK | RG-M5-06 |
| | créneau | ref Creneau | requis | — |
| | bénéficiaire | ref Beneficiaire (M4) | requis | — |
| | rang | int ≥ 1 | requis, unique par créneau | premier arrivé premier servi |
| | dateInscription | datetime | requis | — |
| | statut | enum {en_attente, promue, expirée, annulée} | défaut = en_attente | — |
| | promueEn | ref Reservation? | requis si `promue` | RG-M5-06 |
| **RegleAnnulation** *(= règle no-show)* | id | uuid | PK | RG-M5-09 |
| | portée | enum {établissement, type_ressource, ressource, activité} | requis | résolution du plus spécifique — ⚠ à confirmer |
| | cible | ref (selon portée) | requis | — |
| | délaiFranc | duration | requis | ex. 24 h/48 h |
| | montant | decimal ou % | requis | fixe ou % du tarif de référence |
| | exonérations[] | liste de règles (motif, condition) | optionnel | ex. membre exonéré |
| | modeFacturation | enum {vente_immédiate, débit_pmv_auto, prélèvement_différé, facture_à_encaisser} | requis | ⚠ point ouvert majeur, §8 |
| | marginPostCréneau | duration | requis | bascule auto no-show, §4.8 |
| | actif | bool | défaut = true | — |
| **Emargement** | id | uuid | PK | cahier M5-05 |
| | réservation | ref Reservation | requis | — |
| | statut | enum {présent, absent} | requis | horodaté, modifiable jusqu'à clôture |
| | horodatage | datetime | requis | — |
| | opérateur | ref Utilisateur (socle)? | optionnel | émargement manuel |
| | compteRendu | string? | optionnel | — |
| **FacturationNoShow** | id | uuid | PK | RG-M5-09 |
| | réservation | ref Reservation | requis | — |
| | règleAppliquée | ref RegleAnnulation | requis | — |
| | montant | decimal ≥ 0 | requis | — |
| | statut | enum {à_facturer, facturée, exonérée, contestée} | défaut = à_facturer | — |
| | venteOuAvoir | ref Vente/Avoir (M2)? | requis si `facturée` | articulation §8 |
| | exonéréPar, motifExonération | ref Utilisateur?, string? | requis si `exonérée` | `reservation × exonerer`, tracé |
| **ProjectionAccesReservation** | id | uuid | PK | RG-M5-12 |
| | réservation | ref Reservation | 1:1 | — |
| | droitAccès | ref DroitAccès (L3) | requis | `spec-acces.md` §5 |
| | fenêtreValidité | {début, fin} | = fenêtre du créneau ± tolérance | RG-ACC-01 |

## 6. Critères d'acceptation
- **CA-1 (US-RES-01, RG-M5-03/05)** — *Étant donné* la déclaration d'une Ressource, *quand* le
  gestionnaire choisit un **type** (terrain, glace, ligne d'eau, salle, table…), une **capacité** et,
  le cas échéant, une **compétence requise**, *alors* la Ressource est créée et réutilisable pour
  n'importe quel Créneau de ce type, sans code spécifique par verticale.
- **CA-2 (US-RES-01, RG-M5-03)** — *Étant donné* deux Créneaux sur la **même Ressource** avec des
  fenêtres qui **chevauchent**, *quand* le second est créé, *alors* la création est **bloquée** avec un
  motif explicite de conflit.
- **CA-3 (US-RES-02/03, RG-M5-01/02)** — *Étant donné* un Créneau avec des places disponibles et un
  bénéficiaire disposant d'un **quota de formule**, *quand* il réserve, *alors* la Réservation est
  **confirmée sans encaissement** et le quota est **décompté** (semaine calendaire, RG-M1-12) ; *étant
  donné* un quota **épuisé/absent**, *alors* une **vente à l'unité** est déclenchée vers M2 avant
  confirmation.
- **CA-4 (US-RES-03, RG-M5-01)** — *Étant donné* un Créneau **complet**, *quand* un bénéficiaire tente
  de réserver, *alors* **seule l'inscription en liste d'attente** est proposée, la réservation directe
  étant refusée.
- **CA-5 (US-RES-04, RG-M5-06)** — *Étant donné* une liste d'attente non vide sur un Créneau complet,
  *quand* une Réservation confirmée est **annulée**, *alors* le **premier de la liste d'attente** est
  **promu automatiquement** en Réservation confirmée et **notifié**, sans validation manuelle.
- **CA-6 (US-RES-05, RG-M5-07)** — *Étant donné* un Créneau récurrent hebdomadaire, *quand* le
  gestionnaire modifie **une seule occurrence** (horaire ou ressource), *alors* seule cette occurrence
  change, la série et les occurrences déjà réservées **restant intactes**.
- **CA-7 (US-RES-06, RG-M5-11)** — *Étant donné* une réservation récurrente en conflit avec un
  événement validé sur sa ressource, *quand* le conflit est détecté, *alors* le système **tente un
  report automatique** sur une ressource alternative équivalente ; *quand* aucune ressource équivalente
  n'est disponible, *alors* l'occurrence bascule en **validation manuelle**, avec notification du
  bénéficiaire.
- **CA-8 (US-RES-07, RG-M5-04/09)** — *Étant donné* une Réservation dont le **délai franc** n'est **pas
  dépassé**, *quand* le bénéficiaire annule, *alors* l'annulation est **gratuite**, la place/le quota
  est libéré(e) et proposé(e) à la liste d'attente ; *étant donné* une annulation **après le délai
  franc**, *alors* elle est **refusée en libre-service** (le quota/la place est perdu) et la
  réservation est marquée **annulée tardive**, déclenchant `RG-M5-09`.
- **CA-9 (US-RES-08, RG-M5-09)** — *Étant donné* un Créneau **terminé** (fin + marge paramétrable),
  *quand* aucune présence n'a été confirmée (ni émargement, ni passage d'accès) pour une réservation
  non annulée à temps, *alors* le système la fait basculer **automatiquement** au statut **no-show** et
  crée une `FacturationNoShow`.
- **CA-10 (US-RES-08, lien L3)** — *Étant donné* une Réservation **liée à un droit d'accès**, *quand*
  un **passage est validé** dans la fenêtre du créneau, *alors* la présence est **confirmée
  automatiquement**, sans émargement manuel requis.
- **CA-11 (US-RES-13/14, RG-M5-09)** — *Étant donné* une `FacturationNoShow` au statut `à_facturer`,
  *quand* le système applique la `RegleAnnulation` (délai franc dépassé, pas d'exonération), *alors*
  une **vente ou un avoir M2** est généré pour le **montant** défini (fixe ou % du tarif de référence),
  la `FacturationNoShow` passe à `facturée`, et l'écriture est **traçable** jusqu'à la réservation
  d'origine.
- **CA-12 (US-RES-14, RG-M5-09)** — *Étant donné* un bénéficiaire couvert par une **exonération**
  (ex. membre), *quand* un no-show le concerne, *alors* la `FacturationNoShow` passe à **`exonérée`**
  sans encaissement, **tracée** (règle appliquée).
- **CA-13 (US-RES-09, RG-M5-10)** — *Étant donné* une Réservation à **paiement partagé** entre 4
  participants, *quand* chacun règle sa part, *alors* chaque `ParticipantReservation.statutPaiement`
  passe à `payé` **individuellement** ; *quand* un participant **ne règle pas**, *alors* la réservation
  **reste confirmée** et le reste dû est **imputé à l'organisateur** (`imputé_organisateur`).
- **CA-14 (US-RES-11, RG-M5-08)** — *Étant donné* une Ressource **partageable** (ex. bassin) portant
  deux Créneaux simultanés sur des sous-ressources distinctes (deux lignes d'eau), *quand* chacun se
  remplit selon sa propre capacité, *alors* le **compteur de la ressource mère** reste **cohérent avec
  la somme des occupations**, et toute réservation qui le dépasserait est **refusée** même si un
  Créneau individuel a encore de la place.
- **CA-15 (US-RES-12, RG-M5-12)** — *Étant donné* une Réservation confirmée **liée à un accès**,
  *quand* elle est confirmée, *alors* un `DroitAccès` est projeté avec une **fenêtre de validité** égale
  à celle du créneau (± tolérance paramétrable) ; en dehors de cette fenêtre, l'accès est **refusé**
  (comportement générique L3, RG-ACC-01).

## 7. Cas limites
- **Annulation exactement à l'heure du délai franc** — ⚠ HYPOTHÈSE : retenue comme **encore gratuite**
  (comparaison `≤ délaiFranc` inclusive), non tranchée explicitement par les sources.
- **No-show sur une réservation gratuite/quota sans moyen de paiement enregistré** — Point d'articulation
  majeur : voir §8 et récapitulatif final ; aucune vente immédiate n'est possible sans agent ni moyen
  de paiement à distance déjà connu.
- **Créneau annulé par le gestionnaire (pas par le client)** — ⚠ HYPOTHÈSE : aucun no-show ni frais
  d'annulation ne s'applique côté bénéficiaire ; le quota/la place est **restitué(e)** et le client est
  notifié (comportement retenu par cohérence, non détaillé dans les sources).
- **Ressource partageable dont la ressource mère devient indisponible** (ex. bassin fermé) — Toutes les
  sous-ressources et leurs Créneaux en cours doivent être **annulés en cascade** ; ⚠ HYPOTHÈSE : la
  cascade et sa notification associée ne sont pas détaillées par les sources, à préciser.
- **Récurrence dont le report automatique choisit une ressource moins adaptée** (ex. capacité
  inférieure) — ⚠ HYPOTHÈSE : le report automatique n'est proposé que sur une ressource **équivalente
  ou supérieure** ; en deçà, bascule systématique en validation manuelle (RG-M5-11), à confirmer.
- **Paiement partagé — organisateur lui-même en no-show** — Traité comme n'importe quel bénéficiaire :
  sa part impayée + les parts non réglées des autres participants sont **cumulées** sur sa
  responsabilité solidaire (RG-M5-10), sans double comptage avec `RG-M5-09` — ⚠ HYPOTHÈSE de
  non-cumul, à confirmer avec le métier.
- **Détection de présence par accès sur une réservation multi-participants** — ⚠ HYPOTHÈSE : un seul
  passage d'accès valide **la présence du participant identifié**, pas de toute la réservation ; la
  bascule en no-show s'évalue **par participant**, pas globalement — non tranché explicitement par les
  sources.
- **Liste d'attente qui expire sans réponse à la promotion** — ⚠ HYPOTHÈSE : un délai de confirmation
  paramétrable après promotion (non fixé par les sources) fait passer la ListeAttente au statut
  `expirée` et promeut le rang suivant ; non détaillé dans le cahier (qui ne prévoit qu'une promotion
  automatique sans délai explicite de confirmation).
- **Établissement qui n'active aucune RegleAnnulation** — ⚠ HYPOTHÈSE : par défaut, **aucune
  facturation** de no-show/annulation tardive n'est appliquée (comportement le moins agressif, cohérent
  avec le principe socle « rien n'est codé en dur sans configuration »), le comportement historique du
  cahier M5 (« quota/place perdu, sans remboursement automatique, sans facturation ») restant le
  défaut jusqu'à activation explicite d'une règle.
- **Utilisateur sans affectation sur l'établissement/l'espace de la ressource** — Aucun accès (hérité du
  socle, `RG-SOCLE-05`).

## 8. Dépendances
- **Dépend de : socle L0** (`specs/L0-socle/spec-socle.md`) — hiérarchie Groupe/Région/Établissement/
  Espace (`RG-SOCLE-01`) à laquelle se rattachent Ressources et Créneaux ; permissions `module × action`
  réutilisées sur le module **`reservation`** (`RG-SOCLE-02/03/04`) ; cadrage par établissement actif
  (`RG-SOCLE-05`) ; journal d'audit append-only (`RG-SOCLE-07`) sur lequel s'appuient la traçabilité des
  exonérations, promotions de liste d'attente et bascules en no-show.
- **Dépend de : M1 · Offre & Tarification** (L1, `specs/L1-offre/spec-offre.md`) — ce module est
  **propriétaire fonctionnel** de l'`Activité` référencée par `ServiceInclus.activité` (RG-M1-03) ; il
  **consomme** le tarif de référence (Produit/grille), le **quota inclus** d'une Formule et sa règle de
  décompte en **semaine calendaire sans report** (RG-M1-12), sans redéfinir le moteur de catalogue.
- **Dépend de : M2 · Vente & Caisse** (L2, `specs/L2-vente/spec-vente.md`) — toute **vente à l'unité**
  déclenchée par une réservation sans quota (RG-M5-02), et toute **facturation de no-show/annulation
  tardive** (RG-M5-09) se traduisent par une **Vente** ou un **Avoir** M2 (contre-passation NF525,
  `RG-M2-07`) ; ce module **déclenche**, M2 **encaisse et trace**. ⚠ voir récapitulatif final — le
  **mécanisme d'encaissement à distance sans agent présent** (charge d'un no-show sur une réservation
  gratuite/à quota, sans paiement initial) **n'est couvert par aucune spec de ce dépôt** à ce jour ; M2
  (`spec-vente.md`) ne définit qu'un encaissement **au guichet ou via TPE présentiel**, et le
  **paiement différé** qu'il prévoit (`RG-M2-03`) suppose un **justificatif non acquitté** géré
  manuellement, pas un prélèvement automatique.
- **Dépend de : M6 · Compta & Régie** (L4, `specs/L4-compta/spec-compta.md`) — les ventes/avoirs générés
  par ce module (vente à l'unité, facturation no-show) **alimentent** le journal et, le cas échéant, la
  logique **PCA** (consommation d'un service inclus au passage, RG-M6-03) ; ce module ne redéfinit
  aucune écriture.
- **Dépend de : M4 · CRM** (L5, `specs/L5-crm/spec-crm.md`) — le bénéficiaire/organisateur d'une
  réservation est un `Beneficiaire` d'une `Famille` (`RG-M4-02`, bénéficiaire ≠ payeur) ; le
  **porte-monnaie virtuel (PMV)** (`RG-M4-03`) est un candidat naturel de **moyen de règlement
  immédiat** pour la facturation d'un no-show (débit automatique si le solde le permet), sans
  nécessiter de nouveau moyen de paiement à distance — piste retenue comme **valeur par défaut** de
  `RegleAnnulation.modeFacturation` (⚠ HYPOTHÈSE, à confirmer avec M2/M4, cf. récapitulatif).
- **Dépend de (option) : Accès** (L3, `specs/L3-acces/spec-acces.md`) — une Réservation peut **projeter**
  un `DroitAccès` (RG-ACC-01) sur la fenêtre du créneau (RG-M5-12), et un `Passage` validé
  (RG-ACC-06) sert de **détection automatique de présence** (§4.8) ; ce module **consomme** le
  mécanisme générique d'accès (topologie, hors-ligne, jauge FMI) sans le redéfinir.
- **Référencé par (consommateurs de cette capacité, non redéfinis ici) :**
  `specs/L6-piscine/spec-piscine.md` (objets provisoires `Bassin`/`LigneEau`/`CreneauBassin`, dérivés
  de `RG-M5-01` à `07` avant l'existence de cette spec — **à réconcilier** : `Bassin` → `Ressource`
  partageable, `LigneEau` → sous-`Ressource`, `CreneauBassin` → `Creneau`) ; le panel `p-padel` (terrain
  = `Ressource`, créneau 60/90 min = `Creneau`, no-show/paiement partagé = `RG-M5-09/10`) ; le panel
  `p-musee` (créneaux timed-entry, guide = `Ressource` qualifiée) ; `specs/sport-fitness/spec-sport.md`
  (§2 exclut explicitement « le planning des cours collectifs, la réservation, la liste d'attente » et
  les rattache à ce module, non encore spécifié au moment de sa rédaction).

---

## Points ouverts / hypothèses (récapitulatif)

### Absence de source officielle (à faire trancher/valider par le produit)
1. **US-RES-01 à 14 sont des stories définies par cet agent** — M5 n'a pas de lot dédié dans l'ordre de
   construction MVP (constitution §5) et `backlog.html` ne contient aucune story générique de
   planning/réservation (seules `US-L6-03/04/05/06` de la verticale Piscine **consomment** la notion
   sans la définir). À faire **valider, renuméroter et chiffrer** officiellement avant développement.
2. **`RG-M5-09` à `12` sont de nouvelles règles socle**, absentes du panel `p-m5` du cahier (qui ne va
   que jusqu'à `RG-M5-08`) : elles **généralisent** des règles/décisions aujourd'hui déclarées
   uniquement au niveau de la verticale padel (`RG-PADEL-04/05/06/07`) ou en décision actée musée. Cette
   généralisation est une **proposition de cet agent** conforme à la demande explicite du commanditaire
   (« capacité socle réutilisable »), mais elle **élève au rang de règle socle** des comportements qui
   n'étaient jusqu'ici motivés que par un seul métier (padel) — **à faire valider** que rien de
   spécifiquement « padel » (ex. le montant en % plutôt qu'en valeur fixe, la notion même de « partie »)
   n'a été indûment généralisé.

### ⚠ POINT D'ARCHITECTURE MAJEUR — à arbitrer avec M2/M6 avant implémentation
3. **Le mécanisme d'encaissement d'un no-show sans encaissement préalable ni agent présent n'est
   couvert par aucune spec de ce dépôt.** `RegleAnnulation.modeFacturation` propose 4 pistes
   (`vente_immédiate` — possible seulement si un agent traite le dossier après coup ; `débit_pmv_auto`
   — possible seulement si le bénéficiaire a un PMV crédité, cf. `spec-crm.md` RG-M4-03 ;
   `prélèvement_différé` — suppose un moyen de paiement enregistré/tokenisé ou un mandat SEPA, **non
   défini comme objet socle unique** à ce jour, cf. point ouvert similaire de `spec-sport.md` point 2 ;
   `facture_à_encaisser` — la plus sûre par défaut, mais purement déclarative tant qu'aucun canal de
   règlement à distance n'est standardisé). **C'est le principal risque de ce module** : sans
   arbitrage, la « facturation d'un no-show » reste un **statut** (`FacturationNoShow.statut =
   à_facturer`) sans garantie d'encaissement effectif. À trancher avec le produit + M2/M6 avant tout
   plan technique.

### ⚠ HYPOTHÈSE fonctionnelle (comportement retenu par défaut, à confirmer)
4. **Ordre de priorité entre `RegleAnnulation` de portées différentes** (établissement / type de
   ressource / ressource / activité) — retenu par analogie « la plus spécifique l'emporte » (§4.7).
5. **Marge post-créneau avant bascule automatique en no-show** — non chiffrée par les sources,
   paramètre par établissement/activité (§4.8).
6. **Défaut « aucune RegleAnnulation active »** — retenu comme comportement historique du cahier M5
   (perte de place/quota sans facturation), jusqu'à activation explicite (§7).
7. **Complétion partielle générique** (créneau tenu à effectif réduit, surcoût réparti) — généralisée
   depuis la décision padel « partie ouverte 3/4 », à confirmer verticale par verticale (§4.10).
8. **Non-cumul entre la solidarité de l'organisateur (`RG-M5-10`) et sa propre facturation no-show
   (`RG-M5-09`)** — retenu par hypothèse de simplicité, non tranché par les sources (§7).
9. **Récupération du quota d'un no-show sur créneau tendu** (généralisation musée) — conditionnée à un
   délai franc encore ouvert, non détaillée par les sources (§4.4).
10. **Détection de présence par accès sur réservation multi-participants** — retenue comme évaluation
    **par participant**, non tranchée par les sources (§7).
11. **Expiration d'une promotion de liste d'attente sans confirmation** — mécanisme de délai retenu par
    analogie avec d'autres modules (abandon de panier M3, 15 min), non fourni par les sources pour M5
    (§7).
