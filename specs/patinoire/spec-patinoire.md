# Spec — Verticale Patinoire (`Patinoire` / preset vertical, hors lot MVP numéroté)

- **Lot / module :** Verticale **Patinoire** — preset qui **configure** le socle (M1–M8) et **réutilise**
  des briques déjà spécifiées (réservation & no-show générique, accès/FMI, caution). Ne redéfinit
  **que les règles propres** à la patinoire, conformément à la constitution §5 (« Vertical … Piscine ·
  Patinoire · Sport · Padel · Musée »).
- **Stories couvertes :** **US-PATIN-01 à US-PATIN-10** — ⚠ **HYPOTHÈSE / signalement** : ces user
  stories sont **définies par cette spec**, à la manière des `US-Lx-nn` du backlog, mais **n'existent
  pas dans `backlog.html`** à la date de rédaction (recherche du panel patinoire dans le backlog :
  aucune occurrence hors la mention transverse « Piscine, patinoire, sport, musée… » d'un sélecteur
  de reporting). La patinoire est **hors du périmètre des lots MVP L0→L7** de la constitution §5 ; ces
  stories sont donc **hors backlog MVP** et devront être **validées/priorisées** par le product owner
  avant tout plan d'implémentation.
- **Règles de gestion :** RG-PAT-01 à RG-PAT-06 (source `cahier-detaille.html`, panel `p-patinoire`) +
  décisions actées ★ « ⛸️ Patinoire » (Patins non rendus/cassés, Surbooking de la glace, Pointure en
  rupture, Affûtage) + règles socle réutilisées (RG-M1-02/04/05/06/09/10/13 de `spec-offre.md`,
  RG-M2-02/03/04/07 de `spec-vente.md`, RG-ACC-01 à 04/06 de `spec-acces.md`).
- **Statut :** brouillon

## 1. Objectif
Configurer le socle **M1–M8** pour une patinoire (permanente ou éphémère) et couvrir ses seules
**spécificités** : la **location de patins par pointure** avec caution et grille de retenue, le
**comptage séparé glace/gradins**, la **tolérance de surbooking** de la surface de glace laissée à
l'arbitrage manuel du gestionnaire, et l'**affûtage** à double casquette (prestation facturée /
opération interne). Principe directeur repris du cahier : *« la patinoire n'ajoute pas de moteur, elle
configure le socle »* — tout ce qui est couvert par la réservation générique à l'heure, le contrôle
d'accès/FMI, la caution générique ou la vente **n'est pas répété ici**.

## 2. Périmètre

### 2.1 [RÉUTILISE SOCLE] — non redéfini, seulement référencé
- **Réservation de la glace à l'heure** (créneaux, disponibilité, annulation, no-show) →
  `specs/reservation/spec-reservation.md`. ⚠ **GAP DE DÉPENDANCE** — ce fichier est **en cours de
  spécification** et **n'existe pas encore** dans `specs/reservation/` à la date de rédaction de cette
  spec (vérifié : aucun fichier sous `specs/reservation/**`). La patinoire **suppose** que ce module
  générique porte déjà : la notion de créneau réservable, la règle de no-show, le cycle réservation →
  présence → clôture. Cette spec **n'en redéfinit aucun mécanisme** ; elle **ajoute uniquement** le
  vocabulaire propre au public de la glace (§4.7) et la règle de tolérance au chevauchement (§4.8), à
  **réconcilier** dès que `spec-reservation.md` sera publiée (même traitement que le gap M5 documenté
  dans `spec-piscine.md` §2/§8).
- **Contrôle d'accès & FMI** (topologie espace/contrôleur/équipement, appairage support, validation de
  passage, jauge, hors-ligne/synchro, journal des passages, comptage non nominatif) →
  `specs/L3-acces/spec-acces.md`, RG-ACC-01 à 07. La patinoire **configure deux espaces d'accès**
  (glace, gradins) au sens L3 ; elle ne redéfinit ni le moteur de jauge, ni l'anti-passback, ni le
  hors-ligne.
- **Caution** (encaissement à la remise, restitution, retenue, trace comptable) → mécanisme déjà
  identifié comme **brique piscine réutilisable** (`spec-piscine.md` §4.9, `CautionCasier`). La
  patinoire **réutilise le même patron** (mouvement de caisse/régie dédié) pour la caution de location
  de patins, sans le redéfinir. ⚠ Ce mécanisme **n'a pas de spec transverse propre** (ni M2 ni M6 ne le
  portent nativement à ce jour) — voir §8 et le point de mutualisation signalé en synthèse finale.
- **Vente & caisse** (panier, encaissement, moyens de paiement, ticket, avoir/remboursement, mode
  hors-ligne) → `specs/L2-vente/spec-vente.md`, RG-M2-01 à 08. La location de patins et l'affûtage
  payant sont des **lignes de vente comme les autres** (produits M1) ; la patinoire ne redéfinit pas le
  panier ni l'encaissement.
- **CRM** (fiche client/famille, porteur du droit, historique) → `specs/L5-crm/spec-crm.md`. La
  patinoire rattache la location, la liste d'attente et l'entrée patineur/spectateur à un
  bénéficiaire existant, sans redéfinir la fiche famille.
