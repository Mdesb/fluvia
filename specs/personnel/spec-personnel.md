# Spec — Personnel & planning d'équipe (`App\Personnel`, module `personnel`)

- **Lot / module :** Transverse socle+vertical — **module `App\Personnel`**, consommé par L3 (Accès),
  Réservation (M5), L6 Piscine, Padel (et, par extension, Patinoire/Sport/Musée pour tout encadrant).
- **Stories couvertes :** **US-PERSO-01 à US-PERSO-11** — ⚠ **HORS BACKLOG** : aucune de ces stories
  n'existe dans `backlog.html` (le backlog ne porte que sur les lots L0→L7 et les verticales déjà
  spécifiées). Elles sont **définies par cet agent** à partir des besoins exprimés dans la mission
  (fiche employé, qualifications, planning, absences léger, badge & accès staff) et des points déjà
  posés par `cahier-detaille.html` (§M5-04 « Ressources & encadrants », §RG-PISC-02, §RG-PADEL coach).
  **À faire valider et numéroter officiellement dans le backlog avant développement**, comme déjà
  signalé pour M5 générique (`specs/reservation/spec-reservation.md`, même statut).
- **Règles de gestion :** `RG-PERSO-01` à `RG-PERSO-10` (**nouvelles**, ce module — aucune RG dédiée au
  personnel n'existe dans `cahier-detaille.html`, qui exclut explicitement « Gestion RH des plannings
  agents (paie, congés) — SIRH externe » du périmètre M5, cahier §`p-m5` « Hors périmètre »). Règles
  **réutilisées, non redéfinies** : `RG-SOCLE-01` à `07` (`spec-socle.md`), `RG-ACC-01/02/03/05/06/07`
  (`spec-acces.md`), `RG-M5-03/05` généralisées (`spec-reservation.md`), `RG-PISC-02`
  (`spec-piscine.md`).
- **Statut :** brouillon — périmètre et numérotation `US-PERSO` à valider avec le commanditaire.

## 1. Objectif
Tenir un **référentiel unique des employés** (fiche RH légère, qualifications et leur validité),
organiser leur **planning d'équipe** (créneaux de travail, affectations, absences), et leur donner un
**accès physique** aux espaces qu'ils doivent pouvoir franchir — via un **badge = Support du même
moteur d'accès (L3)** que les clients — le tout **sans réimplémenter** ni la paie/gestion légale des
congés (déléguées à un SIRH externe, décision actée du cahier §M5), ni le moteur de réservation
client (M5/Réservation), ni le moteur de contrôle d'accès (L3), que ce module **consomme**.

## 2. Périmètre
- **Inclus :**
  - **Fiche employé** : identité, poste, type de contrat, date d'entrée/sortie, statut, lien optionnel
    vers un compte **Utilisateur** (socle) pour ceux qui utilisent le logiciel — US-PERSO-01.
  - **Rattachement multi-établissement** : un employé peut être rattaché à plusieurs Établissements,
    avec un poste local éventuellement différent par site — US-PERSO-01/10.
  - **Qualifications** : type (MNS, BNSSA, BEESAN, BAFA, BPJEPS…), date de validité/recyclage, statut
    dérivé (valide/expirée) — US-PERSO-02.
  - **Créneaux de travail (shifts)** : plage horaire, établissement/espace/activité concernés, libellé
    de poste, qualification exigée le cas échéant, effectif requis — US-PERSO-03.
  - **Affectation** d'un ou plusieurs employés à un créneau, détection de **conflit** (double
    affectation d'un même employé sur des créneaux chevauchants, y compris **entre établissements**)
    — US-PERSO-03/10.
  - **Vue roster hebdomadaire** (grille jour/semaine par établissement, par employé, par poste) — écran
    de restitution, pas un objet de données propre — US-PERSO-04.
  - **Absences légères** (congé, maladie, formation, autre) : déclaration, validation, **blocage des
    affectations** sur la période, **alerte de couverture** si l'absence retire le seul qualifié
    couvrant un créneau exigeant une qualification — US-PERSO-05.
  - **Badge staff** = un **Support (L3)** rattaché à l'employé + un **DroitAcces (L3)** portant la
    **portée** (quels espaces) et le **mode horaire** (accès pendant les shifts uniquement, ou
    permanent selon le rôle) — US-PERSO-06/07.
  - **Révocation d'accès** à la sortie (fin de contrat), à la suspension, ou en cas de perte/vol du
    badge — réutilise le mécanisme générique L3 (blocage serveur immédiat + liste de révocation) —
    US-PERSO-08.
  - **Exigence de qualification pour la surveillance POSS** : réconciliation avec `RG-PISC-02` — un
    créneau de surveillance bassin ne peut être couvert que par un employé qualifié (MNS/BNSSA) à
    diplôme valide — US-PERSO-09.
  - **Réconciliation** : `EncadrantMns` (piscine, `spec-piscine.md` §3/§5) et le **Coach** (padel,
    `spec-padel.md` §3) deviennent des **spécialisations de l'Employé** de ce module (poste = « MNS »,
    « coach padel »…), et leurs qualifications/plannings sont désormais portés ici (voir §4.9).
