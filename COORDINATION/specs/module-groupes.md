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

## Reste à faire — conception (ancrée dans le code lu)

- **Lot 2 — tarif + facturation (atomique)**. Ne peut pas se livrer en moitiés : toute route CRUD neuve
  sans écran rouvre l'écart n°15 que Lot 1 a refermé (524/524).
  - `GroupRate` (établissement, `activite` nullable = défaut, `category`, `prixUnitaireHT`, `tauxTva`
    → `App\Compta\Entity\TauxTva`, actif) + CRUD + entrée dans `GroupScopeExtension` + migration + écran.
  - Service `GroupBookingPricer` : compte par catégorie (liste nominative sinon effectif→adulte +
    accompagnateurs→accompagnateur), applique la `GroupRate` (activité puis défaut), **repli** sur
    `activite.tarifReferenceMontant` × effectif (option 1 de Maxime).
  - `InvoiceGroupBookingProcessor` : `POST /group/bookings/{id}/invoice` → crée un `CommercialDocument`
    (`DocumentNature::Quote`) en reprenant `CreateDocumentProcessor` : destinataire mappé depuis
    `payer` (Client → raisonSociale/nom/siret/adresse), lignes du pricer, **chaque ligne porte un
    `TauxTva` du profil de l'établissement actif** (RG-SOCLE-05, vérifié dans `CreateDocumentProcessor::taux`).
    Nouvelle FK `commercialDocument` sur `GroupBooking`, `paymentStatus` avancé, bouton « Facturer ».
  - ⚠ Le devis (Quote) n'est PAS scellé NF525 — c'est le chemin sûr ; la facture scellée reste dérivée
    dans la chaîne Facturation existante.
- **Impact jauge** : à la confirmation, créer **une** `Reservation` (quantity = effectif+accompagnateurs)
  sur le `creneau` (la jauge somme `Reservation.quantity`, D16/ACT-1) — exige un `Beneficiaire`
  responsable saisi à la confirmation, comme le fait le musée (`ConfirmerDossierGroupeHandler`).
- **Lot 3 — absorber le musée** : faire consommer `App\Group` par `Musee`, migrer les
  `DossierGroupeScolaire` existants. Migration de **données vivantes** d'un module qu'une autre session
  peut toucher → en dernier, avec soin.
