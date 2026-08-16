# Spec — Reporting & pilotage multi-niveaux (`M7` / lot `L11`, module `reporting`)

- **Lot / module :** L11 · M7 Reporting & Analyse (multi-niveaux)
- **Stories couvertes :** **US-L11-01 à US-L11-09** — ⚠ **HYPOTHÈSE** : `backlog.html` ne contient
  **aucun onglet `L11`** ni aucune story `US-L11-*` à ce jour (onglets présents : L0→L7 uniquement).
  Ces stories sont donc **définies par cet agent**, à partir du panel `p-m7` du cahier détaillé
  (écrans M7-01 à M7-05, `RG-M7-01` à `RG-M7-08`) et du bloc « M7 · Reporting » de l'onglet
  ★ Décisions actées. **À faire valider et numéroter officiellement** dans le backlog avant
  développement — même situation déjà rencontrée et documentée pour M5 (`specs/reservation/spec-reservation.md`).
- **Règles de gestion :** `RG-M7-01` à `RG-M7-08` (source `cahier-detaille.html`, panel `p-m7`) +
  `RG-REPORT-09` à `RG-REPORT-11` (**nouvelles**, formalisant les 3 décisions actées du bloc
  « M7 · Reporting » qui n'ont pas de numéro `RG-M7` dédié dans le cahier : consolidation multi-régime,
  fuseau/devise, seuil de complétude). Règles socle réutilisées : `RG-SOCLE-01` à `03` (hiérarchie et
  droits, `spec-socle.md`), `RG-M8-01/02/03/04` (permissions, héritage, interface adaptative,
  `spec-backoffice.md`), `RG-ACC-04` (FMI ≠ cumul, `spec-acces.md`), `RG-M6-01` (profil d'exploitant,
  `spec-compta.md`).
- **Statut :** brouillon.

## 1. Objectif
Donner à chaque niveau de la hiérarchie (site, région, direction générale) une **vue de pilotage
unique et cohérente** — tableaux de bord, exploration libre par croisement d'axes et rapports
planifiés — construite sur une **source de données unique** alimentée par les modules producteurs
(vente, accès, compta/régie, réservation, CRM), sans jamais dupliquer ni recalculer divergemment un
indicateur, et sans jamais confondre la **FMI** (sécurité ERP, présence simultanée) avec la
**fréquentation cumulée** (pilotage d'activité).

## 2. Périmètre
- **Inclus :**
  - Dashboard établissement temps réel (CA du jour, entrées, jauge FMI, fond de caisse) — US-L11-01,
    écran M7-01.
  - Dashboard région comparatif (comparaison inter-sites, classements, écarts vs objectifs/n-1,
    drill-down) — US-L11-02, écran M7-02.
  - Dashboard direction générale consolidé (agrégation groupe, tendances/saisonnalité, benchmarks,
    drill-down région → site) — US-L11-03, écran M7-03.
  - Explorateur d'analyses multi-axes (site × activité × produit × catégorie × période × canal, avec
    drill-down) — US-L11-04, écran M7-04.
  - Rapports planifiés (modèle, format, destinataires, périodicité, état actif/suspendu, envoi
    automatique) — US-L11-05, écran M7-05.
  - Cloisonnement strict du périmètre affiché, hérité de M8, jamais redéfini dans M7 — US-L11-06,
    RG-M7-01/07.
  - Distinction permanente FMI / fréquentation cumulée, dans tous les écrans et exports — US-L11-07,
    RG-M7-04.
  - Consolidation multi-régime (régie directe / DSP / groupe privé) avec indicateur de comparabilité
    — US-L11-08, décision actée M7 « profils d'exploitant mixtes ».
  - Mode dégradé sur données manquantes/partielles (marquage explicite, jamais silencieux) —
    US-L11-09, RG-M7-08.