- **Catalogue/produits/stock générique, saisons, TVA multi-taux, cycle brouillon→publié** →
  `specs/L1-offre/spec-offre.md`, RG-M1-01 à 13. Les tarifs entrée patineur/spectateur, l'abonnement
  patineur, la carte multi-entrées glace et le produit « affûtage payant » sont des **produits M1
  standard** ; seule la **déclinaison par pointure** du stock de patins est propre à la patinoire
  (§2.2, aucun concept de variante/pointure n'existe nativement dans M1 — voir §8).

### 2.2 [SPÉCIFIQUE PATINOIRE] — couvert par cette spec
- **Parc de patins par pointure** : stock déclinable par pointure, états bon/à affûter/hors service,
  disponibilité dérivée — RG-PAT-01, RG-PAT-06 — US-PATIN-01.
- **Location de patins (cycle sortie/retour)** : encaissement caution, blocage d'un article de la
  pointure demandée, retour avec saisie d'état, libération de l'article — RG-PAT-01, RG-PAT-05 —
  US-PATIN-02/03.
- **Grille de retenue paramétrable** sur caution en cas de non-restitution/casse, avec trace comptable
  en régie — décision actée « Patins non rendus / cassés » — US-PATIN-04.
- **Pointure en rupture** : proposition de pointure voisine + liste d'attente — décision actée
  « Pointure en rupture » — US-PATIN-05.
- **Affûtage** : service payant (tarif/TVA propres) **et** opération interne de maintenance du parc,
  avec immobilisation temporaire de l'article — RG-PAT-06, décision actée « Affûtage » —
  US-PATIN-06/07.
- **Comptage glace/gradins** : deux zones/jauges indépendantes, routage par type d'entrée — RG-PAT-02 —
  US-PATIN-08.
- **Surbooking de la glace en tolérance manuelle** : pas de blocage automatique des chevauchements,
  résolution laissée au gestionnaire — RG-PAT-03, décision actée « Surbooking de la glace » —
  US-PATIN-09.
- **Saisonnalité — patinoires éphémères** : fenêtre de vente/validité bornée, bascule automatique hors
  fenêtre — RG-PAT-04 — US-PATIN-10.
- **Exclu (pour l'instant), que le module *référence* seulement :**
  - Le **contrôle des douches**, le **matériel de protection** (casques, protège-tibias) : non
    mentionnés par le cahier patinoire (contrairement à la piscine) → hors périmètre de cette spec.
  - La **billetterie spectateur** (gradins) au-delà du routage de comptage (§4.6) : la définition du
    produit « entrée spectateur » elle-même relève de M1.
  - La **gestion RH des encadrants** (moniteurs de patinage, hockey) : référencée par analogie avec le
    patron piscine (`QualificationEncadrant`, `spec-piscine.md` §4.4/§5) mais **non détaillée ici**,
    faute de règle de gestion propre à la patinoire dans le cahier sur ce point — ⚠ HYPOTHÈSE : si
    besoin, réutiliser tel quel le patron piscine plutôt que d'en créer un nouveau.

## 3. Acteurs & droits
Les permissions réutilisent le modèle du socle (`RG-SOCLE-02/03/04/05` — couple `module × action`,
portées par l'établissement/espace actif, UI qui masque plutôt que désactive). La verticale introduit
le module **`patinoire`** pour ses objets propres (parc de patins, grille de retenue, affûtage, liste
d'attente pointure) et **réutilise** sans redéfinir les modules **`offre`** (M1), **`vente`** (M2),
**`acces`** (L3), **`reservation`** (glace à l'heure, cf. §2.1) et **`crm`** (M4).

| Acteur | Peut | Ne peut pas | Permission (module × action) |
|---|---|---|---|
| **Gestionnaire d'offre** | Configurer le parc par pointure (quantités, produit lié), la **grille de retenue** paramétrable, le **tarif/TVA de l'affûtage payant**, les **fenêtres de saison éphémère** (réutilise `offre`), le paramétrage des zones glace/gradins (réutilise `acces`) | Sortir/rendre un patin au comptoir ; encaisser une caution | `offre × creer`, `offre × modifier` (réutilisées M1), `patinoire × configurer` |
| **Agent de comptoir / caisse** | Enregistrer une **sortie de patins** (pointure, caution), enregistrer un **retour** (état, retenue proposée par la grille), proposer une **pointure voisine**, inscrire un client en **liste d'attente**, orienter vers l'**affûtage payant** au comptoir (réutilise `vente`) | Modifier la grille de retenue ; configurer le parc ; forcer une retenue hors barème sans validation | `vente × encaisser` (réutilisée M2), `patinoire × gerer_location`, `patinoire × gerer_liste_attente` |
| **Technicien / atelier** | Faire passer un article à l'état **« à affûter »**, réaliser l'affûtage (prestation client ou maintenance du parc), remettre l'article **« bon »** et disponible | Encaisser une caution ; modifier le tarif de l'affûtage payant | `patinoire × gerer_affutage` |
| **Gestionnaire glace (planning)** | Consulter les créneaux de glace (réutilise `reservation`), **arbitrer manuellement** un chevauchement de créneaux/publics (contact client, réaffectation, remboursement partiel) | Bloquer automatiquement un chevauchement (RG-PAT-03/décision actée : pas d'automatisme) | `reservation × superviser` (réutilisée), `patinoire × arbitrer_surbooking` |
| **Administrateur** | Tout ce qui précède + **forcer une retenue** hors grille (action journalisée) + gérer le référentiel de pointures | — | `patinoire × gerer` (surensemble), `patinoire × forcer_retenue` |
| **Système** | Décrémenter/recréditer automatiquement la disponibilité du parc à la sortie/au retour ; appliquer le **montant par défaut** de la grille de retenue selon le motif saisi ; router chaque entrée vers la **bonne jauge** (glace/gradins) selon le type de billet ; **fermer automatiquement** la vente hors fenêtre de saison éphémère | Bloquer automatiquement un chevauchement de créneaux glace (tolérance libre actée) | *(acteur technique — pas de permission humaine)* |
| **Lecture seule** | Consulter le parc, l'état des locations en cours, la liste d'attente, le tableau glace/gradins | Toute action d'écriture | `patinoire × lire` |

- ⚠ HYPOTHÈSE — Les noms de permissions `patinoire × configurer / gerer_location / gerer_liste_attente
  / gerer_affutage / arbitrer_surbooking / forcer_retenue / lire / gerer` déclinent le tableau
  ci-dessus selon le modèle `module × action` du socle ; **non nommées littéralement** dans les
  sources (le cahier ne détaille pas le module `patinoire` en droits fins, à l'image de `piscine` et
  `acces` déjà signalés). Découpage à **arbitrer avec M8**.
- ⚠ HYPOTHÈSE — Le rôle **Gestionnaire glace (planning)** est retenu comme un utilisateur du socle avec
  affectation établissement ; le cahier ne détaille pas ce rôle spécifiquement, il est déduit de la
  décision actée « surbooking géré manuellement par le gestionnaire ».

## 4. Comportements & règles
Chaque comportement trace une **RG-PAT** (source : `cahier-detaille.html`, panel `p-patinoire`) et/ou
une **décision actée** (★, panel « ⛸️ Patinoire »). Les décisions actées **font foi** et ne sont pas
re-tranchées (constitution §6).

### 4.1 Parc de patins par pointure (US-PATIN-01, RG-PAT-01, RG-PAT-06)
- Le stock de patins est **décliné par pointure** : chaque pointure porte une **quantité totale**, et
  une **disponibilité dérivée** = total − sorti − en affûtage − hors service (cahier §2 « Stock par
  pointure »).
- Chaque pointure est rattachée à un **produit de location** (M1, facette stock, RG-M1-10 « stock
  dédié ») ; **aucune notion de variante/pointure n'existe nativement dans M1** — c'est la **déclinaison
  propre à la patinoire** (§8, à harmoniser avec un éventuel concept générique de variante produit).
- Un article passé à l'état **« à affûter »** ou **« hors service »** **sort du parc louable** et
  **n'est plus décompté dans le disponible** tant qu'il n'est pas remis en service (RG-PAT-06).
- L'état d'un article est **bon / à affûter / hors service**, saisi au retour ou par le technicien
  (cahier §2).

### 4.2 Sortie de patins & caution (US-PATIN-02, RG-PAT-01)
- **RG-PAT-01** — La location d'une paire de patins **bloque un article de stock** de la **pointure
  demandée** contre **encaissement d'une caution** ; l'unité **redevient disponible au retour**.
- La sortie se fait au **comptoir** (écran dédié), rattachée à une **vente** (produit « location de
  patins », M2) et à un **bénéficiaire** identifié (réutilise le bénéficiaire de ligne, RG-M2-04).
- La **caution** est **encaissée à la sortie** comme un **mouvement dédié** (réutilise le patron
  `CautionCasier` de la piscine, `spec-piscine.md` §4.9) : ⚠ HYPOTHÈSE — mode d'encaissement (moyen de
  paiement dédié, empreinte CB, dépôt espèces) **non précisé** par les sources, retenu comme un
  **mouvement de caisse/régie « caution »** identique au patron piscine, à harmoniser transversalement
  (voir synthèse finale).
- Une sortie ne peut être enregistrée que si la **pointure demandée a une disponibilité > 0** ; à
  défaut, voir §4.5 (pointure en rupture).

### 4.3 Retour & saisie d'état (US-PATIN-03, RG-PAT-05)
- **RG-PAT-05** — Au retour, l'**état du matériel est saisi** ; en cas de **non-restitution ou de
  casse**, une **retenue est appliquée** sur la caution (**totale ou partielle**).
- Le retour **libère l'article** de la pointure concernée et le fait basculer à l'état déclaré (bon →
  disponible immédiatement ; à affûter/HS → sort du disponible, §4.1/§4.6).
- Si l'état est **bon**, la **caution est intégralement restituée** ; si l'état est **casse** ou
  **non-restitution** (le client ne rend pas le matériel), la **grille de retenue** s'applique (§4.4).
- ⚠ HYPOTHÈSE — **Restitution partielle d'une paire** (un seul patin rendu, l'autre perdu) : le cahier
  pose la question sans trancher (§7 « Restitution partielle possible ? »). Retenue comme hypothèse de
  travail : la grille de retenue (§4.4) peut porter un **taux réduit pour restitution partielle**
  (paramétrable), **à confirmer avec l'exploitant** avant configuration.

### 4.4 Grille de retenue paramétrable + trace comptable (US-PATIN-04, décision actée)
- **Décision actée « Patins non rendus / cassés »** — La retenue suit une **grille de retenue
  paramétrable** (par établissement), **plus** une **trace comptable** de la retenue en régie. Cette
  décision **fait foi** et répond à la question ouverte du cahier §7 (*« forfait vs valeur de
  remplacement »*), sans trancher elle-même **entre les deux modes** — les **deux modes** restent
  possibles selon paramétrage.
- La grille porte, par établissement (et éventuellement par pointure/article) : un **mode** (forfait
  fixe **ou** valeur de remplacement), un **motif** (casse / non-restitution / perte) et un **montant**
  ou **taux**.
- Au retour en état « casse » ou en cas de **non-restitution constatée** (délai paramétrable après la
  fin de la session, ⚠ HYPOTHÈSE non chiffré par les sources, sur le même patron que le délai de
  forçage des casiers piscine), le **montant de retenue par défaut** de la grille est **proposé** à
  l'agent, **modifiable** par un profil habilité (Administrateur, §3) avant validation.
- Toute retenue **génère une trace comptable en régie** (mouvement dédié, motif, montant, article,
  date) — décision actée, réutilise le mécanisme générique de mouvement de caisse M2/M6 (RG-M2-07 par
  analogie « annulation/avoir tracés »).
- ⚠ HYPOTHÈSE — Le **montant de caution initial** (forfait unique ou grille selon pointure/article)
  n'est **pas chiffré** par les sources (cahier donne un exemple « caution 15 € » à titre illustratif
  uniquement) ; retenu comme **paramétrable par établissement**, à l'image de la caution casier piscine
  (`spec-piscine.md` §4.9, même hypothèse).

### 4.5 Pointure en rupture — pointure voisine + liste d'attente (US-PATIN-05, décision actée)
- **Décision actée « Pointure en rupture »** — Quand la pointure demandée a une **disponibilité nulle**,
  le système **propose une pointure voisine** disponible, puis, à défaut d'acceptation ou de
  disponibilité voisine, propose une **inscription en liste d'attente**.
- ⚠ HYPOTHÈSE — L'**algorithme de « pointure voisine »** (± 1 pointure d'abord, puis ± 2 ?) et le
  **nombre de propositions successives** avant bascule en liste d'attente **ne sont pas détaillés**
  par les sources ; retenu comme hypothèse de travail : proposition des pointures **immédiatement
  inférieure et supérieure disponibles**, dans cet ordre, **paramétrable par établissement**.
- Une inscription en **liste d'attente** porte : pointure demandée, client, date de la demande,
  statut. Le client est **notifié** dès qu'une unité de sa pointure redevient disponible (retour d'une
  location, remise en service après affûtage) ; ⚠ HYPOTHÈSE — canal de notification (e-mail/SMS/app)
  et **délai de réponse avant réattribution au suivant de la liste** non précisés, retenus par
  analogie avec le patron générique de liste d'attente déjà mentionné pour M5 (`spec-piscine.md`, cas
  limite « Encadrant absent » → notification des inscrits) et pour le fitness (liste d'attente cours).

### 4.6 Affûtage — service payant et opération interne (US-PATIN-06/07, RG-PAT-06, décision actée)
- **Décision actée « Affûtage »** — L'affûtage est **à la fois** un **service payant** (tarif et TVA
  **propres**, facturable au client) **et** une **opération interne** de maintenance du parc de
  location.
