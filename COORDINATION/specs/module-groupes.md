# Module « groupes de participants » (`App\Group`) — transverse

*Session groupes (allaccess-d6), 08/09/2026. Branche `feature/module-groupes` (poussée, non fusionnée — gel GitHub).*

## Ce que Maxime a demandé (cadrage AskUserQuestion)

- **Modèle** : « de tout là-dedans » — un groupe est à la fois un objet réutilisable (contact
  organisateur + liste de participants), quelque chose qu'on **réserve**, et qui porte un **tarif** groupe.
- **Opérations** : les quatre — créer/gérer un dossier, affecter à une activité, facturer, liste de participants.
- **Métiers** : **tous**, y compris ceux à venir (Maxime cite l'accrobranche). → le module ne code aucun
  vertical en dur ; il s'accroche au socle `App\Reservation` (`Activite` / `Creneau`).
- **Existant musée** : **généraliser / absorber** `Musee\DossierGroupeScolaire`.

⚠ RIEN À VOIR avec `App\Organisation\Entity\Groupe` (le locataire). Classes en anglais (D5) pour lever
l'ambiguïté : `ParticipantGroup`, `GroupParticipant`, `GroupBooking`.

## Lot 1 — livré (ce commit)

**Serveur** (`app/src/Group/`)
- `ParticipantGroup` (le groupe réutilisable : label, type, organisateur, `client` B2B facultatif, effectif, liste).
- `GroupParticipant` (membre nominatif ; cloisonné par son groupe, pas de colonne établissement).
- `GroupBooking` (la réservation : vise un `Creneau`/`Activite` de `App\Reservation` ; statut option→confirmée→annulée ; paiement différé).
- Cloisonnement RG-SOCLE-05 : `GroupScopeExtension` (les 3 entités), estampillage établissement depuis la
  session (D41), **gardes explicites de périmètre dans les processeurs** (`find()` ne passe pas par l'extension).
- Manifeste `GroupModule` : `capability()` = `null` (service **transverse**, pas une verticale vendue),
  permissions `group.read` / `group.manage`.
- Fixtures dédiées (`GroupFixtures`) : rôle « Gestionnaire de groupes », données de démo, **témoin de
  cloisonnement** sur l'établissement B.
- Migration à la main `Version20260908093000` (3 tables `group_*`), exécutée pour vérification.
- ⚠ `#[ApiFilter]` d'un module neuf : **muets** tant que `src/Group/Entity` n'est pas dans
  `api_platform.yaml > mapping.paths` (ajouté).

**Front** (`frontend/src/pages/Groupes.jsx`)
- Écran maître-détail : liste des groupes, fiche (participants + réservations), tous les gestes.
- Entrée de menu « Groupes » (`group.read`), icône, route, 16 helpers `client.js` (toutes les opérations atteignables).

**Vérifs** : 9 tests API (56 assertions) — cloisonnement, refus de rattachement hors périmètre, cycle de
réservation, **discrimination du filtre `?group=`**. Garde-fous : **54/54** (pre-commit vert).
⚠ **L'écran n'a pas encore tourné dans un navigateur contre l'API réelle** — à faire avant de le déclarer atteignable pour de bon.

## Vérification à l'exécution (08/09) — l'écran a tourné pour de vrai

Montage auto-suffisant (préprod = `main`, sans mon code) : backend du worktree servi (`php -S`, APP_ENV=test
→ `app_testgroupes`, clés JWT de test) + Vite → tunnel SSH → navigateur. Constaté : la liste ne montre que
le groupe de l'établissement A (cloisonnement en vrai), la fiche affiche participants + réservations, la
**création d'un groupe par l'UI aboutit** (`POST → 201`, aucun 415 sur l'écriture `ld+json`), et le journal
réseau ne rend que des 200/201 sur les routes `group*` (le filtre `?group=` discrimine). Conteneurs
ad hoc arrêtés ; `groupes-db` conservé.

## Décisions de Maxime (08/09) pour la suite

- **Facturer un groupe = générer un DEVIS** pour le payeur, qui suit la chaîne existante
  devis → bon de commande → facture NF525 (l'écran Facturation). On ne forge pas le scellé.
- **Base du montant = les DEUX** : tarif de référence de l'activité × effectif **et** une grille
  tarifaire GROUPE par catégorie (à construire).

## Lot 2 — facturation (devis) : LIVRÉ (commit `4f22ca39`, poussé)

`POST /group/bookings/{id}/invoice` → `InvoiceGroupBookingProcessor` crée un `CommercialDocument`
(`DocumentNature::Quote`) pour le payeur (`payer`, à défaut `group.client`), en reprenant la logique de
`CreateDocumentProcessor` : destinataire mappé depuis le `Client`, ligne effectif × prix, **taux de TVA
confronté au profil de l'établissement actif** (RG-SOCLE-05). Montant = tarif de référence de l'activité
(option 1) ou `prixUnitaireHT` du corps. FK `GroupBooking.commercialDocument` (→ `billing_document`),
`paymentStatus` → `purchase_order`, refus si annulée / déjà facturée / sans payeur. Front : bouton
« Facturer » (modale choix TVA + prix) → badge « Devis ». Le devis (Quote) n'est PAS scellé NF525 ; la
facture scellée reste dérivée dans la chaîne Facturation. **11 tests (70 assertions), garde-fous 54/54,
et vérifié à l'exécution** (UI → `POST → 201`, « Devis créé. », paiement « Bon de commande »).

## Tarif « produit groupe » composite : LIVRÉ (commit `07f3b819`)

Arbitrage Maxime « un produit groupe = un mix de produits » (5 audioguides + 3 entrées + 5 visites),
« les deux » : `GroupProduct` (+ `GroupProductLine` imbriquées) = forfait réutilisable appliqué via
`apply-product` ; `GroupBookingItem` = panier (forfait ou à la carte) ; la facturation fait une ligne
de devis par article, chacune sa TVA. Remplace l'idée « grille par tête ». Vérifié à l'exécution.

## Impact jauge : LIVRÉ (commit `97dcf361`)

La confirmation d'une réservation qui vise un créneau crée UNE `Reservation` socle
(`quantity` = effectif + accompagnateurs), refuse si jauge insuffisante (409), et libère à
l'annulation (`AnnuleeLibre`). Responsable = corps `responsable` ou bénéficiaire du client du groupe.
FK `GroupBooking.jaugeReservation`.

## Lot 3 — absorber le musée : PHASE A LIVRÉE · PHASE B à coordonner (décidé par Maxime le 08/09)

⚠ Verticale **partagée** (OWNERS : « verticales … Musée … ») + **données vivantes** + NF525 en aval
(reversements/ventes) → ne pas mener seul, annoncer dans COORDINATION avant d'écrire.

**Ce qui est tissé dans le musée et que `App\Group` n'a PAS** (mesuré — usages de `DossierGroupeScolaire`) :
`Gratuite` + `ContingentGratuite` + `AccorderGratuiteHandler` (gratuités scolaires par contingent),
les reversements OTA, `PerimetreMuseeExtension`, l'audit. Et une **divergence de modèle de fond** :
`ConfirmerDossierGroupeHandler` crée **une `Reservation` par visiteur** (grain visiteur, pour le billet
/ contrôle d'accès individuel — décision structurante n°2 du plan musée), là où `App\Group` crée **une**
`Reservation` `quantity=N` (grain groupe).

**Arbitrages tranchés par Maxime (08/09)** :
1. **Gratuités/contingents → TRANSVERSES** dans `App\Group` (réutilisables par tous les métiers). À
   construire : `GroupGratuiteContingent` (établissement, quota, motif) + `GroupGratuite` (une entrée
   gratuite rattachée à une réservation, décomptée du contingent), un handler d'octroi repris de
   `AccorderGratuiteHandler`, et l'intégration facturation (une gratuité = hors du décompte payant du
   devis). Le musée consommera ces gratuités transverses au lieu des siennes.
2. **Grain de réservation → PAR RÉSERVATION** : `GroupBooking.grain` (enum `per_group` défaut /
   `per_person`), choisi à la création. À la confirmation : `per_person` crée **N** `Reservation`
   (quantity 1, une par visiteur — pour le billet / l'accès individuel) ; `per_group` crée **1**
   `Reservation` (quantity N). ⚠ Le lien `jaugeReservation` (FK unique, livré au commit `97dcf361`)
   devient une **collection** (join table) pour porter les N ; l'annulation les libère toutes. Le
   `per_person` réalise pleinement le grain visiteur du musée (billets/accès nominatifs).

**Mapping `DossierGroupeScolaire` → `App\Group`** : `etablissementScolaire` → `ParticipantGroup.label`
(+ `type=School`) ; `effectif`/`accompagnateurs` → `GroupBooking` ; `creneauEntree` → `creneau` ;
`dateOption` → `optionExpiresAt` ; `venteRattachee` → `venteRattachee` ; `statutPaiement` → `status`
+ `paymentStatus` (mapping à écrire) ; `guidesAffectes` → pas d'équivalent (garder côté musée).

**Migration de données (write-only, idempotente, rejouable, avec témoin de comptage)** : pour chaque
dossier non encore migré, créer `ParticipantGroup` + `GroupBooking` ; ne jamais supprimer ni écraser un
dossier. Marquer les dossiers migrés (champ côté musée ou table de correspondance) pour l'idempotence.

**Direction de dépendance** : `Musee` → `App\Group` (Musée consomme ; jamais de FK `App\Group` → `Musee`).

**Ordre (arbitrages tranchés)** :
- **Phase A — LIVRÉE** (dans `App\Group`, additif, aucune touche au musée) :
  - grain par réservation — `GroupBooking.grain` (`per_group` / `per_person`) + collection
    `jaugeReservations` (l'ancien FK unique devient une join table) + confirm branché N vs 1,
    l'annulation libère les N. Commit `16cb4403` (A1).
  - gratuités transverses — `GroupGratuiteContingent` (enveloppe/établissement) + `GroupGratuite`
    (octroi depuis un contingent, refus au-delà du quota, révocation qui recrédite) ; hors du
    décompte payant du devis (`nbPayants = effectif − gratuités`). Commit `1a4b6080` (A2).
  - 18 tests / 136 assertions, garde-fous 54/54, poussé sur `feature/module-groupes`.
- **Phase B — verticale musée PARTAGÉE, à coordonner** : migration de données (write-only, idempotente,
  témoins) ; puis rebrancher `CreerDossierGroupeProcessor` / écrans musée sur `App\Group` ; puis
  déprécier `DossierGroupeScolaire` (garder la table le temps de valider), puis retirer. Un mot dans
  COORDINATION avant d'ouvrir la Phase B.


## Phase B — analyse détaillée (08/09) : couplage mesuré et fourche à trancher

Toutes les références à `DossierGroupeScolaire` mesurées (`grep -rn` sur `src/` + `frontend/`) :

- **Entité + API** : `Musee\Entity\DossierGroupeScolaire` (POST `/musee/dossiers-groupe`, `{id}/confirmer`).
- **Confirmation** (`ConfirmerDossierGroupeHandler`) : crée **une `Reservation` socle par visiteur** —
  `nbPayantes = total − gratuités` en `modeDecompte=VenteUnite` (`montantDu=0.00`, **différé** : aucune
  `Vente` tant que `statutPaiement ≠ paye`, RG-MUS-03), et `gratuités` en `modeDecompte=Gratuit`.
- **Gratuités** (`AccorderGratuiteHandler`, `Gratuite`, `ContingentGratuite`) : chaque gratuité porte un
  `motif` (élève / accompagnateur), consomme le contingent **et** la jauge du créneau (une `Reservation`
  `Gratuit`), et est reliée à sa `Reservation` (`reservationRattachee`).
- **Cloisonnement** : `PerimetreMuseeExtension` (`{root}.etablissement`).
- **Audit** : le dossier est inscrit dans `Audit\Doctrine\AuditWriteSubscriber`.
- **Aval argent** : tests `Facturation/`, `Recouvrement/`, `RevenueRecovery/` touchent la chaîne du
  dossier ; `venteRattachee` fait le lien NF525.
- **Frontal** : une section de `frontend/src/pages/Musee.jsx` (liste + création + confirmation) et 3
  aides `client.js` (`museeDossiersGroupe`, `museeCreerDossierGroupe`, `museeConfirmerDossierGroupe`).

**Ce que `App\Group` modélise déjà** : grain (`per_person` = un billet par visiteur ✓), contingents de
gratuité transverses ✓, facturation en devis ✓. **Ce qu'il ne modélise PAS encore** : la distinction
`VenteUnite` / `Gratuit` **par réservation**, la règle « aucune `Vente` tant que non payé » (paiement
différé), et le `motif` de gratuité (élève/accompagnateur) + le lien gratuité→réservation.

### La fourche (à trancher avant le rebranch — elle touche l'argent / NF525)

- **B2 (migration additive)** est **indépendante** de la fourche : elle ne fait que MIROITER les champs
  stockés du dossier (`etablissementScolaire`→label+School, `effectif`/`accompagnateurs`,
  `creneauEntree`, `dateOption`→optionExpiresAt, `statutPaiement`→status+paymentStatus, `venteRattachee`
  tel quel) dans `ParticipantGroup`+`GroupBooking`, sans toucher dossiers/réservations/ventes. Sûre,
  rejouable, réversible.
- **B3 (rebranch)** dépend de la fourche : soit on **étend `App\Group`** pour préserver toute la
  sémantique argent du musée (superset : modeDecompte par visiteur + gratuités motivées + différé), soit
  on **simplifie** (le modèle `App\Group` prime, le musée s'y aligne — décision produit sur l'argent).

### Avancement Phase B (08/09)

- **B1 — sémantique argent dans `App\Group` : FAIT** (commit `7b4b3f7a`). La confirmation répartit
  les entrées par mode : payantes en `vente_unite` (`montantDu 0.00`, différé, aucune `Vente`), gratuites
  en `gratuit`. Fin du défaut « tout en gratuit ». Test `testConfirmationRepartitPayantEtGratuit`.
- **B2 — migration miroir : FAIT** (commit `20720532`). Commande `musee:dossiers:migrer-vers-group`
  (write-only, idempotente via `GroupBooking.sourceMuseeDossierId`, rejouable, réversible). Vit dans
  `Musee` (Musee → App\Group). Test de miroir fidèle + idempotence.
- **B3 — rebranch : FAIT** (commit `62f772d8`, arbitrage « absorption complète »). La section « dossiers scolaires » de `Musee.jsx` est retirée et renvoie vers l'écran Groupes ; `DossierGroupeScolaire` / `ContingentGratuite` / `Gratuite` ne sont plus exposés en API (entités+tables conservées) ; processeurs/handlers retirés ; `GratuiteScolaireTest` retiré (CA-5/CA-6 couverts côté Groupes). Musée 22/156, Groupes 20/167, garde-fous 54/54.
- ~~B3 (plan initial)~~ : Rerouter création / confirmation / liste des dossiers musée (backend
  `CreerDossierGroupeProcessor` + front `Musee.jsx` + 3 aides `client.js`) sur `App\Group`, migrer les
  tests musée (`GratuiteScolaireTest`…). CHANGE une verticale partagée + argent → laisser à la session
  qui tient le musée le temps de répondre au mot COORDINATION avant d'y toucher ; effort focalisé.
- **B4 — dépréciation `DossierGroupeScolaire` : RESTE**, en dernier, table conservée le temps de valider.

Mapping paiement retenu (B2) : `en_option`→(Option,Pending) · `bon_commande_emis`→(Confirmed,PurchaseOrder)
· `mandat_emis`→(Confirmed,PurchaseOrder) · `paye`→(Confirmed,Paid). Grain du miroir = `per_person`.
