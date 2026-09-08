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

## Reste à faire (lots suivants)

- **Lot 2 — facturation** : brancher `GroupBooking` → `Vente`/`Facturation` (bon de commande, mandat,
  tiers-payeur via `payer`) ; poser `paymentStatus` par l'acte de facturation plutôt qu'à la main.
- **Lot 3 — absorber le musée** : faire consommer `App\Group` par `Musee`, migrer `DossierGroupeScolaire`.
- **Tarif groupe** : grille tarifaire par catégorie de participant (enfant/adulte/accompagnateur).
- **Impact jauge** : décompter l'effectif d'un `GroupBooking` sur la capacité du `Creneau`.