- **Deux cas distincts** couverts par le même objet (§5, `Affutage.type`) :
  1. **Prestation client (`type = prestation_client`)** — un patineur fait affûter **ses propres
     patins** (hors parc de location) : ligne de vente **produit M1 dédié** (tarif, TVA propres),
     encaissée comme toute prestation (réutilise M2) ; **n'impacte pas** la disponibilité du parc de
     location.
  2. **Maintenance du parc (`type = maintenance_parc`)** — un article du **parc de location** passe à
     l'état **« à affûter »**, **sort du disponible** (RG-PAT-06, §4.1), est traité par le technicien,
     puis **repasse « bon »** et redevient disponible ; **aucune vente** n'est associée (opération
     interne), sauf si l'établissement choisit de **facturer en interne** (⚠ HYPOTHÈSE, non détaillé
     par les sources).
- Dans les deux cas, l'affûtage est **journalisé** (article ou patin client, date d'entrée/sortie
  atelier, technicien) pour suivi opérationnel.
- ⚠ HYPOTHÈSE — L'**impact de l'affûtage payant sur la disponibilité du parc** (cahier §7 : *« Impact
  sur la disponibilité du parc »*) ne concerne, par construction, **que le cas `maintenance_parc`** :
  les patins personnels d'un client affûtés en prestation ne font **jamais partie** du parc de
  location, donc **aucun impact sur le disponible** dans ce cas — **hypothèse à confirmer** si un
  établissement voulait un jour affûter à la demande des patins du parc de location eux-mêmes en
  dehors du cycle de maintenance planifié.