- **Exclu (pour l'instant), que M7 *référence* seulement :**
  - Production des écritures comptables, régie, TVA, RAD/redevances DSP → **M6** (`spec-compta.md`) ;
    M7 **lit** ces agrégats, ne les recalcule pas.
  - Exécution des ventes, encaissement, panier → **M2** (`spec-vente.md`) ; M7 lit le CA encaissé.
  - Validation physique au tourniquet, comptage des passages, jauge FMI temps réel côté équipement →
    **Accès/L3** (`spec-acces.md`) ; M7 lit les mesures FMI/fréquentation déjà produites, n'exploite
    pas directement les événements de passage bruts.
  - Gestion des créneaux, capacité, no-show → **réservation** (`spec-reservation.md`) ; M7 lit le taux
    de remplissage et les no-show déjà qualifiés.
  - Détection des impayés, recouvrement → **sport/recouvrement** (`spec-sport.md`) ; M7 lit les
    indicateurs d'impayés déjà qualifiés.
  - Gestion des droits, des rôles et de l'arbre d'entités → **M8** (`spec-backoffice.md`) ; M7
    **hérite** le périmètre, ne le définit ni ne le modifie (RG-M7-01).
  - Saisie ou correction de données sources : M7 est **lecture / restitution uniquement**.
  - ⚠ HYPOTHÈSE — L'implémentation lira les données des modules producteurs via des **services de
    lecture** (API/lecture seule, éventuellement des vues ou un magasin de restitution
    pré-agrégé) et non par duplication d'écriture ; le choix technique précis (ETL, CQRS, vues
    matérialisées) est **hors périmètre de cette spec fonctionnelle**.

## 3. Acteurs & droits
Module de permission : `reporting`. Le **niveau de dashboard visible** (établissement / région /
groupe) n'est pas une permission distincte : il **découle du niveau de l'entité** à laquelle le rôle
de l'utilisateur est rattaché (RG-M8-02/03) — un rôle rattaché à un Établissement ouvre M7-01, un rôle
rattaché à une Région ouvre M7-02 (+ drill-down M7-01 des sites de la région), un rôle rattaché au
Groupe ouvre M7-03 (+ drill-down complet). Ce comportement **découle directement de RG-M7-01 combinée
à RG-M8-02/03** ; il n'est pas explicité littéralement au cahier M7 mais s'en déduit sans ambiguïté.

| Acteur | Peut | Permission (module × action) |
|---|---|---|
| Directeur de site | Consulter le dashboard temps réel de son établissement (M7-01) ; explorer les axes limités à son site (M7-04) ; recevoir les rapports planifiés de son périmètre | `reporting × lire` (portée = son Établissement) |
| Directeur régional | Comparer ses sites côte à côte (M7-02) ; consulter classements et écarts ; descendre en drill-down au détail d'un site ; planifier des rapports pour sa région | `reporting × lire` (portée = sa Région), `reporting × planifier` |
| Direction générale / DAF | Consulter le consolidé groupe, tendances, saisonnalité, benchmarks (M7-03) ; drill-down jusqu'au site ; planifier tout rapport groupe | `reporting × lire` (portée = Groupe), `reporting × planifier` |
| Administrateur | Configurer indicateurs, modèles de tableaux de bord et axes analytiques ; paramétrer les rapports planifiés et leurs destinataires ; gérer le mode dégradé/complétude | `reporting × configurer`, `reporting × planifier`, `reporting × lire` (surensemble, borné par son périmètre M8) |
| Système | Calculer les mesures depuis la source unique, générer et envoyer un `RapportPlanifié` actif à l'heure prévue, marquer un périmètre « partiel » | *(acteur technique — pas de permission humaine)* |

