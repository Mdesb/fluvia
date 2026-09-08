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

## Lot 3 — absorber le musée : PLAN (chantier coordonné, décidé par Maxime le 08/09)

⚠ Verticale **partagée** (OWNERS : « verticales … Musée … ») + **données vivantes** + NF525 en aval
(reversements/ventes) → ne pas mener seul, annoncer dans COORDINATION avant d'écrire.

**Ce qui est tissé dans le musée et que `App\Group` n'a PAS** (mesuré — usages de `DossierGroupeScolaire`) :
`Gratuite` + `ContingentGratuite` + `AccorderGratuiteHandler` (gratuités scolaires par contingent),
les reversements OTA, `PerimetreMuseeExtension`, l'audit. Et une **divergence de modèle de fond** :
`ConfirmerDossierGroupeHandler` crée **une `Reservation` par visiteur** (grain visiteur, pour le billet
/ contrôle d'accès individuel — décision structurante n°2 du plan musée), là où `App\Group` crée **une**
`Reservation` `quantity=N` (grain groupe).

**Deux arbitrages à trancher AVANT de coder** (produit, pour Maxime) :
1. **Gratuités/contingents** : les rendre transverses dans `App\Group` (si d'autres métiers en ont
   besoin) ou les garder en couche musée au-dessus de `GroupBooking` ?
2. **Grain de réservation** : billet par personne (musée) vs ligne groupe (`App\Group`) — lequel fait foi ?

**Mapping `DossierGroupeScolaire` → `App\Group`** : `etablissementScolaire` → `ParticipantGroup.label`
(+ `type=School`) ; `effectif`/`accompagnateurs` → `GroupBooking` ; `creneauEntree` → `creneau` ;
`dateOption` → `optionExpiresAt` ; `venteRattachee` → `venteRattachee` ; `statutPaiement` → `status`
+ `paymentStatus` (mapping à écrire) ; `guidesAffectes` → pas d'équivalent (garder côté musée).

**Migration de données (write-only, idempotente, rejouable, avec témoin de comptage)** : pour chaque
dossier non encore migré, créer `ParticipantGroup` + `GroupBooking` ; ne jamais supprimer ni écraser un
dossier. Marquer les dossiers migrés (champ côté musée ou table de correspondance) pour l'idempotence.

**Direction de dépendance** : `Musee` → `App\Group` (Musée consomme ; jamais de FK `App\Group` → `Musee`).

**Ordre** : (1) trancher les 2 arbitrages ; (2) porter dans `App\Group` ce qu'ils imposent ; (3) écrire
la migration + témoins ; (4) rebrancher `CreerDossierGroupeProcessor` / écrans musée sur `App\Group` ;
(5) déprécier `DossierGroupeScolaire` (garder la table le temps de valider), puis retirer.