### 4.7 Comptage glace / gradins (US-PATIN-08, RG-PAT-02)
- **RG-PAT-02** — Le comptage d'accès **distingue patineurs et spectateurs** : chaque entrée alimente
  la **jauge de sa zone** (glace ou gradins), les **deux jauges étant indépendantes**.
- Chaque zone (glace, gradins) est une **spécialisation d'`EspaceAccès`** (L3, `spec-acces.md` §5) avec
  son **propre seuil FMI** et sa **propre jauge**, sans lien de dépendance entre les deux compteurs.
- Le **type de billet** (entrée patineur / entrée spectateur) détermine, au niveau du **droit d'accès**
  (M1/M2, réutilisé tel quel — RG-ACC-01), **quelle zone** le passage doit valider ; L3 **route** déjà
  chaque passage vers l'espace associé à l'équipement — la patinoire **ne fait que configurer** deux
  espaces distincts, elle **n'ajoute aucun mécanisme** de routage nouveau.
- L'**affichage temps réel** (occupation glace vs gradins) réutilise la supervision générique L3
  (US-L3-06, `spec-acces.md` §4.10) sur les **deux espaces** configurés.

### 4.8 Surbooking de la glace — tolérance manuelle (US-PATIN-09, RG-PAT-03, décision actée)
- **RG-PAT-03** (règle nominale) — Un créneau de glace **réserve la surface pour un seul public**
  (public / hockey / patinage artistique / écoles) sur sa plage ; **aucun autre public** n'y accède
  **simultanément**, en fonctionnement normal.
- **Décision actée « Surbooking de la glace »** (règle d'exception, **✱ diffère de la recommandation
  initiale**) — En cas de **chevauchement effectif** de deux créneaux/publics sur la glace, le système
  applique une **tolérance libre** : **aucun blocage automatique** à la réservation ou à la validation
  d'un chevauchement ; la résolution est **gérée manuellement par le gestionnaire** (contact des
  parties concernées, réaffectation de créneau, remboursement/dédommagement le cas échéant, hors
  périmètre applicatif de cette spec).
- **Articulation des deux règles** — RG-PAT-03 décrit le **comportement attendu en usage normal**
  (un public par créneau) ; la **décision actée** couvre le **cas où, malgré tout, un chevauchement
  survient** (erreur de saisie, urgence, réservation manuelle en dehors du moteur standard) : le moteur
  de **réservation générique** (`specs/reservation/spec-reservation.md`, §2.1) **n'impose pas de
  verrou dur** sur la disponibilité de la ressource « glace » pour la patinoire — contrairement, par
  exemple, au verrou strict des lignes d'eau en piscine (`spec-piscine.md` RG-PISC-03, « une ligne ne
  peut être affectée qu'à un seul public à un instant donné »). C'est une **différence assumée** entre
  les deux verticales, actée explicitement pour la patinoire.