- **Ne peut pas**, pour tout acteur métier (RG-M7-01, RG-M8-04) : voir un site, une région ou le
  groupe hors de son périmètre hérité de M8 ; modifier une donnée source (vente, écriture comptable,
  passage d'accès) depuis M7 ; contourner le cloisonnement même par l'Explorateur ou un export.
- ⚠ HYPOTHÈSE — Un export manuel ponctuel d'une vue (au-delà des `RapportPlanifié`) est supposé
  couvert par `reporting × lire` (exporter ce qu'on a le droit de voir) plutôt que par une permission
  `exporter` séparée ; non tranché explicitement par le cahier.

## 4. Comportements & règles

### 4.1 Cloisonnement de périmètre (US-L11-06)
- **RG-M7-01** — Chaque utilisateur ne voit que les données de son niveau d'entité ; les droits sont
  **hérités de M8** et **jamais redéfinis** dans M7 (aucune matrice de droits propre à M7).
- Le périmètre effectif d'un `TableauDeBord`, d'une session d'Explorateur ou d'un destinataire de
  `RapportPlanifié` est calculé à partir du rôle actif de l'utilisateur sur l'entité consultée
  (RG-M8-02/03) : l'ensemble des sites descendants de cette entité dans l'arbre Groupe → Région →
  Établissement (→ Espace).
- **RG-M7-07** — Chaque destinataire d'un `RapportPlanifié` ne reçoit que les données **de son propre
  périmètre**, y compris quand le rapport est mutualisé entre plusieurs destinataires de niveaux
  différents (un rapport « région » envoyé à un directeur de site est **restreint à son site** dans
  l'export qu'il reçoit — ⚠ HYPOTHÈSE : le cahier ne précise pas explicitement si un `RapportPlanifié`
  a un périmètre unique appliqué à tous ses destinataires, ou un périmètre calculé **par
  destinataire** ; cette spec retient la seconde option, cohérente avec RG-M7-07 et RG-M7-01).

### 4.2 Source unique & cohérence (US-L11-04)
- **RG-M7-02** — Toutes les mesures proviennent d'une **source de données unique** ; aucun indicateur
  n'est recalculé de façon divergente par écran (dashboard établissement, région, groupe, explorateur
  et rapports planifiés affichent tous la même valeur pour un même triplet indicateur × périmètre ×
  période).
- **RG-M7-05** — Les trois niveaux de dashboard s'appuient sur les **mêmes axes analytiques**,
  garantissant la comparabilité et le drill-down sans rupture d'un niveau à l'autre.

### 4.3 Axes analytiques & agrégation ascendante (US-L11-04)
- **RG-M7-03** — Toute mesure s'agrège de bas en haut sur les axes : **site → région → groupe**, par
  activité et par produit.
- Axes analytiques disponibles dans l'Explorateur (M7-04) : **site** (restreint au périmètre hérité),
  **activité** (piscine, patinoire, sport, padel, musée… selon le référentiel M8/entités), **produit**
  (ticket, abonnement, prestation… — référentiel M1), **catégorie** (famille/catégorie de produit —
  M1), **période** (granularité jour/semaine/mois/année, bornes obligatoires), **canal** (guichet,
  boutique en ligne, app, borne, DSP/régie — ⚠ HYPOTHÈSE : le cahier cite « croisement libre par axes
  site / activité / produit / période » à l'écran M7-04 mais la consigne de cette spec ajoute
  explicitement les axes **catégorie** et **canal** ; ils sont donc traités comme des **extensions
  demandées**, à valider au même titre que les stories US-L11-*).
- **Mesure** obligatoire (CA, entrées, fréquentation cumulée, FMI, taux de remplissage, no-show,
  impayés, fond de caisse…) ; toute combinaison d'axes renvoie une valeur cohérente avec les
  dashboards (RG-M7-02).

### 4.4 FMI ≠ fréquentation cumulée (US-L11-07 — point d'attention majeur)
- **RG-M7-04 — FMI ≠ fréquentation cumulée** — La **FMI** (présence simultanée, sécurité ERP) et la
  **fréquentation cumulée** (pilotage) sont deux mesures distinctes, **toutes deux produites**, **jamais
  confondues** ni dans un libellé, ni dans une formule d'agrégation, ni dans un export.
  - **Fréquentation cumulée** = somme des entrées validées sur la période (compteur qui ne fait
    qu'augmenter), directement agrégeable site → région → groupe par simple somme (RG-M7-03).
  - **FMI** = présence simultanée instantanée (entrées − sorties à un instant t), mesure de sécurité
    par établissement (`spec-acces.md`, RG-ACC-04). L'indicateur restitué en reporting est le
    **« FMI max »** (valeur maximale atteinte sur la période), calculé par le module Accès et **lu**
    par M7 — M7 ne recalcule pas de jauge temps réel (hors périmètre, cf. §2).
  - ⚠ HYPOTHÈSE — **Agrégation de la FMI au-delà du site** : la FMI est une mesure de sécurité
    **intrinsèquement locale à un établissement** (seuil ERP propre à chaque site). Au niveau région
    ou groupe, la « FMI » affichée ne peut donc être qu'une **somme des FMI max par site** ou un
    **classement** (site le plus proche de son seuil), **jamais une présence simultanée réelle
    inter-sites**. Cette spec impose que tout indicateur FMI agrégé au-delà du site porte un libellé
    explicite (« Somme des FMI max des sites », « FMI max — site le plus critique ») pour ne jamais
    laisser croire à une présence simultanée groupe. À confirmer avec la sécurité ERP.

### 4.5 Dashboards par niveau (US-L11-01/02/03)
- **M7-01 — Établissement (temps réel)** : CA du jour (cumul des ventes encaissées M2 depuis
  l'ouverture, rafraîchi en quasi temps réel), entrées (accès validés du jour), jauge FMI (présence
  simultanée vs capacité ERP, alerte visuelle au franchissement de seuil), fond de caisse (solde
  théorique des caisses ouvertes, remonté de M6). Rafraîchissement sans rechargement manuel.
- **M7-02 — Région (comparatif)** : mêmes indicateurs affichés côte à côte pour tous les sites de la
  région ; classements (CA, entrées, taux d'atteinte d'objectif) ; écarts vs objectifs et vs n-1 (en
  valeur et en %, code couleur bon/à surveiller/critique) ; drill-down 1 clic vers M7-01 d'un site.
- **M7-03 — Direction générale (consolidé)** : agrégation ascendante de toutes les régions sur les
  mêmes axes ; tendances et saisonnalité (séries pluriannuelles, effets calendaires) ; benchmarks
  inter-régions/inter-sites (y compris profils d'exploitant différents, cf. §4.7) ; drill-down
  région → site sans changer d'axes.

### 4.6 Rapports planifiés (US-L11-05)
- **RG-M7-06** — Un `RapportPlanifié` **actif** est généré et envoyé automatiquement (au format
  configuré) **sans connexion de l'utilisateur** qui l'a créé ; un rapport **suspendu** n'est pas
  produit.
- Champs configurables : modèle (indicateurs, axes, mise en forme), format (**PDF, CSV ou XLSX** —
  ⚠ HYPOTHÈSE : le cahier cite « Excel ou PDF » à l'écran M7-05 ; le **CSV** est ajouté ici à la
  demande explicite de cette spec comme format d'export supplémentaire, à valider), destinataires
  (liste e-mail, périmètre appliqué par destinataire cf. §4.1), périodicité (quotidienne,
  hebdomadaire, mensuelle… + heure d'envoi), état (actif/suspendu).
- Un rapport planifié référence un `TableauDeBord`/modèle existant ; il n'invente pas de nouveaux
  indicateurs hors du référentiel `Indicateur` (RG-M7-02).

### 4.7 Consolidation multi-régime : régie / DSP / groupe privé (US-L11-08)
- **RG-REPORT-09 (décision actée « profils d'exploitant mixtes »)** — Quand une région ou le groupe
  mélange des sites de profils d'exploitant différents (régie directe / DSP / groupe privé, référentiel
  M8/`Entité.profilExploitant`), la consolidation :
  1. **agrège les indicateurs communs** (CA, fréquentation cumulée, FMI max…) normalement, en
     appliquant l'agrégation ascendante standard (RG-M7-03) ;
  2. porte un **indicateur de comparabilité** (`ConsolidationRegime` / `regimeMixte = vrai`) sur toute
     mesure consolidée regroupant des sites de régimes différents, signalant que le chiffre agrège des
     réalités comptables/contractuelles potentiellement hétérogènes (ex. TVA, RAD/redevance DSP) ;
  3. **isole les spécificités** propres à un régime (ex. compte d'exploitation RAD/redevances DSP,
     état de régie publique) dans des vues dédiées, non fusionnées dans l'agrégat commun.
- Cette règle s'applique en particulier au **CA** : le CA « régie » (encaissement direct, `spec-compta.md`
  §RG-M6) et le CA « DSP » (potentiellement soumis à redevance) sont additionnés dans l'agrégat « CA
  groupe », **avec** l'indicateur de comparabilité activé dès qu'un des deux régimes est présent dans
  le périmètre consolidé.

### 4.8 Fuseau horaire & devise (contexte international)
- **RG-REPORT-10 (décision actée)** — Toute mesure temps réel (notamment FMI) est **horodatée en
  UTC en base** ; l'**affichage** se fait au **fuseau horaire du site** sur un dashboard établissement,
  et au **fuseau du niveau consulté** (ou un fuseau de référence configuré) sur les vues région/groupe,
  avec **conversion explicite** signalée. Les montants sont affichés dans la **devise de
  référence du site** ; toute conversion pour un consolidé multi-devise (⚠ HYPOTHÈSE : cas non
  documenté au-delà de la France métropolitaine à ce jour, cf. RG-M8 « tout hébergé en France ») est
  **hors périmètre MVP**.

### 4.9 Données manquantes / mode dégradé (US-L11-09)
- **RG-M7-08** — En cas de données manquantes d'un site (panne de remontée, site hors-ligne non
  encore resynchronisé, etc.), la consolidation **se poursuit** en **signalant explicitement** le
  périmètre incomplet plutôt qu'en bloquant la vue.
- **RG-REPORT-11 (décision actée « seuil de complétude »)** — Un **seuil de complétude** paramétrable
  (par indicateur et par période) déclenche le marquage **« partiel »** d'une mesure consolidée dès
  qu'un ou plusieurs sites du périmètre n'ont pas remonté leurs données à temps ; ce marquage
  n'est **jamais silencieux** : il est visible sur le dashboard, dans l'Explorateur et dans tout
  `RapportPlanifié` généré sur une période incomplète (badge « données partielles » + liste des sites
  manquants, au minimum comptés).

## 5. Objets de données

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| **Indicateur** | code | string | requis, unique | ex. `CA`, `FREQUENTATION_CUMULEE`, `FMI_MAX`, `TAUX_REMPLISSAGE`, `NO_SHOW`, `IMPAYES`, `FOND_CAISSE` |
| | libelle | string | requis | affiché en UI |
| | unite | string | requis | €, nombre, %, ratio |
| | modeCalcul | string/formule | requis | somme, max, moyenne, ratio — RG-M7-02 |
| | nature | enum {instantané, cumulé} | requis | distingue FMI (instantané) de fréquentation/CA (cumulé) — RG-M7-04 |
| | sourceModule | enum {M2, L3-accès, M6, réservation, sport/recouvrement, M1} | requis | module producteur, jamais recalculé divergemment (RG-M7-02) |
| **AxeAnalytique** | type | enum {site, activite, produit, categorie, periode, canal} | requis | RG-M7-05 (site/activité/produit/période au cahier ; catégorie/canal = extension §4.3) |
| | hierarchie | liste ordonnée | ex. site→région→groupe ; produit→catégorie | structure l'agrégation ascendante (RG-M7-03) |
| | granularites | liste | pour `periode` : jour/semaine/mois/année | requis pour l'axe période |
| **Mesure** | id | uuid | PK | valeur calculée pour un triplet indicateur × périmètre × période |
| | indicateur | ref Indicateur | requis | |
| | entite | ref Entité (M8) | requis | site, région ou groupe selon le niveau |
| | axes | liste (AxeAnalytique, valeur) | optionnel | filtres appliqués (activité, produit, catégorie, canal) |
| | periode | plage de dates + granularité | requis | bornes obligatoires (RG-M7-... écran M7-04) |
| | valeur | decimal | requis | résultat du calcul |
| | regimeExploitant | enum {régie, DSP, groupe_privé, mixte} | requis si agrégé | porte `comparabiliteRegime` si mixte — RG-REPORT-09 |
| | comparabiliteRegime | bool | défaut faux | vrai si la mesure agrège des régimes différents (RG-REPORT-09) |
| | statutCompletude | enum {complet, partiel} | requis | RG-M7-08, RG-REPORT-11 |
| | sitesManquants | liste ref Entité | si partiel | traçabilité du périmètre incomplet |
| | fuseauAffichage / devise | string | requis à l'affichage | horodatage UTC en base, conversion à l'affichage — RG-REPORT-10 |
| **TableauDeBord** | id | uuid | PK | |
| | nom | string | requis | |
| | niveau | enum {établissement, région, groupe} | requis | rattaché à un niveau d'entité (M8) — RG-M7-05 |
| | entiteRattachee | ref Entité | requis | détermine le périmètre effectif (§4.1) |
| | indicateurs | liste ref Indicateur | requis, ≥1 | composition affichée |
| | miseEnPage | JSON/config | optionnel | disposition des widgets |
| **RapportPlanifié** | id | uuid | PK | |
| | modele / tableauDeBordRef | ref TableauDeBord | requis | RG-M7-06 |
| | format | enum {PDF, XLSX, CSV} | requis | Excel/PDF au cahier + CSV en extension (§4.6) |
| | destinataires | liste (email + entité de rattachement) | requis, ≥1 | périmètre appliqué par destinataire — RG-M7-07 |
| | periodicite | enum {quotidienne, hebdomadaire, mensuelle, …} | requis | + heure d'envoi |
| | etat | enum {actif, suspendu} | requis | RG-M7-06 |
| | dernierEnvoi / prochainEnvoi | datetime | dérivé | traçabilité du cycle actif→généré→envoyé |
| **Export** | id | uuid | PK | instance générée d'un RapportPlanifié, ou export manuel |
| | rapportPlanifie | ref RapportPlanifié | optionnel | nul si export manuel (§3, ⚠ HYPOTHÈSE) |
| | format | enum {PDF, XLSX, CSV} | requis | |
| | perimetre | ref Entité + axes appliqués | requis | reflète le périmètre du demandeur/destinataire |
| | statut | enum {généré, envoyé, échec} | requis | traçabilité de l'envoi |
| | genereLe | datetime | requis | |
| **PeriodeComparative** | periodeCourante | plage de dates | requis | pour les écarts « vs n-1 » (M7-02) |
| | periodeReference | plage de dates | requis | n-1 ou période paramétrée |
| | ecartValeur / ecartPourcentage | decimal | dérivé | code couleur bon/à surveiller/critique (§4.5) |

## 6. Critères d'acceptation

- **CA-1 (US-L11-06, RG-M7-01)** — *Étant donné* un directeur régional rattaché à la Région A, *quand*
  il ouvre son dashboard M7-02, *alors* il voit **l'agrégat de ses sites de la Région A uniquement** ;
  *quand* il tente d'accéder aux données d'un site de la Région B, *alors* l'accès est **refusé**
  (masqué, RG-M8-04).
- **CA-2 (US-L11-01)** — *Étant donné* un directeur de site connecté, *quand* il consulte son dashboard
  M7-01, *alors* il voit **CA du jour, entrées, jauge FMI et fond de caisse** de son établissement,
  actualisés **sans rechargement manuel** ; *quand* la présence simultanée atteint le seuil de sécurité
  ERP, *alors* la jauge FMI passe en **état d'alerte visuel**.
- **CA-3 (US-L11-02)** — *Étant donné* un directeur régional, *quand* il consulte M7-02, *alors* il voit
  **tous ses sites côte à côte** avec classements et écarts vs objectifs/n-1 ; *quand* il clique sur un
  site, *alors* il **descend en drill-down** au dashboard M7-01 détaillé de ce site.
- **CA-4 (US-L11-03)** — *Étant donné* la Direction générale, *quand* elle consulte M7-03, *alors* elle
  voit le **consolidé groupe** homogène sur toutes les régions, avec tendances/saisonnalité et
  benchmarks ; *quand* elle descend en drill-down, *alors* elle passe de région à site **sans
  changer d'axes** (RG-M7-05).
- **CA-5 (US-L11-04, RG-M7-02)** — *Étant donné* l'Explorateur d'analyses, *quand* un utilisateur
  compose une combinaison d'axes (site × activité × produit × période) autorisée par son périmètre,
  *alors* la valeur renvoyée est **strictement cohérente** avec celle affichée sur les dashboards pour
  le même indicateur et le même périmètre.
- **CA-6 (US-L11-07, RG-M7-04)** — *Étant donné* un établissement, *quand* on affiche ses indicateurs
  FMI et fréquentation cumulée pour une même journée, *alors* les **deux valeurs sont distinctes,
  clairement libellées et jamais fusionnées** dans un même champ ou une même formule d'agrégation.
- **CA-7 (US-L11-05, RG-M7-06)** — *Étant donné* un `RapportPlanifié` **actif**, *quand* l'heure/la
  périodicité configurée survient, *alors* le rapport est **généré et envoyé automatiquement**, même si
  son créateur n'est pas connecté ; *étant donné* un rapport **suspendu**, *alors* **aucune génération**
  n'a lieu à l'échéance prévue.
- **CA-8 (US-L11-06, RG-M7-07)** — *Étant donné* un `RapportPlanifié` envoyé à plusieurs destinataires
  de niveaux différents (site + région), *quand* le rapport est généré, *alors* **chaque destinataire
  reçoit uniquement les données de son propre périmètre**.
- **CA-9 (US-L11-08, RG-REPORT-09)** — *Étant donné* une région comportant des sites en régie et des
  sites en DSP, *quand* le CA consolidé de la région est affiché, *alors* la valeur agrégée est
  produite **et** porte un **indicateur de comparabilité activé**, avec accès aux vues isolées par
  régime (RAD/redevances DSP, état de régie publique) sans les fusionner dans l'agrégat commun.
- **CA-10 (US-L11-09, RG-M7-08, RG-REPORT-11)** — *Étant donné* un site n'ayant pas remonté ses
  données sur la période consultée au-delà du seuil de complétude configuré, *quand* le consolidé
  région/groupe est affiché, *alors* la vue **n'est pas bloquée**, la mesure concernée est **marquée
  « partielle »** de façon visible, et la **liste des sites manquants** est accessible — jamais de
  marquage silencieux.
- **CA-11 (RG-REPORT-10)** — *Étant donné* une mesure horodatée en UTC, *quand* elle est affichée sur
  un dashboard établissement, *alors* l'heure est convertie au **fuseau du site** ; *quand* la même
  mesure est affichée au niveau région/groupe regroupant des sites de fuseaux différents, *alors* la
  conversion appliquée est **explicite** (fuseau indiqué à l'écran).

## 7. Cas limites

- **FMI agrégée au-delà du site** (§4.4) — aucune agrégation ne doit laisser entendre une présence
  simultanée inter-sites réelle ; libellé explicite obligatoire (« somme des FMI max », « site le
  plus critique ») — ⚠ HYPOTHÈSE de traitement, à confirmer avec la sécurité ERP.
- **Périmètre mixte régie/DSP/groupe privé** — cf. RG-REPORT-09 ; un utilisateur groupe doit pouvoir
  **filtrer par régime** dans l'Explorateur pour retrouver la comparabilité stricte quand nécessaire.
- **Données manquantes prolongées** — si un site reste hors-complétude sur plusieurs périodes
  consécutives, ⚠ HYPOTHÈSE : au-delà d'un seuil (ex. N jours), une alerte dédiée est envoyée à
  l'administrateur en plus du marquage « partiel » sur les dashboards — non spécifié explicitement par
  le cahier, à trancher.
- **Gros volumes / historique pluriannuel** (dashboard DG, tendances & saisonnalité M7-03) — ⚠
  HYPOTHÈSE : les vues région/groupe ne sont **pas garanties temps réel strict** comme le dashboard
  établissement (M7-01) ; un **délai de fraîcheur** paramétrable (ex. rafraîchissement périodique des
  agrégats région/groupe, alors que le site reste quasi temps réel) est nécessaire pour absorber le
  volume — non chiffré par les sources, à cadrer en plan technique (budget de performance,
  pré-agrégation).
- **Comparaison n-1 avec périmètre changé** — un site déplacé d'une région à une autre entre la
  période courante et la période de référence (RG-M8-03, arbre d'entités mutable) : ⚠ HYPOTHÈSE :
  l'écart vs n-1 est calculé sur le **périmètre actuel** de l'entité consultée (les données
  historiques du site suivent son rattachement courant), avec un signalement si l'historique
  n'existait pas sous ce rattachement sur toute la période de référence.
- **Utilisateur avec rôles sur plusieurs entités non contiguës** (ex. directeur de deux sites de
  régions différentes, RG-M8-03 « union des rôles ») — le périmètre effectif de son dashboard/export
  est l'**union** de ces entités, sans dashboard « région » de rattachement implicite ; ⚠ HYPOTHÈSE :
  l'écran affiché est alors une vue multi-sites de type Explorateur plutôt qu'un unique M7-01/02/03 —
  non détaillé par le cahier.
- **Échec d'envoi d'un `RapportPlanifié`** (adresse invalide, service de messagerie indisponible) — le
  rapport reste `généré`, l'`Export` associé passe au statut `échec`, une nouvelle tentative ou une
  alerte à l'administrateur est attendue ; ⚠ HYPOTHÈSE : politique de réessai non précisée par le
  cahier.
- **Suppression/désactivation d'un `Indicateur` référencé par un `TableauDeBord` ou un
  `RapportPlanifié` actif** — ⚠ HYPOTHÈSE, par cohérence avec `spec-backoffice.md` M8-04
  (référentiel utilisé non supprimable, seulement désactivable) : un indicateur utilisé ne peut être
  que **désactivé**, jamais supprimé, pour préserver l'historique des rapports déjà envoyés.

## 8. Dépendances

- Dépend de **`specs/L0-socle/spec-socle.md`** — hiérarchie Groupe → Région → Établissement → Espace,
  identité et rattachement des utilisateurs (RG-SOCLE-01/02).
- Dépend de **`specs/L7-backoffice/spec-backoffice.md`** (M8) — droits fins `module × action`,
  héritage par niveau d'entité, interface adaptative (RG-M8-01 à 04) : M7 **hérite** le périmètre,
  ne le redéfinit jamais (RG-M7-01).
- Dépend de **`specs/L2-vente/spec-vente.md`** (M2) — CA, tickets, moyens de paiement, comme source du
  CA restitué (§4.5).
- Dépend de **`specs/L3-acces/spec-acces.md`** — passages horodatés, **fréquentation cumulée** et
  **jauge FMI** (RG-ACC-04) : M7 consomme ces mesures, ne les recalcule pas (RG-ACC-06 côté Accès).
- Dépend de **`specs/L4-compta/spec-compta.md`** (M6) — recettes, régie, TVA, RAD/redevances DSP
  (écran M6-08), fond de caisse, comme source des indicateurs comptables et du régime d'exploitant
  (RG-M6-01) utilisé pour la consolidation multi-régime (§4.7).
- Dépend de **`specs/reservation/spec-reservation.md`** (M5) — capacité, taux de remplissage,
  no-show qualifiés (`FacturationNoShow`).
- Dépend de **`specs/sport-fitness/spec-sport.md`** / recouvrement — indicateurs d'impayés
  (`IncidentPrelevement`, statut recouvrement).
- Dépend de **`specs/L1-offre/spec-offre.md`** (M1) — référentiel produit/catégorie utilisé par l'axe
  analytique produit/catégorie (§4.3).
- Dépendance transverse : **tout module producteur de données futur** (nouvelles verticales Piscine,
  Patinoire, Padel, Musée déjà couvertes par leurs specs dédiées) doit exposer ses indicateurs via la
  **source unique** (RG-M7-02) pour rester consommable par M7 sans intégration ad hoc par écran.
