# Spec — Verticale Piscine / Centre aquatique (`Piscine` / lot `L6`)

- **Lot / module :** L6 · Verticale Piscine (paramétrage + fonctions propres au milieu aquatique
  au-dessus du socle **M1–M8** déjà spécifié)
- **Stories couvertes :** US-L6-01 à US-L6-10 (backlog `backlog.html`, panel `p-l6`, 55 pts)
- **Règles de gestion :** RG-PISC-01 à RG-PISC-05 (cahier `cahier-detaille.html`, panel `p-piscine`)
  + décisions actées ★ « 🏊 Piscine » (Dépassement POSS, Lignes d'eau public/club, Casier non rendu,
  Bébés & accompagnants) + règles socle réutilisées (RG-ACC-03/04 de `spec-acces.md`, RG-M1-03/04/13
  de `spec-offre.md`)
- **Statut :** brouillon

## 1. Objectif
Configurer le socle **M1–M8** pour un établissement aquatique et couvrir ses spécificités
**réglementaires** (Fréquentation Maximale Instantanée / capacité POSS des bassins),
**organisationnelles** (encadrement qualifié MNS/BNSSA, lignes d'eau, créneaux mutualisés
grand public/scolaire/club) et **matérielles** (casiers connectés, bracelet RFID étanche) — sans
réinventer ce que couvrent déjà M1 (offre), M2 (vente/caisse), L3 (contrôle d'accès), M6 (compta/PCA)
et M4 (CRM/familles). Principe directeur du cahier : *« si un besoin piscine est couvrable par le
socle, il n'apparaît pas ici »*.

## 2. Périmètre
- **Inclus :**
  - Modèle de catalogue « piscine type » (entrées, carte 10=12, abonnements Gold/Classique, cours) —
    US-L6-01.
  - Application concrète de la jauge FMI du socle Accès (L3) comme **POSS réglementaire** : blocage
    d'entrée au seuil + règle **« une sortie = une entrée »** — US-L6-02, RG-PISC-01.
  - **Pré-alerte** à X % du seuil FMI et **priorité** aux réservations/abonnés — US-L6-03.
  - Exigence d'**encadrant qualifié** (MNS/BNSSA) par activité/créneau bassin — US-L6-04, RG-PISC-02.
  - **Lignes d'eau** et **capacité par bassin**, distinctes de la FMI globale — US-L6-05.
  - **Créneaux publics multiples** (grand public/scolaire/club) sur lignes distinctes d'un même
    bassin — US-L6-06, RG-PISC-03.
  - **Jauge grand public au prorata** des lignes occupées par un club/scolaire — US-L6-07.
  - **Bébés & accompagnants comptés dans la FMI** même sans droit — US-L6-08, RG-PISC-05 (tel que
    précisé par la décision actée).
  - **Casiers connectés** : attribution/libération au bracelet, caution, relance, forçage
    administratif — US-L6-09.
  - **Bracelet RFID étanche** comme support d'accès/casier en milieu humide — US-L6-10, RG-PISC-04.
- **Exclu (pour l'instant), que le module *référence* seulement :**
  - La **définition des produits/tarifs/formules** eux-mêmes (types, grille, quotient familial, PCA
    portée par le produit) → **M1** (`spec-offre.md`, RG-M1-01 à 13). L6 **instancie un modèle** au-dessus
    de M1 (US-L6-01) ; il ne redéfinit pas le moteur de catalogue.
  - Le **mécanisme générique de jauge FMI, passage, appairage support, anti-passback,
    hors-ligne/synchro, journal des passages** → **L3 Accès** (`spec-acces.md`, RG-ACC-01 à 07). L6
    **concrétise** ces mécanismes pour l'usage piscine (POSS, prorata lignes, bébés) ; il ne les
    redéfinit pas.
  - La **planification des séances/activités, la disponibilité des encadrants, la génération de
    créneaux récurrents** (calendrier, liste d'attente, émargement) → **M5 Planning & Réservation**
    (référencé par le cahier §3, RG-M5-01 à 07). ⚠ **HYPOTHÈSE** — aucune spec dédiée `spec-planning.md`
    n'existe encore dans le dépôt (M5 n'a pas de lot L*/dédié dans l'ordre de construction) ; L6
    modélise donc en §5 les objets **spécifiques piscine** (Bassin, LigneEau, CréneauBassin) comme
    une **extension provisoire** au-dessus des concepts génériques du cahier (RG-M5-03/05/06/07),
    à **réconcilier avec le futur module M5** dès sa spécification.
  - La **reconnaissance comptable** (PCA, TVA, régie) → **M6** (`spec-compta.md`). L6 **hérite** de la
    règle PCA portée par les produits du modèle piscine (RG-M1-08) sans l'exécuter.
  - Les **fiches familles/bénéficiaires**, autorisations, PMV → **M4/CRM** (`spec-crm.md`). L6
    **consomme** le bénéficiaire porteur du droit/bracelet sans redéfinir la famille.
  - Le **contrôle des douches** (relais, durée par point de douche) : présent au cahier comme carte
    « option » et objet `Douche`, mais **non couvert par une user story L6** du backlog actuel → **hors
    périmètre de cette spec** ; objet référencé pour cohérence matérielle du bracelet (§5) uniquement.
  - L'**UI (front)**, l'**authentification, les rôles/permissions, le journal d'audit** → **socle L0**
    (`spec-socle.md`), réutilisés et non redéfinis.

## 3. Acteurs & droits
Les permissions réutilisent le modèle du socle (`RG-SOCLE-02/03/04/05`) : couple `module × action`,
portées par l'**établissement/espace actif**. La verticale introduit le module **`piscine`** pour ses
objets propres (bassins, lignes d'eau, casiers, encadrants qualifiés) et **réutilise** les modules
**`offre`** (catalogue, `spec-offre.md`) et **`acces`** (FMI, passages, `spec-acces.md`) sans les
redéfinir. Source : cahier §1 (« Rôles ») et §2 (panel `p-piscine`).

| Acteur | Peut | Ne peut pas | Permission (module × action) |
|---|---|---|---|
| **Gestionnaire d'offre** | Instancier/éditer le modèle « piscine type » (US-L6-01, réutilise `offre`) ; configurer bassins, lignes d'eau, seuils POSS, prorata, exigence de qualification par activité | Superviser la FMI en temps réel ; forcer un casier | `offre × creer`, `offre × modifier` (réutilisées L1), `piscine × configurer` |
| **Agent d'accueil / caisse** | Superviser le tableau de bord FMI/pré-alerte (US-L6-03) ; enregistrer bébé/accompagnant non nominatif (réutilise `acces`) ; attribuer/libérer un casier, encaisser/libérer la caution, relancer un casier en retard | Configurer bassins/lignes/POSS ; affecter un encadrant qualifié | `acces × superviser`, `acces × appairer` (réutilisées L3), `piscine × gerer_casier` |
| **Encadrant (MNS/BNSSA)** | Consulter son affectation de créneau, son diplôme et sa date de validité ; contrôler en mobilité (réutilise `acces × controler`) | Modifier le paramétrage POSS/lignes ; gérer les casiers | `piscine × lire`, `acces × controler` (réutilisée L3) |
| **Administrateur / Responsable technique** | Tout ce qui précède + **forcer l'ouverture** d'un casier non rendu (action journalisée) + gérer le référentiel qualifications (MNS/BNSSA, validité) | — | `piscine × gerer` (surensemble), `piscine × forcer_casier`, `securite × gerer` (délégation, socle) |
| **Système** | Calculer la jauge grand public au prorata des lignes club (US-L6-07) ; bloquer/débloquer le tripode au seuil POSS (réutilise le moteur `acces`) ; incrémenter/décrémenter la FMI à chaque passage, y compris non nominatif | Autoriser une entrée au-delà du seuil hors règle « une sortie = une entrée » | *(acteur technique — pas de permission humaine)* |
| **Lecture seule** | Consulter le tableau de bord POSS/FMI, le plan des bassins, l'état des casiers | Toute action d'écriture | `piscine × lire`, `acces × lire` |

- ⚠ HYPOTHÈSE — Les noms de permissions `piscine × configurer / gerer_casier / forcer_casier / lire /
  gerer` déclinent le tableau ci-dessus selon le modèle `module × action` du socle ; ils ne sont **pas
  nommés littéralement** dans les sources (le cahier ne détaille pas le module `piscine` en droits
  fins). Découpage à **arbitrer avec M8** (comme signalé pour `acces` en L3, `spec-acces.md` §3).
- ⚠ HYPOTHÈSE — Le rôle **Encadrant (MNS/BNSSA)** est-il un utilisateur du socle avec affectation
  établissement (comme un agent), ou un profil RH distinct géré par M5 « Ressources & encadrants »
  (cahier M5-04) ? La spec retient l'hypothèse **utilisateur socle avec une affectation** dont le
  **diplôme et sa validité** sont portés par un objet `QualificationEncadrant` (§5), cohérent avec
  US-L6-04 (« qualifications gérées par agent »).

## 4. Comportements & règles
Chaque comportement trace une **RG-PISC** (source : `cahier-detaille.html`, panel `p-piscine`), une
**décision actée** (★, panel « 🏊 Piscine ») et/ou une **US-L6** (source : `backlog.html`, panel
`p-l6`). Les décisions actées **font foi** et ne sont pas re-tranchées (constitution §6).

### 4.1 Catalogue « piscine type » (US-L6-01)
- Un modèle génère en un clic les produits **entrée unitaire adulte/enfant**, **carte 10 entrées**
  (bonus « 10=12 » paramétrable, RG-M1-04/13), **abonnement Gold** (accès illimité + 1 aquagym/semaine
  incluse) et **abonnement Classique** (accès bassin seul), et des **cours** (aquagym, aquabike,
  aquazumba, leçons) — US-L6-01, cahier §1.
- Chaque produit généré reste **entièrement éditable** (tarif, TVA, validité, quotas) **sans casser le
  modèle** source (US-L6-01) : le modèle est un **gabarit d'instanciation**, pas un lien vivant figé.
- La **carte 10** décrémente un **stock de compostages** à chaque passage (RG-M1-04/13, réutilisé de
  M1) ; l'**abonnement** contrôle une **période de validité** (RG-M1-03).
- Le catalogue généré est **publiable et achetable** en caisse (M2) et en ligne (M3), sans étape
  supplémentaire propre à L6 (US-L6-01).
- ⚠ HYPOTHÈSE — Le **contenu exact** du modèle « piscine type » (tarifs par défaut, TVA 20 %, quotas
  Gold, nombre de cours inclus) n'est pas chiffré dans les sources ; retenu comme **gabarit
  paramétrable par établissement à l'instanciation**, non figé en dur (cohérent constitution §4.4
  « aucune logique métier codée en dur »).

### 4.2 FMI/POSS — blocage & règle « une sortie = une entrée » (US-L6-02, RG-PISC-01)
- **RG-PISC-01** — La **capacité d'accueil réglementaire (POSS)** est traduite en un **seuil de
  présents simultanés** par établissement et/ou par bassin. Dès que le nombre de présents atteint ce
  seuil, **le tripode refuse toute nouvelle entrée** ; la **réouverture est automatique** dès qu'une
  sortie fait repasser sous le seuil.
- Ceci est une **application concrète** de la jauge FMI générique de L3 (`RG-ACC-04`, `spec-acces.md`
  §4.5) : le compteur (entrées − sorties) et le mode « blocage au seuil » **existent déjà** dans le
  moteur Accès ; L6 impose que le **mode POSS soit toujours en blocage strict** (pas d'alerte simple)
  pour un espace/bassin piscine soumis à la réglementation ERP — contrairement au mode générique L3 où
  blocage/alerte est **paramétrable par espace**.
- **Décision actée « Dépassement POSS »** — La régulation suit la règle **« une sortie = une entrée »**
  : une place se libère **exactement** à chaque sortie validée ; complétée par une **pré-alerte à X %**
  et une **priorité aux réservations/abonnés** (voir §4.3).
- Le **seuil FMI/POSS** est **paramétrable par établissement** (et par bassin, cf. §4.5), et toute
  modification du seuil est **journalisée** (qui, quand, valeur) — US-L6-02.
- La granularité est double : une **FMI globale établissement** (POSS bâtiment) et, si l'établissement
  le configure, une **FMI/capacité par bassin** distincte (voir §4.5) — cahier §4 « POSS/FMI » :
  « périmètre (établissement/bassin) ».

### 4.3 Pré-alerte à X % & priorité réservations (US-L6-03)
- Un **seuil de pré-alerte** (X %, ex. 80 % du POSS) est **paramétrable** et déclenche un **signal
  visible** au poste d'accueil/supervision dès qu'il est franchi — US-L6-03, décision actée.
- Les **places correspondant aux réservations à venir** (créneaux scolaires/clubs — §4.6 — et, par
  extension, réservations grand public si le produit le prévoit) sont **réservées et non attribuables**
  aux entrées libres, même sous le seuil de pré-alerte — US-L6-03, décision actée « priorité
  réservations/abonnés ».
- Le tableau de bord affiche en temps réel : **présents / FMI (POSS) / places réservées restantes**
  — US-L6-03.
- ⚠ HYPOTHÈSE — La **priorité « abonnés »** (Gold notamment) mentionnée par la décision actée n'est pas
  détaillée au-delà du principe : retenue comme **file d'attente non prioritaire pour les entrées
  libres au-delà de X %**, les abonnés Gold n'étant *pas* automatiquement admis au-delà du seuil POSS
  strict (RG-PISC-01 reste absolu pour la sécurité) — **le mécanisme concret de priorisation
  (réservation préalable obligatoire au-delà de X % ? file dédiée au guichet ?) est à préciser avec
  l'exploitant.**

### 4.4 Encadrant qualifié requis selon activité (US-L6-04, RG-PISC-02)
- **RG-PISC-02** — Toute **activité en bassin exige un encadrant qualifié MNS** (ou équivalent
  surveillance, ex. BNSSA) **affecté et à diplôme valide** ; un créneau bassin **sans qualifié couvrant
  ne peut être ouvert à la baignade**.
- Chaque **activité/créneau** porte une **exigence de qualification** (MNS, BNSSA, ou aucune) —
  US-L6-04.
- L'**ouverture ou la validation** d'un créneau est **bloquée** si aucun encadrant qualifié n'est
  affecté — US-L6-04.
- Les **qualifications et leur date de validité** sont gérées par agent ; une **qualification expirée
  n'est plus prise en compte** (l'encadrant n'est plus proposé pour l'activité qui l'exige, cahier
  M5-04) — US-L6-04.
- ⚠ HYPOTHÈSE — Cette règle s'appuie sur les concepts génériques M5 (compétence/disponibilité/conflit,
  cahier §3 « M5-04 ») qui n'ont pas encore de spec dédiée dans le dépôt (voir §2 Exclu). L6 retient le
  **comportement observable** (blocage à l'ouverture sans qualifié couvrant) comme règle propre à la
  verticale piscine, à **réconcilier** avec la future spec M5.

### 4.5 Lignes d'eau & capacité par bassin (US-L6-05)
- L'établissement se **décompose en bassins**, chacun avec un **nombre de lignes** et une **capacité
  propre** — US-L6-05, RG-PISC (bassins/lignes).
- Une **réservation** (créneau scolaire/club) peut **bloquer une ou plusieurs lignes d'eau** sur un
  créneau donné — US-L6-05.
- Le **compteur de capacité par bassin** est **distinct** du compteur de séance (M5) et de la **FMI
  globale établissement** (§4.2) — US-L6-05, décision actée M5 « Lignes d'eau partagées → compteur de
  capacité par bassin en plus de la capacité par séance ».
- Une **réservation dépassant les lignes ou la capacité disponibles** est **refusée** — US-L6-05.

### 4.6 Créneaux publics multiples sur lignes distinctes (US-L6-06, RG-PISC-03)
- **RG-PISC-03** — Un **créneau réservé** (scolaire ou club) **préempte une part de la jauge FMI et/ou
  des lignes d'eau** : ces lignes et cette capacité sont **retirées du disponible grand public**
  pendant le créneau.
- Un **même créneau horaire** peut porter **plusieurs publics** (grand public, scolaire, club) affectés
  chacun à des **lignes précises** — US-L6-06, décision actée M5 « Créneaux réservés à un public :
  plusieurs créneaux sur lignes d'eau distinctes du même bassin ».
- Une **ligne ne peut être affectée qu'à un seul public à un instant donné** (pas de chevauchement) —
  US-L6-06.
- **Chaque public a sa propre jauge**, indépendante des autres publics du créneau — US-L6-06.

### 4.7 Jauge grand public au prorata des lignes club (US-L6-07)
- La **jauge grand public** d'un créneau se **recalcule automatiquement** dès qu'un club/scolaire
  **réserve ou libère** des lignes du bassin — US-L6-07, décision actée « Jauge grand public réduite au
  prorata des lignes occupées ».
- Le **prorata** suit une règle **paramétrable** (exemple donné par le backlog : *capacité restante =
  capacité × lignes libres / lignes totales*) — US-L6-07.
- La **jauge recalculée s'applique en temps réel** à la **vente en ligne** (M3) et au **contrôle
  d'accès** (L3) — US-L6-07.
- ⚠ HYPOTHÈSE — La **formule de prorata par défaut** (linéaire sur le nombre de lignes) est
  **l'hypothèse de référence** ; le backlog évoque explicitement une alternative possible (prorata de
  **surface** ou **forfait paramétré**, cahier §5 « cas limites ») — la spec retient le **prorata
  linéaire par lignes comme réglage par défaut**, **paramétrable par établissement** pour les deux
  autres modes, à confirmer avec l'exploitant.

### 4.8 Bébés & accompagnants comptés dans la FMI (US-L6-08, RG-PISC-05)
- **Décision actée « Bébés & accompagnants »** — Les bébés et accompagnants sont **comptés dans la
  FMI** (présence physique réelle), **même sans droit** (billet payant ou gratuit non consommé). Cette
  décision **fait foi** et **prévaut** sur toute mention contraire dans les sources (voir contradiction
  documentée ci-dessous).
- **RG-PISC-05** (texte source du cahier) — Un bébé sans support (accompagné) **n'est pas décompté du
  droit d'entrée** de l'accompagnant (gratuité non nominative). La formulation d'origine indiquait la
  prise en compte FMI comme *« paramétrable par établissement »* : la **décision actée** ci-dessus
  **lève ce paramétrage** — le comptage FMI des bébés/accompagnants est **systématique**, non optionnel.
- Cette règle **réutilise** le mécanisme générique **non nominatif** de L3 (`RG-ACC-03`,
  `spec-acces.md` §4.4 : bouton agent « +1 », motif tracé) : L6 **confirme** que ce comptage
  **incrémente/décrémente la FMI** au même titre qu'un passage nominatif — US-L6-08.
- Chaque catégorie (bébé, accompagnant) est **enregistrée à l'entrée même si gratuite**, et **chaque
  sortie décrémente le compteur** au même titre qu'un entrant payant — US-L6-08.
- ⚠ **Contradiction documentée dans les sources** — La carte « FMI / POSS réglementaire » du cahier §2
  (panel `p-piscine`) indique : *« Compteur présents : entrées − sorties temps réel (**hors** bébés
  sans support) »*, ce qui **contredit littéralement** RG-PISC-05, US-L6-08 et la décision actée ★. La
  spec retient la **décision actée** (source de vérité prioritaire, constitution §6) : **les bébés/
  accompagnants sont comptés**. **Le texte de la carte cahier §2 doit être corrigé lors d'une prochaine
  mise à jour du cahier** pour lever l'ambiguïté.

### 4.9 Casiers connectés — caution, relance, forçage (US-L6-09)
- Un **bracelet** peut être **lié à un casier** : le même support ouvre l'accès (tripode) **et** le
  casier attribué — US-L6-09, cahier §2 « Casiers connectés ».
- État du casier : **libre / occupé / non rendu** — cahier §4, objet `Casier`.
- Une **caution est encaissée à l'attribution** (bracelet + casier) et **libérée à la restitution** —
  US-L6-09, décision actée « Casier non rendu → caution consignée ».
- Un **casier non restitué** après le créneau/la journée déclenche une **relance** et bascule l'état à
  **« en retard »**, suivi à l'accueil — US-L6-09, décision actée.
- **Après un délai** (paramétrable par établissement), un **agent habilité peut forcer l'ouverture**
  du casier ; l'action est **journalisée** (agent, motif, horodatage) — US-L6-09, décision actée
  « forçage administratif après délai ».
- ⚠ HYPOTHÈSE — Le **délai précis** avant forçage, le **montant de la caution** (forfait unique ou
  grille selon zone/type de casier) et la **conduite en cas de casier forcé avec objets à l'intérieur**
  (procès-verbal, dépôt en régie des objets, restitution à réclamation) ne sont **pas chiffrés** dans
  les sources (cahier §5 : *« quel processus et sous quel délai »* reste une question ouverte
  partiellement tranchée par la décision — le **principe** est acté, le **paramétrage exact** ne l'est
  pas). **À définir avec l'exploitant** avant configuration.
- ⚠ HYPOTHÈSE — Le **mode d'encaissement/libération de la caution** (moyen de paiement dédié,
  empreinte CB, dépôt espèces, rattachement au porte-monnaie virtuel M4) n'est pas précisé ; retenu
  comme un **mouvement de caisse/régie dédié** cohérent avec le motif « caution » déjà utilisé pour la
  patinoire (cahier §5 patinoire : grille de retenue + trace comptable en régie), **à harmoniser avec
  M2/M6** (mécanisme de caution générique potentiellement mutualisable entre verticales — non
  actuellement spécifié en L2/L4).

### 4.10 Bracelet RFID étanche (US-L6-10, RG-PISC-04)
- **RG-PISC-04** — Le **bracelet RFID étanche remplace le smartphone** comme support au bord du
  bassin : **accès, casier et douche** s'opèrent au bracelet ; **aucun support mobile** n'est requis ni
  supporté en zone humide.
- Le bracelet est une **spécialisation** du `Support` générique de L3 (`spec-acces.md` §5, type =
  `RFID`) : il est **encodé et rattaché à un titre/client** puis **lu au contrôle d'accès** comme tout
  support RFID — US-L6-10.
- La **lecture RFID** fonctionne aux **points d'accès et casiers** avec un **temps de réponse
  acceptable en usage réel** — US-L6-10 (cohérent avec l'exigence générique L3 « < 1 s en conditions
  nominales », `RG-ACC-01`, US-L3-03).
- Le bracelet peut être **désactivé/réattribué** et son **historique de rattachement est tracé**,
  au même titre que tout `Support` (réutilise `Appairage`, L3 §5) — US-L6-10.
- Les **rôles** portés par le bracelet — entrée tripode, casier, douche, aquagym incluse — sont un
  **paramétrage des droits rattachés**, non une propriété physique distincte du support — cahier §2.

## 5. Objets de données
Les types PHP sont indicatifs (spec = comportement observable). Tout objet est rattaché à un
**Établissement/Espace** via le socle (`RG-SOCLE-01`) ; identifiants = **UUID** (constitution §3). Les
objets M1 (Produit, Formule, CarteMultiEntrées), L3 (`EspaceAccès`, `JaugeFmi`, `Support`, `Passage`,
`Appairage`) et M4 (`Famille`, `Beneficiaire`) sont **référencés, non redéfinis** — voir `spec-offre.md`
§5, `spec-acces.md` §5, `spec-crm.md` §5.

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| **Poss** | id | uuid | PK | POSS = capacité d'accueil réglementaire (RG-PISC-01) |
| | perimetre | enum {etablissement, bassin} | requis | cahier §4 |
| | espaceRef | ref EspaceAccès (L3) | requis | seuil FMI porté par l'espace, spécialisé POSS ici |
| | bassinRef | ref Bassin? | requis si perimetre=bassin | granularité bassin (§4.2/4.5) |
| | seuilPresents | int > 0 | requis | valeur POSS saisie (cahier §4) |
| | baseReglementaire | string? | optionnel | référence/texte réglementaire, ⚠ HYPOTHÈSE non détaillée |
| | modeSeuil | enum {blocage} | figé « blocage » | RG-PISC-01 impose le blocage strict (vs L3 générique blocage/alerte) |
| **ParamPreAlerte** | id | uuid | PK | US-L6-03 |
| | possRef | ref Poss | requis | 1 Poss ↔ 0..1 ParamPreAlerte |
| | seuilPourcent | int (1–99) | requis, paramétrable | pré-alerte à X % (défaut ⚠ HYPOTHÈSE non chiffré) |
| | reservationsProtegees | bool | défaut true | décision actée « priorité réservations » |
| **Bassin** | id, nom | uuid, string | requis | rattaché à un Espace (socle) |
| | nbLignes | int > 0 | requis | US-L6-05 |
| | capacite | int > 0 | requis | distincte de la FMI établissement (§4.5) |
| | occupationCourante | int ≥ 0 | dérivé | compteur de capacité par bassin (US-L6-05) |
| **LigneEau** | id, numero | uuid, int | requis, unique par bassin | US-L6-05, cahier §4 |
| | bassinRef | ref Bassin | requis | — |
| | etat | enum {publique, reservee} | requis | cahier §4 |
| | creneauRef | ref CreneauBassin? | requis si reservee | — |
| **CreneauBassin** | id | uuid | PK | ⚠ extension provisoire, dérivée de RG-M5-03/06/07 (§2) |
| | bassinRef | ref Bassin | requis | — |
| | debut, fin | datetime | début < fin | — |
| | encadrantRequis | enum {MNS, BNSSA, aucune} | requis | RG-PISC-02, US-L6-04 |
| **CreneauPublic** | id | uuid | PK | US-L6-06 |
| | creneauBassinRef | ref CreneauBassin | requis | plusieurs publics par créneau |
| | typePublic | enum {grand_public, scolaire, club} | requis | US-L6-06 |
| | lignesAffectees[] | ref LigneEau[] | ≥ 1, sans chevauchement | RG-PISC-03, US-L6-06 |
| | jauge | int ≥ 0 | propre au public | indépendante des autres publics du créneau |
| **JaugeGrandPublicCalculee** | creneauBassinRef | ref CreneauBassin | 1-1 | US-L6-07 |
| | capaciteRestante | int ≥ 0 | dérivé, recalculé en temps réel | formule prorata (§4.7, ⚠ HYPOTHÈSE défaut linéaire) |
| | modeProrata | enum {lignes, surface, forfait} | défaut = lignes | paramétrable par établissement |
| **QualificationEncadrant** | id | uuid | PK | US-L6-04 |
| | encadrantRef | ref Utilisateur (socle) | requis | ⚠ HYPOTHÈSE, voir §3 |
| | type | enum {MNS, BNSSA, autre} | requis | cahier M5-04 |
| | dateValidite | date | requis | expirée = non prise en compte |
| **AffectationEncadrant** | id | uuid | PK | US-L6-04 |
| | creneauBassinRef | ref CreneauBassin | requis | — |
| | encadrantRef | ref QualificationEncadrant | requis, valide à la date du créneau | bloque l'ouverture sinon |
| **Casier** | id, numero | uuid, int | requis, unique par zone | cahier §4, US-L6-09 |
| | zone | string | requis | ex. vestiaire homme/femme |
| | etat | enum {libre, occupe, non_rendu} | défaut = libre | cahier §4 |
| | braceletRef | ref Support (L3, type=RFID)? | requis si occupe/non_rendu | US-L6-09 |
| **CautionCasier** | id | uuid | PK | décision actée « caution consignée » |
| | casierRef | ref Casier | requis | 1-1 à l'attribution |
| | montant | decimal ≥ 0 | requis, paramétrable | ⚠ HYPOTHÈSE montant non chiffré (§4.9) |
| | statut | enum {encaissee, liberee, retenue} | défaut = encaissee | retenue si forçage (§4.9) |
| **RelanceCasier** | id, casierRef | uuid, ref | requis | US-L6-09 |
| | dateRelance | datetime | horodaté | — |
| | delaiForcageJours | int > 0 | paramétrable | ⚠ HYPOTHÈSE valeur non chiffrée |
| **ForcageCasier** | id, casierRef | uuid, ref | requis | US-L6-09 |
| | agent, motif, horodatage | ref Utilisateur, string, datetime | requis | journalisé (RG-SOCLE-07) |
| **Douche** *(référencée, hors périmètre)* | numeroPoint, dureeParametree, relais, etat | — | option, cahier §2/§4 | non couverte par une US-L6 (§2 Exclu) |
| **BraceletÉtanche** *(spécialisation de Support, L3)* | uidRfid | string | unique | RG-PISC-04 |
| | titreOuAboRef | ref DroitAccès (L3)/Beneficiaire (M4) | requis | rattachement usager |
| | roles | set {acces, casier, douche} | ≥ 1 | cahier §2 |
| | etat | enum {actif, bloqué} | hérité de `Support` (L3) | §4.10 |

## 6. Critères d'acceptation
- **CA-1 (US-L6-01)** — *Étant donné* un établissement piscine à mettre en vente, *quand* le
  gestionnaire d'offre instancie le modèle « piscine type », *alors* les produits **entrée
  adulte/enfant, carte 10 (bonus paramétrable), abonnement Gold, abonnement Classique et cours** sont
  créés en un clic, restent **individuellement éditables** sans casser le modèle, et le catalogue est
  **publiable et achetable** en caisse et en ligne sans étape supplémentaire.
- **CA-2 (US-L6-02, RG-PISC-01)** — *Étant donné* un établissement/bassin dont le **seuil POSS** est
  atteint, *quand* un nouveau passage d'entrée est présenté, *alors* le tripode **refuse** l'entrée
  avec un message explicite ; *quand* une sortie est validée, *alors* **exactement une place** se
  libère (« une sortie = une entrée ») et une nouvelle entrée redevient possible ; le seuil est
  **paramétrable par établissement/bassin** et toute modification est **journalisée** (qui, quand,
  valeur).
- **CA-3 (US-L6-03)** — *Étant donné* un seuil de pré-alerte X % **paramétré**, *quand* la
  fréquentation l'atteint, *alors* un **signal visible** apparaît au poste d'accueil/supervision ; les
  **places réservées** (créneaux scolaires/clubs) restent **non attribuables** aux entrées libres ; le
  tableau de bord affiche **présents / FMI / places réservées restantes** en temps réel.
- **CA-4 (US-L6-04, RG-PISC-02)** — *Étant donné* un créneau bassin nécessitant un **encadrant qualifié**
  (MNS ou BNSSA), *quand* aucun encadrant à **diplôme valide** n'est affecté, *alors* l'**ouverture ou
  la validation** du créneau est **bloquée** ; *quand* la date de validité d'une qualification est
  dépassée, *alors* l'encadrant **n'est plus proposé** pour cette activité.
- **CA-5 (US-L6-05)** — *Étant donné* un bassin décomposé en **lignes** avec une **capacité propre**,
  *quand* une réservation demande des lignes ou une capacité **au-delà du disponible**, *alors* elle
  est **refusée** ; le **compteur de capacité du bassin** reste **distinct** du compteur de séance (M5)
  et de la **FMI globale** de l'établissement.
- **CA-6 (US-L6-06, RG-PISC-03)** — *Étant donné* un créneau horaire portant **plusieurs publics** (grand
  public, scolaire, club), *quand* chacun est affecté à des **lignes précises**, *alors* **aucune ligne
  n'est affectée à deux publics simultanément** (refus au chevauchement) et **chaque public dispose
  d'une jauge indépendante** des autres.
- **CA-7 (US-L6-07)** — *Étant donné* un club qui **réserve ou libère des lignes** sur un bassin,
  *quand* l'opération est confirmée, *alors* la **jauge grand public se recalcule automatiquement**
  selon la règle de prorata paramétrée, et la **capacité recalculée s'applique immédiatement** à la
  vente en ligne et au contrôle d'accès.
- **CA-8 (US-L6-08, RG-PISC-05, décision actée)** — *Étant donné* un bébé ou un accompagnant **sans
  droit payant**, *quand* l'agent enregistre son entrée en **comptage non nominatif**, *alors* la
  **FMI est incrémentée** (présence physique réelle) **sans décompte de crédit** ; *quand* il sort,
  *alors* la **FMI est décrémentée** au même titre qu'un entrant payant ; ce comptage est
  **systématique**, non paramétrable établissement par établissement (la décision actée prévaut sur la
  formulation « paramétrable » de RG-PISC-05 d'origine — voir §4.8).
- **CA-9 (US-L6-09, décision actée « casier non rendu »)** — *Étant donné* un bracelet lié à un
  casier attribué, *quand* l'usager l'attribue, *alors* une **caution est encaissée** ; *quand* il le
  restitue, *alors* la **caution est libérée** et le casier repasse **libre** ; *quand* le casier
  **n'est pas restitué** après le délai paramétré, *alors* une **relance** est déclenchée et l'état
  passe à **« en retard »** ; *quand* le délai de forçage est dépassé, *alors* un **agent habilité**
  peut **forcer l'ouverture**, action **journalisée** (agent, motif, horodatage).
- **CA-10 (US-L6-10, RG-PISC-04)** — *Étant donné* un bracelet RFID étanche **encodé et rattaché** à un
  titre/client, *quand* il est présenté au tripode ou au casier, *alors* il est **lu et validé** comme
  tout `Support` RFID du socle Accès (L3), sans nécessiter de support mobile en zone humide ; *quand*
  il est **désactivé/réattribué**, *alors* son **historique de rattachement est tracé**.

## 7. Cas limites
- **Dépassement POSS en affluence** — Régulé par « une sortie = une entrée » + pré-alerte X % + places
  réservées protégées (décision actée) ; ⚠ le **mécanisme concret de priorisation des abonnés Gold**
  au-delà du seuil de pré-alerte **n'est pas détaillé** (§4.3) — à préciser avec l'exploitant.
- **Lignes d'eau partagées public/club** — Jauge grand public réduite **au prorata** (décision actée) ;
  ⚠ la **formule exacte** (lignes vs surface vs forfait) n'est **pas figée**, retenue par défaut en
  proportion linéaire des lignes (§4.7).
- **Casier non rendu** — Caution consignée + relance + forçage administratif (décision actée) ; ⚠
  **délai précis avant forçage**, **montant de caution** et **conduite si des objets sont trouvés dans
  un casier forcé** (procès-verbal, dépôt en régie) **non chiffrés** (§4.9).
- **Bébés & accompagnants** — Comptés dans la FMI même sans droit (décision actée, prévaut sur la
  formulation « paramétrable » de RG-PISC-05 d'origine) ; ⚠ **contradiction non résolue** avec la carte
  cahier §2 (« hors bébés sans support ») — la décision actée fait foi, le cahier devra être corrigé
  (§4.8).
- **Créneau bassin sans encadrant qualifié** — Ouverture/validation **bloquée** (RG-PISC-02) ; ⚠ la
  **conduite en cas d'absence de dernière minute** de l'encadrant affecté (fermeture du créneau,
  remplacement automatique) est traitée par la **décision actée M5 « Encadrant absent » →
  remplacement automatique proposé, sinon annulation + notification des inscrits** — réutilisée sans
  redéfinition, en attendant la spec M5 dédiée.
- **Bassin sans découpage en lignes (piscine mono-bassin sans ligne d'eau)** — ⚠ **non tranché** : la
  spec suppose qu'un établissement peut déclarer **un bassin à 1 ligne** pour rester cohérent avec le
  modèle §5, mais l'ergonomie d'un établissement sans notion de « ligne » n'est **pas confirmée** par
  les sources.
- **Bracelet RFID perdu/volé au bord du bassin** — Réutilise le blocage serveur immédiat + propagation
  à la prochaine synchro du socle Accès (`RG-ACC-07`, `spec-acces.md` §4.7), sans règle piscine
  spécifique supplémentaire.
- **Carte 10=12 épuisée en cours de séance aquagym incluse Gold** — Réutilise le refus « carte épuisée
  » + proposition de rechargement (`RG-ACC-02`, `spec-acces.md` §4.8) ; l'articulation exacte entre le
  **quota de cours inclus Gold** (M1/M5) et le **crédit de la carte 10** (produit distinct) n'est pas
  détaillée par les sources — ⚠ HYPOTHÈSE : ce sont deux droits indépendants, un usager Gold consommant
  son quota d'aquagym n'entame pas une éventuelle carte 10 détenue séparément.
- **Établissement soumis à une réglementation POSS différente selon le bassin** (ex. bassin
  d'apprentissage vs bassin sportif) — Couvert par le périmètre `Poss.perimetre = bassin` (§5) ; ⚠ la
  **base réglementaire elle-même** (texte, autorité de contrôle, contrôle documentaire) reste hors
  périmètre applicatif — champ `baseReglementaire` informatif seulement.
- **⚠ Point réglementaire ERP/POSS à valider par l'exploitant (priorité haute)** — L'ensemble du §4.2
  (seuils, granularité établissement/bassin, mode « blocage strict » imposé) repose sur la lecture du
  cahier et des décisions actées ; **aucune source du dépôt ne référence le texte réglementaire POSS
  exact** (arrêté ERP type X, commission de sécurité). La configuration effective des seuils devra être
  **validée avec l'exploitant/la commission de sécurité** avant mise en service (constitution §4.5
  « conformité dès la conception »).

## 8. Dépendances
- **Dépend de : socle L0** (`specs/L0-socle/spec-socle.md`) — hiérarchie Groupe/Région/Établissement/
  Espace (`RG-SOCLE-01`) à laquelle se rattachent bassins, lignes d'eau et casiers ; permissions
  `module × action` réutilisées et étendues au module **`piscine`** (`RG-SOCLE-02/03/04`) ; journal
  d'audit append-only (`RG-SOCLE-07`) pour le forçage de casier et les modifications de seuil POSS.
- **Dépend de : M1 · Offre & Tarification** (`specs/L1-offre/spec-offre.md`) — modèle générique de
  **Produit/Formule/CarteMultiEntrées** instancié par le modèle « piscine type » (RG-M1-01 à 13,
  US-L6-01) ; formule Gold/Classique = spécialisation de `Formule` (droits d'accès + services inclus à
  quota, RG-M1-03/12) ; carte 10=12 = spécialisation de `CarteMultiEntrées` (RG-M1-04/13).
- **Dépend de : L3 · Contrôle d'accès** (`specs/L3-acces/spec-acces.md`) — moteur générique de jauge
  FMI (`RG-ACC-04`), validation de passage (`RG-ACC-01/02`), comptage non nominatif (`RG-ACC-03`,
  réutilisé tel quel pour §4.8), hors-ligne/synchro (`RG-ACC-05/06`), support/appairage (`Support`,
  `Appairage`, spécialisés en `BraceletÉtanche`, §4.10). **L6 ne redéfinit aucun mécanisme L3** ; il en
  **paramètre l'usage** (mode blocage strict imposé pour la FMI/POSS, §4.2).
- **Dépend de : M6 · Compta & Régie** (`specs/L4-compta/spec-compta.md`) — reconnaissance PCA des
  produits piscine vendus d'avance (abonnement étalé, carte à la consommation, RG-M1-08 hérité) ;
  encaissement de la **caution casier** en régie (mouvement dédié, §4.9, à harmoniser). L6 n'exécute
  pas la comptabilité, il en **déclenche** les faits générateurs (vente, passage, casier).
- **Dépend de : M4/CRM** (`specs/L5-crm/spec-crm.md`) — bénéficiaire porteur du droit/bracelet au sein
  d'une **Famille** (`RG-M4-02`) ; L6 rattache le bracelet à un `Beneficiaire`, sans redéfinir la
  fiche famille.
- **Dépend de (référencé, non spécifié dans le dépôt) : M5 · Planning & Réservation** — concepts
  génériques de créneau, activité, encadrant/compétence, récurrence (cahier §3, RG-M5-01 à 07) au-dessus
  desquels L6 construit `Bassin`, `LigneEau`, `CreneauBassin` ; **aucune spec `spec-planning.md`
  n'existe encore** dans `specs/` à la date de rédaction (voir §2 Exclu) — **risque de divergence** à
  traiter en priorité lors de la spécification de M5.
- **Interagit avec le matériel IT Cotation** — bracelets RFID étanches lus par les mêmes lecteurs/
  tripodes que le socle Accès (`spec-acces.md` §8) ; ⚠ protocoles matériel déjà signalés comme point
  ouvert en L3, applicables ici sans distinction piscine spécifique.

---

## Points ouverts / hypothèses (récapitulatif)
1. **⚠ RÉGLEMENTATION POSS/ERP — À VALIDER PAR L'EXPLOITANT (priorité haute)** : aucune source du
   dépôt ne référence le texte réglementaire exact (arrêté ERP, commission de sécurité) ; la
   granularité établissement/bassin et le mode « blocage strict imposé » sont une lecture du cahier
   à confirmer avant mise en service (§4.2, §7).
2. **⚠ HYPOTHÈSE — Priorisation des abonnés Gold au-delà du seuil de pré-alerte** : principe acté
   (décision ★), mécanisme concret non détaillé (§4.3, §7).
3. **⚠ HYPOTHÈSE — Formule de prorata de la jauge grand public** : lignes (défaut retenu) vs surface
   vs forfait paramétré, non figée par les sources (§4.7, §7).
4. **⚠ HYPOTHÈSE — Paramétrage exact du casier non rendu** : délai avant forçage, montant de caution,
   conduite si objets trouvés dans un casier forcé — principe acté, chiffrage non tranché (§4.9, §7).
5. **⚠ Contradiction documentée — Comptage FMI des bébés/accompagnants** : la carte cahier §2 dit
   « hors bébés sans support », RG-PISC-05/US-L6-08/décision actée disent l'inverse ; la spec retient
   la décision actée et signale l'incohérence à corriger dans le cahier (§4.8, §7).
6. **⚠ HYPOTHÈSE — Statut socle de l'Encadrant MNS/BNSSA** : utilisateur socle avec affectation
   établissement (retenu) vs profil RH distinct porté par M5 (§3).
7. **⚠ GAP DE DÉPENDANCE — Absence de spec M5 Planning & Réservation** : L6 modélise en §5 des objets
   provisoires (`Bassin`, `LigneEau`, `CreneauBassin`) dérivés des règles génériques du cahier
   (RG-M5-01 à 07) faute de spec dédiée dans le dépôt ; à réconcilier dès que M5 sera spécifié (§2, §8).
8. **⚠ HYPOTHÈSE — Mécanisme de caution générique** : pas de spec transverse (M2/M6) pour la caution/
   dépôt (utilisée aussi en patinoire pour les patins) ; L6 propose un mouvement de caisse/régie dédié,
   à harmoniser transversalement (§4.9).
9. **⚠ HYPOTHÈSE — Contenu chiffré du modèle « piscine type »** : tarifs par défaut, quotas Gold,
   nombre de cours inclus non chiffrés dans les sources, retenus comme paramétrables à l'instanciation
   (§4.1).
10. **⚠ HYPOTHÈSE — Noms des permissions `piscine × …`** : dérivés du tableau Acteurs & droits selon
    le modèle socle, à figer avec M8 comme pour `acces` en L3 (§3).