- Le tableau de bord glace signale (⚠ HYPOTHÈSE, non détaillé par les sources au-delà du principe de
  tolérance) les **chevauchements détectés** au gestionnaire, sans les empêcher — à préciser
  (alerte visuelle en planning ? liste des conflits à traiter ?) une fois `spec-reservation.md`
  disponible.

### 4.9 Saisonnalité — patinoires éphémères (US-PATIN-10, RG-PAT-04)
- **RG-PAT-04** — Une offre saisonnière a une **fenêtre de vente et de validité bornée** ; **hors de
  cette fenêtre**, la **vente et l'accès sont fermés automatiquement**.
- Réutilise la **Saison** générique de M1 (`spec-offre.md`, objet `Saison`, `dateDébut`/`dateFin`,
  RG-M1-06) et la **durée de validité**/les **canaux** du `Produit` (RG-M1-07/09) : une patinoire
  éphémère associe son **catalogue** (tarifs, produits) à une **fenêtre datée** (ex. décembre-février).
- ⚠ **GAP** — M1 (`spec-offre.md`) ne décrit **aucune bascule automatique** de statut produit
  (`brouillon`/`publié`/`archivé`) déclenchée par une **date de fin de fenêtre** : le cycle de vie
  décrit (RG-M1-09) est **manuel** (conditions de publication) et ne prévoit pas d'**archivage
  temporisé**. La **fermeture automatique de la vente hors fenêtre** (exigée par RG-PAT-04) est donc
  une **spécificité patinoire non couverte nativement par M1** — à traiter soit comme une extension
  du cycle de vie M1 (job de bascule à date), soit comme un comportement propre porté par un objet
  `SaisonÉphémère` dédié (§5). Retenu ici comme un **objet dédié patinoire** en attendant arbitrage
  avec M1.
- La **fermeture de l'accès** hors fenêtre réutilise le contrôle des **droits d'accès expirés** de L3
  (`DroitAccès.fenêtreValidité`, RG-ACC-01) : un droit acheté pour une saison ne valide plus après sa
  `dateFin`, sans mécanisme supplémentaire.

## 5. Objets de données
Les types PHP sont indicatifs (spec = comportement observable). Tout objet est rattaché à un
**Établissement/Espace** via le socle (`RG-SOCLE-01`) ; identifiants = **UUID** (constitution §3). Les
objets M1 (`Produit`, `Stock`, `Saison`), M2 (`Vente`, `LigneVente`, mouvement de caisse), L3
(`EspaceAccès`, `DroitAccès`, `Passage`) et M4 (`Beneficiaire`) sont **référencés, non redéfinis** —
voir `spec-offre.md` §5, `spec-vente.md` §5, `spec-acces.md` §5, `spec-crm.md` §5. Les objets de
réservation de la glace (`Creneau`, réservation, no-show) relèvent de `specs/reservation/` (⚠ non
encore publiée, §2.1) et **ne sont pas modélisés ici**.