- **Exclu (pour l'instant), que ce module *référence* seulement :**
  - **Paie, solde de congés légal, arrêts maladie CPAM, éléments variables de paie** — **délégués à un
    SIRH externe** (décision actée du cahier, panel `p-m5` : « Gestion RH des plannings agents (paie,
    congés) — SIRH externe »). L'**Absence** de ce module est un objet **léger** de blocage
    planning/accès, **pas** un système de gestion des congés légaux ; elle ne calcule ni solde ni
    indemnité.
  - **Réservation des ressources par les clients** (terrains, lignes d'eau, salles…) → **M5
    Réservation** (`spec-reservation.md`). Ce module gère le planning **interne** (RH) des employés,
    modèle **distinct** de la réservation client, même si les deux se **recoupent** ponctuellement
    (un créneau de surveillance bassin correspond à une fenêtre de `Créneau` réservable, voir §4.9).
  - **Contrôle d'accès physique** (validation au tourniquet, jauge FMI, anti-passback, hors-ligne) →
    **L3 Accès** (`spec-acces.md`). Ce module **crée** le Support et le DroitAcces d'un employé, ne
    réimplémente pas le moteur de validation au passage.
  - **Recrutement, entretiens, dossiers administratifs complets** (contrats signés, bulletins de
    salaire, DPAE) — hors périmètre logiciel de billetterie/accès, relève d'un SIRH.
  - **UI (front)** ; **authentification, rôles/permissions, journal d'audit** → **socle L0**
    (`spec-socle.md`), réutilisés et non redéfinis ici.

## 3. Acteurs & droits
Les permissions réutilisent le modèle du socle (`RG-SOCLE-02/03/04/05`) : couple `module × action` sur
le module **`personnel`**, portées par l'**établissement actif** (un employé multi-site peut être géré
sur plusieurs établissements selon les affectations de l'utilisateur gestionnaire, `RG-SOCLE-05`) ;
l'UI **masque** ce qui n'est pas autorisé (`RG-SOCLE-04`). La gestion du **badge** réutilise en outre
les permissions `acces × appairer` / `acces × bloquer_support` du module `acces` (`spec-acces.md` §3).

| Acteur | Peut | Ne peut pas | Permission (module × action) |
|---|---|---|---|
| **Administrateur RH / Responsable établissement** | Créer/modifier une fiche employé, ses rattachements multi-site, ses qualifications ; déclencher l'émission ou la révocation d'un badge staff ; forcer une révocation immédiate | Modifier la paie (hors périmètre, SIRH externe) | `personnel × gerer_employe`, `personnel × gerer_qualification`, `personnel × gerer_badge` |
| **Responsable planning / Chef d'équipe** | Créer des créneaux de travail, affecter/désaffecter des employés, détecter et résoudre les conflits, valider une absence, consulter le roster hebdomadaire | Créer une fiche employé, éditer une qualification, gérer un badge | `personnel × gerer_planning`, `personnel × valider_absence`, `personnel × lire` |
| **Employé (soi-même)** | Consulter son planning, ses qualifications et leur validité, son badge et son périmètre d'accès ; déclarer une demande d'absence | Modifier le planning d'un autre employé, valider sa propre absence, modifier sa qualification | `personnel × lire_soi`, `personnel × declarer_absence_soi` |
| **Agent d'accueil (délégation ponctuelle)** | Signaler un badge staff perdu/volé (réutilise `acces × bloquer_support`) | Créer/modifier un employé, une qualification, un créneau | `acces × bloquer_support` (réutilisée L3) |
| **Système** | Calculer le statut d'une qualification (valide/expirée) à la date courante, bloquer une affectation sur un créneau exigeant une qualification sans employé valide couvrant, bloquer une affectation en conflit (chevauchement), recalculer la fenêtre de validité d'un badge en mode « shifts uniquement », révoquer un badge à la date de sortie, propager la révocation à la liste embarquée L3 | Autoriser une affectation en conflit ou une couverture de surveillance sans qualification valide | *(acteur technique — pas de permission humaine)* |
| **Lecture seule** | Consulter le référentiel employés/qualifications, le roster, le statut des badges | Toute action d'écriture | `personnel × lire` |

- ⚠ HYPOTHÈSE — Les noms de permissions `personnel × …` ne sont **nommés nulle part** dans les sources
  (le cahier n'ayant pas de panel dédié « Personnel ») ; découpage **dérivé par analogie** avec les
  modules `acces` (L3) et `reservation` (M5), **à arbitrer avec M8**.
- ⚠ HYPOTHÈSE — Un **Employé n'a pas obligatoirement de compte Utilisateur (socle)** : un agent
  d'entretien qui n'utilise jamais le logiciel peut avoir une fiche Employé et un badge d'accès sans
  jamais se connecter. Seuls les employés qui **opèrent le logiciel** (caisse, supervision accès,
  émargement) ont un `utilisateurRef` renseigné (voir §5, résout l'hypothèse ouverte de
  `spec-piscine.md` §3 sur le statut socle de l'Encadrant, tranchée ici).

## 4. Comportements & règles

### 4.1 Fiche employé & rattachement multi-établissement (US-PERSO-01/10)
- **RG-PERSO-01** — Un **Employé** porte une identité, un **poste**, un **type de contrat**, une
  **date d'entrée**, un **statut** (`actif`/`suspendu`/`sorti`) et, optionnellement, une **date de
  sortie**. Il peut être **lié à un compte Utilisateur** (socle) s'il opère le logiciel ; sinon la
  fiche reste **autonome** (identité propre, sans connexion possible).
- **RG-PERSO-09 (multi-site)** — Un Employé peut être **rattaché à plusieurs Établissements** via des
  **RattachementEmploye** distincts, chacun portant un **poste local** éventuel (ex. « MNS » sur le
  site A, « agent d'accueil » sur le site B) et sa propre période d'effet. Les **droits logiciels**
  (s'il existe un Utilisateur) suivent le modèle socle **indépendamment** du rattachement RH
  (`RG-SOCLE-03` : Affectation utilisateur↔rôle↔établissement) — les deux notions **ne se confondent
  pas** : le rattachement RH ouvre l'éligibilité au planning/badge sur le site, l'Affectation socle
  ouvre l'accès **au logiciel** sur ce site.
- Un employé **sans aucun rattachement actif** ne peut être affecté à aucun créneau ni détenir de badge
  actif (cohérent `RG-SOCLE-05`).

### 4.2 Qualifications & validité (US-PERSO-02)
- **RG-PERSO-02** — Une **Qualification** porte un **type** (MNS, BNSSA, BEESAN, BAFA, BPJEPS, autre —
  liste **ouverte et paramétrable**, ⚠ HYPOTHÈSE : non chiffrée dans les sources, cahier M5-04 ne cite
  que MNS/BNSSA/éducateur sportif comme exemples) et une **date de validité** (date de recyclage/
  expiration). Une qualification dont la date de validité est **dépassée** est **automatiquement
  considérée expirée** ; l'employé **n'est plus proposé** pour toute exigence qui la requiert (reprend
  et généralise `RG-PISC-02` et `RG-M5-05`).
- Une même qualification (même type) peut être **renouvelée** (nouvelle date de validité) sans perdre
  l'historique des recyclages précédents — ⚠ HYPOTHÈSE : historisation retenue par cohérence avec
  l'audit socle (`RG-SOCLE-07`), non détaillée dans les sources.
- ⚠ HYPOTHÈSE — Une **alerte de recyclage à échéance proche** (ex. J-30 avant expiration) n'est **pas
  spécifiée** dans les sources ; retenue comme comportement souhaitable mais **non tranché** (délai,
  canal de notification) — à confirmer avec l'exploitant.

### 4.3 Créneaux de travail — shifts (US-PERSO-03)
- **RG-PERSO-03** — Un **CreneauTravail** définit une plage horaire (début < fin) rattachée à un
  **Établissement** et, le cas échéant, un **Espace**/une **activité** ; il porte un **libellé de
  poste** (ex. « Surveillance bassin 1 », « Accueil caisse matin », « Coach cours collectif 18h »), un
  **effectif requis** (défaut 1) et, optionnellement, une **qualification exigée**.
- Le CreneauTravail peut être **récurrent** (motif hebdomadaire + fin, exceptions par occurrence) —
  même mécanique généralisable que `RG-M5-07` (récurrence), appliquée ici au planning RH plutôt qu'à
  la réservation client.
- Une **topologie incohérente** (créneau sans établissement, fin ≤ début, effectif requis ≤ 0) est
  **refusée à l'enregistrement**.

### 4.4 Affectation & détection de conflit (US-PERSO-03/10)
- **RG-PERSO-04** — Une **AffectationTravail** lie un **Employé** à un **CreneauTravail**. Un même
  Employé ne peut être affecté à **deux créneaux chevauchants**, **y compris sur des établissements
  différents** (l'employé est la ressource contrainte, généralisation de `RG-M5-03` appliquée à
  l'humain plutôt qu'à un terrain/bassin) : le **conflit est bloqué à l'affectation**, avec le motif
  affiché.
- Si le CreneauTravail porte une **qualification exigée**, l'affectation n'est possible **que si**
  l'Employé détient une **Qualification valide** de ce type à la date du créneau (voir §4.9) ; sinon
  l'affectation est **refusée**.
- Un CreneauTravail dont l'**effectif requis n'est pas atteint** reste visible comme **« sous-couvert »**
  au roster — ⚠ HYPOTHÈSE : le comportement (blocage strict de l'ouverture d'activité vs simple alerte
  visuelle) dépend du contexte métier ; pour la surveillance bassin, `RG-PISC-02` impose le **blocage
  strict de l'ouverture** (§4.9) ; pour un poste d'accueil non réglementé, une **alerte non bloquante**
  est retenue par défaut — à confirmer par type de poste.

### 4.5 Roster hebdomadaire (US-PERSO-04)
- Le **roster** est une **vue de restitution** (grille jour/semaine/liste, filtrable par établissement,
  espace, poste, employé) agrégeant les `CreneauTravail` et leurs `AffectationTravail` — **pas un objet
  de données propre**, comme le calendrier générique de `spec-reservation.md` §2. Il affiche pour
  chaque créneau : poste, horaire, employé(s) affecté(s), statut de couverture (complet / sous-couvert
  / conflit), et signale visuellement une **qualification manquante ou expirée**.

### 4.6 Absences légères (US-PERSO-05)
- **RG-PERSO-05** — Une **Absence** (congé, maladie, formation, autre) porte une période (début < fin),
  un **type**, et un **statut** (`declaree` → `validee`/`refusee`). Une absence **validée** **bloque
  toute nouvelle affectation** de l'employé sur sa période.
- Une absence validée qui **chevauche une AffectationTravail déjà confirmée** déclenche une **alerte de
  couverture** (§7) plutôt qu'une annulation automatique — ⚠ HYPOTHÈSE : la conduite (proposition de
  remplacement automatique par un employé qualifié disponible, par analogie avec la décision actée M5
  « Encadrant absent → remplacement automatique, sinon annulation + notification », cf.
  `spec-piscine.md` §4.4, `spec-padel.md` §7) est **retenue par défaut mais non confirmée** pour ce
  module générique.
- **RG-PERSO-10 (hors périmètre paie/congés)** — Cette Absence **ne calcule ni solde de congés légal ni
  paie** ; elle n'est qu'un **objet de blocage planning et, le cas échéant, d'accès** (voir §4.8). La
  gestion légale des congés reste **déléguée au SIRH externe** (décision actée, cahier §`p-m5`).

### 4.7 Badge staff = Support + DroitAcces, résolu par le moteur L3 (US-PERSO-06/07)
- **RG-PERSO-06** — Un **Badge staff** rattache un **Employé actif** à un **Support** (L3, `type`
  QR/RFID/badge physique, `spec-acces.md` §5) et à un **DroitAcces** (L3) qui porte la **portée**
  (liste d'`EspaceAccès` autorisés — potentiellement sur **plusieurs établissements** pour un employé
  multi-site, §4.1) et le **mode horaire** :
  - **`shifts_uniquement`** — le DroitAcces n'est **valide que pendant les fenêtres des
    `CreneauTravail` planifiés/confirmés** de l'employé (+ une **marge configurable**, ex. ±15 min) ;
  - **`permanent`** — le DroitAcces reste **ouvert en continu** sur la durée du contrat (rôles à accès
    élargi : responsable, sécurité, technicien astreinte…).
- **RG-PERSO-07 (moteur commun)** — Le badge staff est **lu et validé au tourniquet par le même moteur
  d'accès** que les clients (`RG-ACC-01`) : mêmes contrôles (existence/validité du droit, marges,
  anti-passback), même journal des passages (`RG-ACC-06`), même comportement hors-ligne (`RG-ACC-05`).
  Un passage d'employé n'introduit **aucun chemin de validation parallèle**.
- Un **Support déjà appairé et actif** ne peut être ré-affecté à un autre employé **sans révocation
  préalable** (réutilise l'unicité `RG-ACC` de `spec-acces.md` §4.2) : **un badge = un employé**.
- ⚠ HYPOTHÈSE — Le badge staff **incrémente-t-il la jauge FMI/POSS** au même titre qu'un client ? Non
  tranché par les sources ; retenu par défaut que **oui** (présence physique réelle, cohérent avec la
  décision actée « bébés & accompagnants comptés », `RG-PISC-05`) — **à confirmer**, car un dépassement
  de seuil ERP pourrait alors être partiellement dû au personnel.

### 4.8 Révocation d'accès (US-PERSO-08)
- **RG-PERSO-08** — La **révocation** d'un badge staff suit le mécanisme générique **support perdu/
  volé** de L3 (`RG-ACC-07`, `spec-acces.md` §4.7) : **blocage serveur immédiat**, **ajout à la liste de
  révocation** embarquée, **propagation à la prochaine synchro** des contrôleurs, **traçabilité**
  (motif, auteur, horodatage).
- **Déclencheurs de révocation** :
  1. **Fin de contrat** — à la `dateSortie` de l'Employé (ou immédiatement si saisie rétroactivement),
     le badge passe **automatiquement** à `révoqué`.
  2. **Suspension** — un Employé passé au statut `suspendu` voit son badge **suspendu** (même mécanique
     que la révocation, réversible sans ré-appairage si la suspension est levée) — ⚠ HYPOTHÈSE :
     distinction suspension (réversible) / révocation (nécessite ré-émission d'un nouveau badge) non
     tranchée par les sources, retenue par analogie avec le statut `actif`/`bloqué` du `Support` L3.
  3. **Perte/vol du badge** — déclaration par un rôle habilité, réutilise **telle quelle** `RG-ACC-07`.
  4. **Révocation manuelle** par un Administrateur RH (ex. faute grave), tracée et motivée.
- Une révocation **survenant pendant un créneau en cours** coupe l'accès **immédiatement** (§7, cas
  limite) — le CreneauTravail/AffectationTravail en cours **n'est pas annulé automatiquement**, seul
  l'accès physique est coupé ; la gestion RH de l'incident (fin de mission anticipée) reste manuelle.

### 4.9 Exigence de qualification pour la surveillance POSS — réconciliation piscine (US-PERSO-09)
- **Réconciliation avec `RG-PISC-02`** (`spec-piscine.md` §4.4) — cette règle, portée jusqu'ici de
  façon **provisoire** par la verticale piscine (`QualificationEncadrant`/`AffectationEncadrant`
  référençant directement un `Utilisateur` socle), est désormais **portée par ce module** : un créneau
  bassin exigeant un encadrant qualifié (MNS/BNSSA) **ne peut être ouvert à la baignade** que si au
  moins un `AffectationTravail` couvrant ce créneau référence un **Employé** (poste = MNS/BNSSA) dont la
  **Qualification** correspondante est **valide** à la date du créneau.
- **Lien optionnel CreneauTravail ↔ Créneau (Réservation)** — quand l'activité correspond à un
  `Créneau` réservable côté client (`spec-reservation.md` §4.2, ex. une séance aquagym), le
  `CreneauTravail` de l'encadrant peut porter une référence optionnelle vers ce `Créneau` (champ
  `creneauReservationRef`) : le planning RH et la réservation client **restent deux objets distincts**
  (planning interne vs capacité vendable) mais **partagent la même fenêtre horaire** et se **valident
  mutuellement** — l'ouverture du `Créneau` de réservation reste **bloquée** tant qu'aucun
  `AffectationTravail` qualifié ne couvre la fenêtre (reprend le blocage `RG-PISC-02`).
- **La `Ressource` générique de réservation** (`spec-reservation.md` §4.1, attribut
  `compétenceExigée`) qui représente un encadrant/coach comme ressource bookable **référence
  désormais l'Employé** (via sa Qualification) plutôt qu'un `Utilisateur` nu : la vérification de
  `compétenceExigée` **délègue** à ce module pour statuer sur la validité de la qualification.
- **Réconciliation Coach padel** — le rôle **Coach** (`spec-padel.md` §3/§4.8) est désormais un
  **Employé** de ce module dont le **poste** est « coach padel » ; sa disponibilité en tant que
  ressource réservable (non-chevauchement des réservations « avec coach ») reste gérée côté
  `spec-reservation.md`, mais son **existence, son contrat et son éventuelle qualification** (ex.
  BPJEPS activités de raquette) sont désormais portés **ici**. Le comportement « coach indisponible »
  (§7 `spec-padel.md`) peut désormais s'appuyer sur une **Absence** déclarée dans ce module.

## 5. Objets de données
Tout objet est rattaché à un **Établissement/Espace** via le socle (`RG-SOCLE-01`) ; identifiants =
**UUID** (constitution §3). Les objets `Support`, `DroitAcces`, `EspaceAccès`, `ListeRévocation`
(L3), `Ressource`/`Créneau` (Réservation) sont **référencés, non redéfinis** — voir `spec-acces.md` §5,
`spec-reservation.md` §5.

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| **Employe** | id | uuid | PK | RG-PERSO-01 |
| | utilisateurRef | ref Utilisateur (socle)? | optionnel | lien logiciel, non systématique (§3) |
| | nom, prenom | string | requis | identité propre (indépendante d'Utilisateur) |
| | matricule | string? | optionnel, unique si renseigné | identifiant interne RH |
| | poste | string | requis | ex. « MNS », « agent d'accueil », « coach padel » |
| | typeContrat | enum {CDI, CDD, vacataire, saisonnier, stagiaire, prestataire} | requis | ⚠ HYPOTHÈSE liste non chiffrée, extensible |
| | dateEntree | date | requis | — |
| | dateSortie | date? | optionnel | déclenche révocation badge (§4.8) |
| | statut | enum {actif, suspendu, sorti} | défaut = actif | RG-PERSO-01 |
| **RattachementEmploye** | id | uuid | PK | RG-PERSO-09 |
| | employeRef | ref Employe | requis | — |
| | etablissementRef | ref Etablissement (socle) | requis | multi-site |
| | posteLocal | string? | optionnel | surcharge du poste sur ce site |
| | debut, fin | date, date? | debut requis | période d'effet |
| **Qualification** | id | uuid | PK | RG-PERSO-02 |
| | employeRef | ref Employe | requis | — |
| | type | enum {MNS, BNSSA, BEESAN, BAFA, BPJEPS, autre} | requis | ⚠ HYPOTHÈSE liste ouverte |
| | libelle | string? | requis si type=autre | — |
| | dateObtention | date? | optionnel | historique |
| | dateValidite | date | requis | expiration/recyclage |
| | statut | enum {valide, expiree} | dérivé de dateValidite | recalculé à la date courante |
| **CreneauTravail** | id | uuid | PK | RG-PERSO-03 |
| | etablissementRef | ref Etablissement | requis | — |
| | espaceRef | ref Espace (socle)? | optionnel | zone concernée |
| | creneauReservationRef | ref Créneau (Réservation)? | optionnel | lien fenêtre client, §4.9 |
| | libellePoste | string | requis | ex. « Surveillance bassin 1 » |
| | debut, fin | datetime | debut < fin | — |
| | qualificationRequise | enum {MNS, BNSSA, aucune, …}? | optionnel | RG-PERSO-04/08, RG-PISC-02 |
| | effectifRequis | int ≥ 1 | défaut = 1 | — |
| | statut | enum {planifie, confirme, realise, annule} | défaut = planifie | — |
| | recurrence | {motif, fin}? | optionnel | généralisation RG-M5-07 |
| **AffectationTravail** | id | uuid | PK | RG-PERSO-04 |
| | creneauTravailRef | ref CreneauTravail | requis | — |
| | employeRef | ref Employe | requis | pas de chevauchement (RG-PERSO-04) |
| | qualificationUtiliseeRef | ref Qualification? | requis si créneau qualifiant | traçabilité couverture |
| | statut | enum {planifiee, confirmee, realisee, absente_remplacee, annulee} | défaut = planifiee | — |
| **Absence** | id | uuid | PK | RG-PERSO-05 |
| | employeRef | ref Employe | requis | — |
| | debut, fin | datetime | debut < fin | — |
| | type | enum {conge, maladie, formation, autre} | requis | pas de calcul de solde (RG-PERSO-10) |
| | statut | enum {declaree, validee, refusee} | défaut = declaree | — |
| | valideePar | ref Utilisateur? | requis si validee/refusee | — |
| | motif | string? | optionnel | — |
| **BadgeStaff** | id | uuid | PK | RG-PERSO-06 |
| | employeRef | ref Employe | requis, unique badge actif par employé | 1 badge actif à la fois |
| | supportRef | ref Support (L3) | requis | Support générique (QR/RFID/badge) |
| | droitAccesRef | ref DroitAcces (L3, type étendu = personnel) | requis | ⚠ extension coordonnée avec L3 |
| | statut | dérivé de Support.statut | actif/bloqué | RG-ACC-07 |
| | dateEmission | datetime | requis | — |
| | dateRevocation | datetime? | requis si révoqué | RG-PERSO-08 |
| | motifRevocation | string? | requis si révoqué | fin contrat / suspension / perte-vol / manuel |
| **PorteeAccesEmploye** | id | uuid | PK | RG-PERSO-06/07 |
| | badgeStaffRef | ref BadgeStaff | requis, 1-1 | — |
| | espacesAutorises[] | ref EspaceAccès (L3)[] | ≥ 1 | portée, multi-établissement possible |
| | modeHoraire | enum {shifts_uniquement, permanent} | requis | §4.7 |
| | margeAvantApres | duration? | requis si shifts_uniquement | tolérance autour du shift |
| **DeclarationIncidentBadge** | id, badgeStaffRef | uuid, ref | requis | réutilise `DéclarationPerteVol` L3 |
| | motif, agent, horodatage | string, ref Utilisateur, datetime | requis | tracée, réversible (RG-ACC-07) |

## 6. Critères d'acceptation
- **CA-1 (US-PERSO-01)** — *Étant donné* un poste requis, *quand* un Administrateur RH crée un Employé
  sans compte Utilisateur associé, *alors* la fiche est créée et **utilisable pour le planning et le
  badge**, sans possibilité de connexion au logiciel.
- **CA-2 (US-PERSO-10, RG-PERSO-09)** — *Étant donné* un Employé rattaché à deux établissements, *quand*
  on consulte son planning, *alors* les créneaux **des deux sites** apparaissent, chacun avec son poste
  local éventuel.
- **CA-3 (US-PERSO-02, RG-PERSO-02)** — *Étant donné* une Qualification dont la `dateValidite` est
  dépassée, *quand* le système évalue son statut, *alors* elle apparaît **`expiree`** et l'employé
  **n'est plus proposé** pour un créneau qui l'exige.
- **CA-4 (US-PERSO-03, RG-PERSO-04)** — *Étant donné* un Employé déjà affecté à un créneau chevauchant
  (même établissement ou établissement différent), *quand* on tente une nouvelle affectation
  chevauchante, *alors* elle est **refusée** avec le motif de conflit affiché.
- **CA-5 (US-PERSO-03, RG-PERSO-04)** — *Étant donné* un CreneauTravail portant une
  `qualificationRequise`, *quand* on tente d'affecter un employé **sans qualification valide** de ce
  type, *alors* l'affectation est **refusée**.
- **CA-6 (US-PERSO-04)** — *Étant donné* le roster hebdomadaire, *quand* on le consulte, *alors* chaque
  créneau affiche son **statut de couverture** (complet/sous-couvert/conflit) et signale visuellement
  une **qualification manquante ou expirée**.
- **CA-7 (US-PERSO-05, RG-PERSO-05)** — *Étant donné* une Absence **validée** sur une période, *quand*
  on tente d'affecter l'employé sur cette période, *alors* l'affectation est **refusée** ; *quand*
  l'absence chevauche une affectation déjà confirmée, *alors* une **alerte de couverture** est levée.
- **CA-8 (US-PERSO-06/07, RG-PERSO-06)** — *Étant donné* un Employé actif, *quand* un badge staff lui
  est émis avec une portée d'espaces et un mode horaire `shifts_uniquement`, *alors* le DroitAcces n'est
  **valide que pendant les fenêtres de ses CreneauTravail** (± marge) ; en mode `permanent`, il reste
  **valide en continu** sur la durée du contrat.
- **CA-9 (US-PERSO-07, RG-PERSO-07)** — *Étant donné* un badge staff valide, *quand* l'employé se
  présente à un tourniquet dans sa portée et sa fenêtre horaire, *alors* le passage est **validé par le
  même moteur** que pour un client (marges, anti-passback, journal — `RG-ACC-01/06`) ; hors portée ou
  hors fenêtre, il est **refusé**.
- **CA-10 (US-PERSO-08, RG-PERSO-08)** — *Étant donné* un Employé dont la `dateSortie` est atteinte,
  *quand* le système traite l'échéance, *alors* son badge staff passe **immédiatement** à `révoqué`, la
  révocation est **propagée à la liste embarquée** des contrôleurs, et l'action est **tracée**.
- **CA-11 (US-PERSO-08, RG-PERSO-08)** — *Étant donné* un badge staff déclaré **perdu/volé**, *alors* le
  blocage est **immédiat côté serveur**, refusé **y compris hors-ligne** après propagation, et la
  déclaration est **réversible** par un rôle habilité (réutilise `RG-ACC-07`).
- **CA-12 (US-PERSO-09, RG-PISC-02 réconciliée)** — *Étant donné* un CreneauTravail « surveillance
  bassin » sans aucun `AffectationTravail` couvrant avec une Qualification MNS/BNSSA **valide**, *quand*
  on tente d'ouvrir le créneau/la séance correspondante à la baignade, *alors* l'ouverture est
  **bloquée**, avec le motif « aucun encadrant qualifié affecté ».

## 7. Cas limites
- **Employé multi-site avec créneaux simultanés sur deux établissements** — Conflit **bloqué** à
  l'affectation (RG-PERSO-04), quel que soit l'établissement (§4.4).
- **Badge perdu** — Réutilise **telle quelle** la procédure L3 (`RG-ACC-07`) : blocage serveur immédiat,
  liste de révocation, réversibilité tracée (§4.8).
- **Qualification expirée bloquant la surveillance** — Le créneau reste **sous-couvert** au roster
  (§4.5) ; l'ouverture d'une activité bassin qui l'exige est **bloquée** (§4.9, RG-PISC-02 réconciliée) ;
  ⚠ HYPOTHÈSE : pas d'alerte automatique de recyclage à échéance proche (§4.2), à confirmer.
  Une qualification qui expire **le jour même d'un créneau déjà affecté** rend l'affectation
  **invalide rétroactivement au moment de l'évaluation** (le créneau bascule « sous-couvert » avant
  son ouverture) — ⚠ HYPOTHÈSE : le comportement exact (notification proactive à J-1 ? blocage
  seulement à l'instant T de l'ouverture ?) n'est pas tranché.
- **Fin de contrat en plein créneau en cours** — L'accès est coupé **immédiatement** à la révocation
  (§4.8) même si l'AffectationTravail est encore `confirmee`/`realisee` ; le traitement RH de
  l'incident reste **manuel**.
- **Employé sans compte Utilisateur détenteur d'un badge** — Cas nominal explicitement supporté (§3,
  §4.1) : le badge fonctionne indépendamment de tout accès logiciel.
- **Badge staff partagé entre plusieurs employés** — **Interdit** : unicité 1 badge actif = 1 employé
  (réutilise l'unicité `Support` L3, §4.7).
- **Créneau sous-couvert sur un poste non réglementé** (ex. accueil) — Alerte **non bloquante** par
  défaut, contrairement à la surveillance bassin (§4.4) — ⚠ HYPOTHÈSE à confirmer par type de poste.
- **Absence chevauchant une affectation déjà confirmée** — Alerte de couverture, **pas d'annulation
  automatique** ; ⚠ HYPOTHÈSE de remplacement automatique par analogie M5/piscine/padel non confirmée
  (§4.6).
- **Suspension vs révocation** — ⚠ HYPOTHÈSE : distinction (réversibilité sans ré-appairage) non
  tranchée par les sources (§4.8).
- **Badge staff comptabilisé dans la jauge FMI/POSS** — ⚠ HYPOTHÈSE retenue par défaut (oui), non
  tranchée par les sources ; impact potentiel sur un dépassement de seuil ERP (§4.7).
- **Coach padel indisponible** — Peut désormais s'appuyer sur une `Absence` de ce module plutôt que sur
  l'hypothèse générique M5 « encadrant absent » (§4.9), résolvant partiellement le point ouvert
  `spec-padel.md` §7/§12.

## 8. Dépendances
- **Dépend de : socle L0** (`specs/L0-socle/spec-socle.md`) — hiérarchie Groupe/Région/Établissement/
  Espace (`RG-SOCLE-01`) à laquelle se rattachent Employé, RattachementEmploye et CreneauTravail ;
  permissions `module × action` réutilisées sur le module `personnel` (`RG-SOCLE-02/03/04`) ; cadrage
  par établissement actif (`RG-SOCLE-05`) ; lien optionnel vers **Utilisateur** ; **journal d'audit
  append-only** (`RG-SOCLE-07`) pour toute révocation/suspension.
- **Dépend de : L3 Accès** (`specs/L3-acces/spec-acces.md`) — le **Badge staff** est une
  **spécialisation** du `Support` générique et s'appuie sur son `DroitAcces` (§4.7) ; le passage
  d'employé est validé par le **même moteur** (`RG-ACC-01/02/06`) ; la révocation réutilise **telle
  quelle** le mécanisme perdu/volé (`RG-ACC-07`, `ListeRévocation`) et le fonctionnement hors-ligne
  (`RG-ACC-05`). ⚠ **Extension coordonnée** — le type de `DroitAcces` (`spec-acces.md` §5, enum
  `{billet, abonnement, carte_quota}`) doit être **étendu** avec une valeur `personnel` pour porter la
  source `BadgeStaff`/`Employe` : **point de coordination à traiter avec la spec L3** avant
  implémentation.
- **Dépend de : Réservation (M5)** (`specs/reservation/spec-reservation.md`) — la `Ressource` générique
  représentant un encadrant/coach (attribut `compétenceExigée`, RG-M5-05 généralisée) **référence
  désormais l'Employé** de ce module pour la vérification de qualification (§4.9) ; le
  `CreneauTravail` (RH) peut porter un lien optionnel vers le `Créneau` (client) sur la même fenêtre,
  **sans fusionner les deux modèles**.
- **Dépend de / réconcilie : L6 Piscine** (`specs/L6-piscine/spec-piscine.md`) — `RG-PISC-02` (encadrant
  qualifié requis) est désormais **implémentée par ce module** (§4.9) ; les objets provisoires
  `QualificationEncadrant`/`AffectationEncadrant` (`spec-piscine.md` §5) sont **supersédés** par
  `Qualification`/`AffectationTravail` et **doivent être mis à jour** dans une prochaine révision de
  `spec-piscine.md` pour référencer ce module au lieu d'un `Utilisateur` socle nu (résout l'hypothèse
  ouverte `spec-piscine.md` §3/point 6).
- **Dépend de / réconcilie : Padel** (`specs/padel/spec-padel.md`) — le rôle **Coach** (§3/§4.8) est
  désormais un **Employé** de ce module (poste « coach padel ») ; sa disponibilité comme ressource
  réservable reste gérée par `spec-reservation.md`, son existence RH et ses absences par ce module
  (résout partiellement le point ouvert `spec-padel.md` §7/§12).
- **Référencé, non redéfini :** M2 Vente & Caisse pour l'appairage initial d'un support en caisse (si
  un badge staff est émis au comptoir plutôt qu'en back-office RH) — `spec-vente.md`, non détaillé ici.

---

## Points ouverts / hypothèses (récapitulatif)
1. **⚠ NUMÉROTATION `US-PERSO` HORS BACKLOG** : ces stories n'existent pas dans `backlog.html` et
   doivent être **validées et intégrées officiellement** avant développement (en-tête, §1).
2. **⚠ HYPOTHÈSE — Lien Employé ↔ Utilisateur optionnel** : tranche l'hypothèse ouverte de
   `spec-piscine.md` §3 (statut socle de l'Encadrant) — retenu qu'un Employé peut exister **sans**
   compte logiciel (§3, §4.1).
3. **⚠ HYPOTHÈSE — Liste des types de contrat et des types de qualification** : non chiffrées dans les
   sources, retenues comme énumérations **ouvertes et paramétrables** (§4.1, §4.2, §5).
4. **⚠ HYPOTHÈSE — Alerte de recyclage à échéance proche** : non spécifiée (délai, canal) (§4.2, §7).
5. **⚠ HYPOTHÈSE — Remplacement automatique en cas d'absence couvrant un créneau confirmé** : retenu
   par analogie avec la décision M5/piscine/padel « encadrant absent », **non confirmé** pour ce module
   générique (§4.6, §7).
6. **⚠ HYPOTHÈSE — Blocage strict vs alerte non bloquante en sous-effectif** : blocage strict retenu
   uniquement pour la surveillance réglementée (RG-PISC-02) ; alerte non bloquante par défaut ailleurs,
   **à confirmer par type de poste** (§4.4, §7).
7. **⚠ POINT DE COORDINATION AVEC L3 — Extension de `DroitAcces.type`** : ajout de la valeur
   `personnel` non présente dans `spec-acces.md` §5 ; **à traiter conjointement** avant implémentation
   (§8).
8. **⚠ GAP DE MISE À JOUR — `spec-piscine.md`** : les objets provisoires `QualificationEncadrant`/
   `AffectationEncadrant` doivent être **repointés** vers ce module dans une prochaine révision (§8).
9. **⚠ HYPOTHÈSE — Comptage du personnel dans la jauge FMI/POSS** : retenu par défaut « oui » (présence
   physique réelle), **non tranché** par les sources — impact sécurité ERP à confirmer (§4.7, §7).
10. **⚠ HYPOTHÈSE — Distinction suspension (réversible) / révocation (définitive)** : retenue par
    analogie avec `Support.statut` L3, **non détaillée** dans les sources (§4.8, §7).
11. **⚠ HYPOTHÈSE — Noms des permissions `personnel × …`** : dérivées par analogie avec `acces`/
    `reservation`, à **arbitrer avec M8** (§3).