| Objet | Champ | Type | Contraintes | Notes |
|---|---|---|---|---|
| **ParcPatins** | id | uuid | PK | RG-PAT-01, cahier §4 « Stock de patins et matériel géré par pointure » |
| | pointure | int (ex. 28–48) | requis, unique par établissement | déclinaison propre patinoire (§8, aucun concept M1 natif) |
| | produitLocationRef | ref Produit (M1, facette stock) | requis | RG-M1-10 « stock dédié » |
| | quantitéTotale | int ≥ 0 | requis | cahier §4 |
| | quantitéSortie | int ≥ 0 | dérivé | Σ LocationPatins en_cours pour cette pointure |
| | quantitéEnAffûtage | int ≥ 0 | dérivé | Σ articles à l'état « à affûter » (§4.6 maintenance_parc en cours) |
| | quantitéHS | int ≥ 0 | dérivé | articles hors service |
| | quantitéDisponible | int ≥ 0 | dérivé = total − sortie − affûtage − HS | RG-PAT-06 |
| **LocationPatins** *(= « LocationMatériel » du cahier §4)* | id | uuid | PK | RG-PAT-01/05 |
| | parcPatinsRef | ref ParcPatins | requis | pointure louée |
| | ligneVenteRef | ref LigneVente (M2) | requis | produit « location de patins » |
| | beneficiaireRef | ref Beneficiaire (M4) | requis | porteur de la location |
| | cautionRef | ref Caution | requis | réutilise le patron piscine (§4.2, ⚠ mutualisation à confirmer) |
| | dateSortie | datetime | requis | horodaté |
| | dateRetour | datetime? | vide tant qu'en cours | — |
| | etatRetour | enum {bon, casse, non_rendu} | requis à la clôture | RG-PAT-05 |
| | statut | enum {en_cours, retournee, non_rendue} | défaut = en_cours | cycle de vie |
| | retenueRef | ref RetenueCaution? | requis si etatRetour ∈ {casse, non_rendu} | §4.4 |
| **Caution** *(patron générique, réutilisé — voir `spec-piscine.md` §4.9 `CautionCasier`)* | id | uuid | PK | ⚠ pas de spec transverse M2/M6, patron dupliqué piscine/patinoire à ce jour |
| | montant | decimal ≥ 0 | requis, paramétrable | ⚠ HYPOTHÈSE non chiffré (§4.2) |
| | statut | enum {encaissee, liberee, retenue_partielle, retenue_totale} | défaut = encaissee | §4.3/4.4 |
| **GrilleRetenue** | id | uuid | PK | décision actée « grille de retenue paramétrable » |
| | etablissementRef | ref Établissement (socle) | requis | paramétrable par établissement |
| | motif | enum {casse, non_rendu, perte, restitution_partielle} | requis | §4.4 |
| | mode | enum {forfait, valeur_remplacement} | requis | cahier §7, non tranché entre les deux → les deux existent |
| | montantOuTaux | decimal ≥ 0 | requis | forfait (montant) ou % de la valeur (taux) selon `mode` |
| | pointureRef | ref ParcPatins? | optionnel | grille pouvant varier par pointure/article |
| **RetenueCaution** | id, locationRef | uuid, ref LocationPatins | requis | trace de l'application de la grille |
| | grilleAppliqueeRef | ref GrilleRetenue | requis | valeur par défaut proposée |
| | montantRetenu | decimal ≥ 0 | modifiable par profil habilité avant validation | §4.4 |
| | mouvementRegieRef | ref (mouvement de caisse/régie, M2/M6) | requis | trace comptable décision actée |
| | agent, motif, horodatage | ref Utilisateur, string, datetime | requis | journalisé (RG-SOCLE-07) |
| **ListeAttentePointure** | id | uuid | PK | décision actée « pointure en rupture » |
| | pointureRef | ref ParcPatins | requis | pointure demandée |
| | clientRef | ref Beneficiaire (M4) | requis | — |
| | dateDemande | datetime | requis | — |
| | statut | enum {en_attente, proposee, honoree, expiree} | défaut = en_attente | §4.5 |
| | pointureVoisineProposee | int? | optionnel | ⚠ HYPOTHÈSE algorithme ±1 (§4.5) |
| **Affutage** | id | uuid | PK | RG-PAT-06, décision actée « affûtage » |
| | type | enum {prestation_client, maintenance_parc} | requis | §4.6, distingue les deux cas |
| | ligneVenteRef | ref LigneVente (M2)? | requis si type = prestation_client | produit dédié tarif/TVA propres |
| | parcPatinsRef | ref ParcPatins? | requis si type = maintenance_parc | article immobilisé (§4.1) |
| | dateEntreeAtelier, dateSortieAtelier | datetime, datetime? | entrée requise | suivi opérationnel |
| | technicienRef | ref Utilisateur (socle) | requis | §3 |
| | statut | enum {en_attente, en_cours, termine} | défaut = en_attente | remise en service = « bon » (§4.1) |
| **ZonePatinoire** *(spécialisation d'`EspaceAccès`, L3)* | espaceAccesRef | ref EspaceAccès (L3) | requis | RG-PAT-02 |
| | typeZone | enum {glace, gradins} | requis | routage du billet (§4.7) |
| **SaisonEphemere** | id, libelle | uuid, string | requis | RG-PAT-04, cahier §4 |
| | dateOuverture, dateFermeture | date, date | ouverture ≤ fermeture | montage/démontage installation |
| | fenetreVenteDebut, fenetreVenteFin | date, date | requis | peut différer de la fenêtre d'exploitation |
| | saisonM1Ref | ref Saison (M1) | requis | réutilise `spec-offre.md` §5 `Saison` |
| | catalogueAssocie[] | ref Produit[] (M1) | ≥ 1 | produits actifs sur la fenêtre |
| | bascule | enum {manuelle, automatique} | ⚠ défaut à arbitrer (§4.9, gap M1) | fermeture auto vente/accès hors fenêtre |

## 6. Critères d'acceptation
- **CA-1 (US-PATIN-01, RG-PAT-01, RG-PAT-06)** — *Étant donné* un parc de patins déclaré par pointure,
  *quand* on consulte la disponibilité, *alors* elle vaut **total − sorti − en affûtage − hors
  service** ; *quand* un article passe à l'état **« à affûter »** ou **« hors service »**, *alors* il
  **sort immédiatement du disponible** et n'y revient qu'après remise en service.
- **CA-2 (US-PATIN-02, RG-PAT-01)** — *Étant donné* une pointure disponible, *quand* l'agent enregistre
  une **sortie de patins** rattachée à une vente et un bénéficiaire, *alors* un **article de cette
  pointure est bloqué**, une **caution est encaissée**, et la disponibilité de la pointure **décroît
  de 1** ; *quand* la pointure demandée est à disponibilité nulle, *alors* la sortie est **refusée** et
  bascule vers §4.5 (CA-5).
- **CA-3 (US-PATIN-03, RG-PAT-05)** — *Étant donné* une location en cours, *quand* l'agent enregistre le
  **retour** avec l'**état constaté** (bon/casse/non-rendu), *alors* l'article **redevient disponible**
  (ou sort du parc si casse/HS), et la **caution est restituée intégralement si l'état est bon**, ou
  soumise à la **grille de retenue** sinon (→ CA-4).
- **CA-4 (US-PATIN-04, décision actée)** — *Étant donné* un retour en état **casse** ou **non-rendu**,
  *quand* l'agent clôture le retour, *alors* le **montant par défaut de la grille de retenue
  paramétrée** (forfait ou valeur de remplacement, selon configuration établissement) est **proposé**,
  **modifiable par un profil habilité** avant validation, et **génère une trace comptable en régie**
  (mouvement dédié, motif, montant, article, date).
- **CA-5 (US-PATIN-05, décision actée)** — *Étant donné* une pointure demandée à **disponibilité nulle**,
  *quand* l'agent tente une sortie, *alors* le système **propose une pointure voisine disponible** ;
  *quand* aucune pointure voisine n'est disponible ou n'est acceptée, *alors* le client peut être
  **inscrit en liste d'attente**, et est **notifié** dès qu'une unité de sa pointure redevient
  disponible.
- **CA-6 (US-PATIN-06, décision actée)** — *Étant donné* un client apportant ses **propres patins**,
  *quand* il demande un **affûtage payant**, *alors* une **ligne de vente dédiée** (tarif et TVA
  **propres** à ce service) est créée, encaissée comme toute prestation, **sans impact** sur la
  disponibilité du parc de location.
- **CA-7 (US-PATIN-07, RG-PAT-06, décision actée)** — *Étant donné* un article du **parc de location**
  nécessitant un affûtage, *quand* il est mis à l'état **« à affûter »**, *alors* il **sort du
  disponible** (CA-1) ; *quand* le technicien termine l'opération, *alors* l'article **repasse « bon »
  » et redevient disponible**, **sans ligne de vente associée** (opération interne).
- **CA-8 (US-PATIN-08, RG-PAT-02)** — *Étant donné* deux zones **glace** et **gradins** configurées
  comme espaces d'accès distincts, *quand* un patineur et un spectateur franchissent chacun leur
  équipement, *alors* **chaque passage incrémente la jauge de sa seule zone** ; les deux jauges restent
  **indépendantes** et s'affichent séparément en supervision.
- **CA-9 (US-PATIN-09, RG-PAT-03, décision actée)** — *Étant donné* deux créneaux/publics glace qui se
  **chevauchent** (erreur, cas exceptionnel), *quand* la réservation est enregistrée, *alors* **aucun
  blocage automatique** n'empêche l'opération (tolérance actée) ; le chevauchement reste **visible** au
  gestionnaire, dont la **résolution manuelle** (réaffectation, contact client) n'est **pas automatisée**
  par le système.
- **CA-10 (US-PATIN-10, RG-PAT-04)** — *Étant donné* une offre saisonnière avec une **fenêtre de vente
  et de validité bornée**, *quand* la date courante est **hors de cette fenêtre**, *alors* la **vente
  est fermée automatiquement** (produits non commercialisables) et l'**accès est refusé** (droit hors
  fenêtre de validité, réutilise RG-ACC-01) ; *quand* la date rentre **dans la fenêtre**, *alors* la
  vente et l'accès sont **automatiquement rouverts**.

## 7. Cas limites
- **Restitution partielle d'une paire** (un seul patin rendu) — ⚠ **non tranché** par les sources
  (cahier §7) ; hypothèse : taux réduit de la grille de retenue applicable, à confirmer avec
  l'exploitant (§4.3).
- **Caution mutualisée par panier vs par article loué** — ⚠ **explicitement laissé « à trancher »** par
  le cahier (§7, note finale : *« la caution est-elle mutualisée par panier (une caution / client) ou
  une caution par article loué ? »*). Cette spec retient par défaut **une caution par article loué**
  (cohérent avec la formulation singulière de RG-PAT-01 « un article… contre encaissement d'une
  caution ») mais **le point reste ouvert** — impact direct sur le modèle `Caution`/`LocationPatins`
  (§5) si la décision finale diffère (caution unique multi-articles au panier).
- **Non-restitution constatée — délai avant retenue automatique** — ⚠ le cahier ne précise pas de délai
  de grâce après la fin de session avant de considérer un article comme non rendu (contrairement au
  « délai de forçage » explicite des casiers piscine) ; retenu par analogie comme **paramétrable par
  établissement**, à confirmer (§4.4).
- **Pointure voisine indisponible également** — repli sur liste d'attente (CA-5) ; ⚠ l'**algorithme
  exact de proximité** (±1 puis ±2 ?) et le **nombre de propositions** ne sont pas détaillés (§4.5).
- **Affûtage payant demandé sur un article du parc de location lui-même** (hors cycle de maintenance
  planifié) — ⚠ cas non couvert explicitement par les sources ; le modèle `Affutage.type` suppose une
  distinction stricte prestation_client / maintenance_parc, à confirmer si un établissement souhaitait
  un jour facturer l'affûtage d'un article de son propre parc (§4.6).
- **Chevauchement de créneaux glace non résolu avant l'heure** — Tolérance libre actée (RG-PAT-03,
  décision) ; ⚠ **aucune procédure de résolution n'est spécifiée** (ordre de priorité entre publics,
  compensation) — relève de la gestion opérationnelle humaine, hors périmètre applicatif (§4.8).
- **Établissement sans réservation glace préalable pour l'accès patineur libre** (séance publique sans
  créneau nommé) — ⚠ articulation avec `specs/reservation/` non tranchée faute de spec publiée (gap
  §2.1) ; hypothèse : un accès patineur « libre » consomme simplement un droit d'accès M1/L3 sans
  passer par le moteur de créneau, à confirmer une fois `spec-reservation.md` disponible.
- **Fenêtre de vente ≠ fenêtre d'exploitation** (ex. vente ouverte avant montage de la patinoire) —
  couvert par la distinction `fenetreVenteDebut/Fin` vs `dateOuverture/dateFermeture` (§5) ; ⚠ le
  comportement si un client achète pendant la fenêtre de vente mais **avant l'ouverture effective**
  (accès refusé jusqu'à `dateOuverture` ?) n'est **pas détaillé** par les sources.
- **Bascule automatique de statut produit hors fenêtre de saison** — ⚠ **gap M1** : le cycle de vie
  produit générique (RG-M1-09) est **manuel** (conditions de publication), sans **job de bascule
  temporisée** ; la fermeture automatique exigée par RG-PAT-04 nécessite soit une **extension de M1**,
  soit un comportement propre porté par `SaisonEphemere` — **à arbitrer avec M1** avant implémentation
  (§4.9).
- **Article marqué « hors service » définitivement (casse totale, non recyclable)** — ⚠ non détaillé :
  sortie du disponible permanente (RG-PAT-06) mais le cahier ne précise pas de **processus de
  déclassement/réforme** du parc (retrait comptable, remplacement budgétaire) — hors périmètre
  applicatif, signalé pour cohérence avec M6/inventaire.

## 8. Dépendances
- **Dépend de : socle L0** (`specs/L0-socle/spec-socle.md`) — hiérarchie Groupe/Région/Établissement/
  Espace (`RG-SOCLE-01`) à laquelle se rattachent le parc de patins, les zones et les grilles de
  retenue ; permissions `module × action` réutilisées et étendues au module **`patinoire`**
  (`RG-SOCLE-02/03/04`) ; journal d'audit append-only (`RG-SOCLE-07`) pour les retenues forcées et les
  déclarations de non-restitution.
- **Dépend de : M1 · Offre & Tarification** (`specs/L1-offre/spec-offre.md`) — produits « entrée
  patineur/spectateur », « location de patins », « affûtage payant », abonnement patineur, carte
  multi-entrées glace (RG-M1-01 à 13) ; `Saison`/`durée de validité`/`canaux` pour la saisonnalité
  éphémère (RG-M1-06/07/09). ⚠ **GAP** — aucun concept natif de **déclinaison/variante par pointure**
  ni de **bascule automatique de statut par date** (§4.1, §4.9) — extension à arbitrer avec M1.
- **Dépend de : M2 · Vente & Caisse** (`specs/L2-vente/spec-vente.md`) — panier, lignes de vente pour
  la location et l'affûtage payant, encaissement, mouvements de caisse/régie pour la caution et la
  retenue (RG-M2-01 à 08). ⚠ Le **mécanisme générique de caution** n'a pas de spec transverse propre
  (voir synthèse) ; patinoire et piscine dupliquent aujourd'hui le même patron.
- **Dépend de : L3 · Contrôle d'accès** (`specs/L3-acces/spec-acces.md`) — moteur générique de jauge
  FMI, validation de passage, hors-ligne/synchro (RG-ACC-01 à 07), spécialisé en **deux `EspaceAccès`**
  (glace, gradins) sans mécanisme nouveau (§4.7).
- **Dépend de (⚠ non encore publiée dans le dépôt) : `specs/reservation/spec-reservation.md`** —
  réservation générique de la glace à l'heure, no-show ; la patinoire s'appuie sur ce moteur pour les
  créneaux et n'en redéfinit aucun mécanisme, mais **ajoute** le vocabulaire de public (§4.7 du
  cahier : public/hockey/artistique/écoles) et la **règle de tolérance au chevauchement** (§4.8) —
  **risque de divergence à traiter en priorité** dès la publication de cette spec (même traitement que
  le gap M5 documenté dans `spec-piscine.md` §8).
- **Dépend de : L6 · Piscine** (`specs/L6-piscine/spec-piscine.md`) — **patron de caution** réutilisé
  tel quel (`CautionCasier` → `Caution`, §4.2/§5) ; référence de style pour la modélisation
  `LocationMatériel`/grille de retenue. Aucune dépendance fonctionnelle directe (deux verticales
  indépendantes), mais **mutualisation technique recommandée** (voir synthèse finale).
- **Dépend de : M6 · Compta & Régie** (`specs/L4-compta/spec-compta.md`) — trace comptable de la
  retenue de caution (décision actée §4.4), reconnaissance PCA des produits saisonniers vendus
  d'avance (abonnement patineur, carte glace, héritée de RG-M1-08).
- **Dépend de : M4/CRM** (`specs/L5-crm/spec-crm.md`) — bénéficiaire porteur de la location, de la
  liste d'attente et de l'entrée patineur/spectateur.

---

## Points ouverts / hypothèses (récapitulatif)
1. **⚠ GAP DE DÉPENDANCE — Absence de `specs/reservation/spec-reservation.md`** : le moteur générique de
   réservation de la glace à l'heure n'est pas encore publié dans le dépôt ; cette spec pose des
   hypothèses de vocabulaire (public de la glace) et de comportement (tolérance au chevauchement) à
   réconcilier dès sa publication (§2.1, §4.8, §8).
2. **⚠ Caution mutualisée par panier vs par article loué** : explicitement laissé « à trancher » par le
   cahier ; hypothèse retenue = caution par article (§4.2, §7).
3. **⚠ Mécanisme générique de caution non spécifié transversalement** : patinoire et piscine dupliquent
   aujourd'hui le même patron (mouvement de caisse/régie dédié) sans spec M2/M6 commune — candidat
   fort à une **mutualisation** (module `caution` générique consommé par les deux verticales) (§4.2,
   §5, §8).
4. **⚠ Restitution partielle d'une paire de patins** : non tranchée par les sources, hypothèse de taux
   réduit à confirmer (§4.3, §7).
5. **⚠ Montant de caution initial et délai avant non-restitution constatée** : non chiffrés, paramétrables
   par établissement par hypothèse (§4.2, §4.4, §7).
6. **⚠ Algorithme de « pointure voisine » et paramètres de la liste d'attente** (canal de notification,
   délai de réponse) : non détaillés par les sources (§4.5, §7).
7. **⚠ Affûtage sur un article du parc de location lui-même en dehors du cycle de maintenance planifié**
   : cas non couvert explicitement, distinction stricte prestation_client/maintenance_parc à confirmer
   (§4.6, §7).
8. **⚠ Procédure de résolution d'un chevauchement de créneaux glace** : la tolérance est actée (pas de
   blocage automatique), mais aucune procédure de résolution n'est spécifiée — relève de la gestion
   humaine (§4.8, §7).
9. **⚠ GAP M1 — Bascule automatique de statut produit par date** : le cycle de vie produit générique de
   M1 est manuel, sans job de bascule temporisée ; la fermeture automatique de vente/accès hors fenêtre
   de saison (RG-PAT-04) nécessite une extension à arbitrer avec M1 (§4.9, §7).
10. **⚠ HYPOTHÈSE — Noms des permissions `patinoire × …`** : dérivés du tableau Acteurs & droits selon
    le modèle socle, à figer avec M8 comme pour `acces`/`piscine` (§3).
11. **⚠ Statut hors-backlog des US-PATIN-01 à 10** : ces user stories sont définies par cette spec, pas
    par `backlog.html` ; à faire valider/prioriser par le product owner avant tout plan
    d'implémentation (voir en-tête).
