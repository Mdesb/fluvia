# Plan technique — Verticale Centre de Padel (`Padel` / lot post-MVP, V2 L16, hors ordre L0→L7)

- **Spec source :** specs/padel/spec-padel.md
- **Stack :** Symfony 7 · API Platform · Doctrine/MariaDB
- **Couvre :** US-PADEL-01 à 10 · RG-PADEL-01 à 07 · CA-1 à CA-14

> **Dépendance structurante — `App\Reservation` (M5, `specs/reservation/`)** — Ce plan **suppose que le
> module socle `App\Reservation\*`** (créneau, chevauchement, no-show, paiement partagé, récurrence,
> liste d'attente, `RegleAnnulation`) est **implémenté** selon `specs/reservation/plan-reservation.md`
> **avant** ce lot Padel (T1-T31 de `specs/reservation/tasks-reservation.md`). À la date de rédaction,
> `App\Reservation\*` **n'existe pas encore** dans `app/src` (seuls spec+plan existent) — **c'est le
> principal risque d'ordonnancement de ce plan** (Risque n°1). Padel est le **premier consommateur
> concret** de ce socle (avant Piscine, dont la réconciliation est différée — `plan-reservation.md` §7).
>
> **Ce que Padel réutilise intégralement, sans y toucher (`App\Reservation\*`, zéro fichier modifié) :**
> `Ressource` (terrain = `Ressource(codeType='terrain_padel')`, coach = `Ressource(codeType=
> 'coach_padel', competenceRequise='coach_padel')`), `Creneau` (60/90 min, chevauchement RG-M5-03),
> `Reservation` (statuts, `modeDecompte=vente_unite`, `dateLimiteAnnulation`), `ParticipantReservation`
> (paiement partagé, solidarité organisateur — RG-M5-10/RG-PADEL-04), `RegleAnnulation` (no-show/
> annulation tardive paramétrable — RG-M5-09/RG-PADEL-06), `Recurrence`/`RegleConflitRecurrence`
> (report auto/validation manuelle — RG-M5-11/RG-PADEL-07), `ListeAttente`, `ProjectionAccesReservation`
> (badge sur fenêtre réservée — RG-M5-12/RG-PADEL-05 volet accès), `FacturationNoShow`, `Emargement`,
> le port `FormuleBeneficiaireInterface` (statut membre/non-membre, hérite Risque n°5 du plan
> réservation), les `State\*Processor`/handlers exposés par l'API `reservation.*`.
>
> **Ce que Padel ajoute, propre à la verticale (`App\Padel\*`), détaillé §1-§2 :** tarification pleine/
> creuse × membre (`PlageHoraire`/`GrilleTarifaireTerrain`, extension **locale** documentée §1.2, RG-
> PADEL-02), matching de joueurs et complétion partielle 3/4 (`ReservationPadel` overlay, RG-PADEL-03),
> niveau de jeu validé club (`NiveauJoueur`, US-PADEL-04), tournois/poules/scores (`Tournoi` et
> satellites, US-PADEL-05/06/07), location de matériel + caution locale (`LocationMateriel`/
> `CautionMateriel`, US-PADEL-08 — **aucune capacité "caution" socle n'existe** à ce jour, même posture
> que `App\Piscine\Entity\CautionCasier`), réservation avec coach (overlay `ReservationPadel`, US-PADEL-
> 09), et le **relais d'éclairage** — **capacité réellement nouvelle** (port matériel enfichable
> `App\Padel\Port\PiloteEclairage`, patron `App\Acces\Port\PiloteAcces`/`SimulateurAccesAdapter`,
> US-PADEL-10).
>
> **M1 Offre / M2 Vente (référencées, non modifiées)** — `App\Offre\Entity\{Produit,TypeTarif}` (code
> réel) : un `Produit` par établissement (type `terrain_padel`, catalogue à paramétrer, hors périmètre
> code) sert d'**ancre** de ligne de vente ; le prix réel (pleine/creuse × membre) est **forcé**
> (`LigneVente.prixForce=true`) par `CalculateurTarifTerrainHandler` — voir §1.2 pour la justification
> détaillée de ce choix (résout le point ouvert §4.2/§7 de la spec **sans étendre** `App\Offre\*`).
> `App\Vente\Entity\{Vente,LigneVente}` (code réel) : la vente à l'unité d'un créneau, d'une location de
> matériel ou de frais de tournoi passe par la création standard d'une `Vente`/`LigneVente`, aucune
> modification.

---

## 0. Décisions structurantes (résumé)

1. **Le terrain est une `Ressource` du socle Réservation, jamais réifié en entité Padel dupliquée** —
   `App\Padel\Entity\TerrainPadel` est un **overlay 1:1** (comme `App\Sport\Entity\StatutAccesFitness`
   sur `DroitAcces`) qui ne porte **que** les champs absents du modèle générique (`type` indoor/outdoor,
   `duréesAutoriséesMinutes`). Le coach n'a **même pas** d'overlay : c'est une `Ressource` nue
   (`codeType='coach_padel'`).
2. **`InscriptionPartie` (spec §5) est éliminée au profit de `App\Reservation\Entity\ParticipantReservation`**
   — le paiement partagé, le statut de paiement par joueur et la solidarité organisateur sont **déjà**
   portés par cette entité socle (RG-M5-10) ; créer un objet Padel parallèle aurait dupliqué une
   capacité déjà livrée (constitution §4 point 4). Seuls les champs **de matching** (niveau visé,
   statut de la partie) restent propres à Padel, portés par l'overlay `ReservationPadel` (§1.3).
3. **Tarification pleine/creuse × membre : extension locale assumée, pas de modification de `App\Offre\*`**
   — le modèle `GrilleTarifaire` M1 (produit × type de tarif × **saison de dates**) n'a pas d'axe
   horaire intrajournalier (spec §4.2/§7, point ouvert n°3). Plutôt que d'étendre M1 (risque de
   régression sur un module déjà codé et utilisé par 5+ autres verticales) ou de dupliquer le moteur de
   vente, Padel introduit `PlageHoraire`/`GrilleTarifaireTerrain` **localement** et **force le prix** de
   la `LigneVente` (`prixForce=true`, mécanisme déjà prévu par `App\Vente\Entity\LigneVente`, droit
   `vente.forcer_prix` accordé à l'acteur système du calcul). Documenté comme **risque de duplication de
   modèle à surveiller** (Risque n°2) — si Musée ou une autre verticale a un jour besoin d'un axe
   horaire, une factorisation M1 devra être arbitrée (même posture que le risque SEPA de `plan-sport.md`
   Risque n°5).
4. **Répartition du surcoût « maintenue à 3 »** — équitable entre les 3 joueurs présents par défaut,
   **paramétrable** (`ParametragePadel.modeRepartitionSurcout`, un seul mode implémenté dans ce lot :
   `equitable_presents`, champ prévu pour extension future) — décision produit §4.3/§7 point 4 de la
   spec, tranchée conformément à l'énoncé de mission.
5. **Relais d'éclairage : port + adaptateur simulateur, protocole matériel hors périmètre** — même
   posture que `App\Acces\Port\PiloteAcces`/`SimulateurAccesAdapter` (lu, patron réel du dépôt) :
   interface métier agnostique du transport, adaptateur par défaut en mémoire, protocole réel (contact
   sec/IoT/domotique) **non spécifié** (spec §4.9 point ouvert n°10, Risque n°4).
6. **Caution matériel : entité locale Padel, pas de capacité socle** — aucun objet « Caution » socle
   n'existe (M2/M6 ne le portent pas) ; `App\Padel\Entity\CautionMateriel` suit **exactement** le patron
   de `App\Piscine\Entity\CautionCasier` (colonne dénormalisée d'unicité `locationActive`). Même risque
   de duplication future documenté par Piscine (Risque n°6, à factoriser un jour si une 3ᵉ verticale a
   besoin d'une caution).
7. **`NiveauJoueur` scopé par établissement, pas strictement 1:1 joueur** — la spec (§5) annonce un
   niveau 1:1 par joueur, mais un joueur peut fréquenter plusieurs clubs (établissements) dont chacun
   valide indépendamment (RG-SOCLE-01, cloisonnement) ; ce plan retient **1 `NiveauJoueur` par couple
   (joueur, établissement)** — écart mineur documenté vs. la spec, à confirmer produit (Risque n°7).
8. **Sécurité : toutes les opérations exposées côté Padel sont gardées par des permissions `padel.*`**
   (conforme à la mission) ; les processors Padel appellent les **services** du socle Réservation
   directement en interne (pas de second appel HTTP re-vérifiant `reservation.*`), à l'image de la façon
   dont `App\Sport\Service\PropagationAccesFitnessHandler` écrit directement sur `DroitAcces` sans
   repasser par l'API L3.

---

## 1. Entités & schéma

Namespace : **`App\Padel\Entity\*`**. `id` = UUID (`Symfony\Component\Uid\Uuid`, type Doctrine `uuid`).
`declare(strict_types=1)` partout. Noms métier en français. Toute entité racine porte (directement ou
via une relation dénormalisée) un rattachement **Établissement** (RG-SOCLE-01), cloisonnée par
`App\Padel\Doctrine\PerimetrePadelExtension` (même pattern que L1/L2/L3/L5/L6/Sport).

### 1.1 Enums (`App\Padel\Enum\*`)

| Enum | Valeurs | Notes |
|---|---|---|
| `TypeTerrain` | `indoor`, `outdoor` | §4.1 |
| `LibellePlageHoraire` | `pleine`, `creuse` | RG-PADEL-02 |
| `StatutJoueurTarif` | `membre`, `non_membre` | dérivé via `FormuleBeneficiaireInterface` (socle) |
| `ModeRepartitionSurcout` | `equitable_presents` | extensible, défaut et seul mode livré (décision n°4) |
| `StatutPartieOuverte` | `ouverte`, `complete`, `maintenue_a_3` | nul si la réservation n'est pas une partie ouverte |
| `StatutNiveauJoueur` | `propose`, `valide` | US-PADEL-04 |
| `FormatTournoi` | `poules`, `tableau` | US-PADEL-05 |
| `StatutTournoi` | `ouvert_inscriptions`, `en_cours`, `termine` | — |
| `StatutPaiementInscriptionTournoi` | `paye`, `en_attente` | frais par paire |
| `StatutMatchTournoi` | `a_jouer`, `joue` | US-PADEL-06 |
| `StatutRetourMateriel` | `en_cours`, `rendu`, `non_rendu` | US-PADEL-08 |
| `StatutCautionMateriel` | `encaissee`, `liberee`, `retenue` | patron `Piscine\Enum\StatutCaution`, dupliqué localement (namespace propre au module) |
| `StatutRelaisEclairage` | `operationnel`, `en_defaut` | RG-PADEL-05 |
| `ActionEclairage` | `allumage`, `extinction` | — |
| `StatutEvenementEclairage` | `ok`, `echec_repli_manuel` | — |
| `ModeRepliEclairage` | `manuel` | seul mode livré |

### 1.2 Terrain & tarification (US-PADEL-01, RG-PADEL-01/02)

| Entité | Champ | Type Doctrine | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **TerrainPadel** (`padel_terrain`) *(overlay 1:1)* | id | uuid | non | PK | — |
| | ressource | `OneToOne` → `App\Reservation\Entity\Ressource` | non | unique | **existant, réutilisé** — `codeType='terrain_padel'` |
| | type | string(10) enum `TypeTerrain` | non | requis | §4.1 |
| | dureesAutoriseesMinutes | json (`list<int>`, ⊆ {60,90}) | non | ≥ 1 élément | paramétrable par terrain |
| **ParametragePadel** (`padel_parametrage_etablissement`) | id, etablissement | uuid, `OneToOne` → `Etablissement` | non | unique | 1 par établissement, patron `PolitiqueAntiImpayes` (Sport) |
| | produitTerrainRef | uuid | non | réf. logique `App\Offre\Entity\Produit` (catalogue « terrain padel », hors code) | ancre `LigneVente.produit` |
| | typeTarifMembreRef, typeTarifNonMembreRef | uuid, uuid | non | réf. logique `App\Offre\Entity\TypeTarif` | ancre `LigneVente.typeTarif` |
| | echelleNiveauMin, echelleNiveauMax | smallint, smallint | non | min < max, défaut 1/10 | §4.4, échelle paramétrable |
| | modeRepartitionSurcout | string(20) enum `ModeRepartitionSurcout` | non, défaut `equitable_presents` | décision n°4 | — |
| | toleranceEntreeBadgeMinutes | smallint | oui | ≥ 0 | défaut passé à `ProjectionAccesReservation` (socle) |
| **PlageHoraire** (`padel_plage_horaire`) | id, etablissement | uuid, `ManyToOne` → `Etablissement` | non | index | axe de tarification propre à Padel (décision n°3) |
| | libelle | string(8) enum `LibellePlageHoraire` | non | requis | RG-PADEL-02 |
| | heureDebut, heureFin | `time` | non | début < fin | — |
| | joursApplicables | json (`list<int>` 1-7) | non | ≥ 1 | — |
| **GrilleTarifaireTerrain** (`padel_grille_tarifaire_terrain`) | id, terrain | uuid, `ManyToOne` → `TerrainPadel` | non | index | RG-PADEL-02 |
| | plageHoraire | `ManyToOne` → `PlageHoraire` | non | — | — |
| | statutJoueur | string(11) enum `StatutJoueurTarif` | non | requis | — |
| | dureeMinutes | smallint | non | ∈ {60,90} | — |
| | prix | decimal(10,2) | non | ≥ 0 | — |
| | *(contrainte)* | — | — | **unique** (terrain, plageHoraire, statutJoueur, dureeMinutes) | — |

> **Pourquoi le prix est « forcé » et pas porté par une nouvelle grille M1** — `CalculateurTarifTerrainHandler`
> (§4) résout `(terrain, plageHoraire(créneau.début), statutJoueur(joueur))` → `prix`, puis crée la
> `LigneVente` avec `produit=ParametragePadel.produitTerrainRef`, `typeTarif=…MembreRef`/`…NonMembreRef`,
> `prixUnitaire=prix calculé`, `prixForce=true`. Le `Produit`/`TypeTarif` M1 ne servent que d'**ancre
> catalogue/comptable** (traçabilité, TVA, export compta) — la **résolution du montant** est **entièrement
> propre à Padel**. Aucune ligne `off_grille_tarifaire` (saison) n'est créée ni lue pour ce calcul.

### 1.3 Réservation & matching (US-PADEL-01/02/03/09, RG-PADEL-01/03)

| Entité | Champ | Type | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **ReservationPadel** (`padel_reservation`) *(overlay 1:1)* | id | uuid | non | PK | — |
| | reservation | `OneToOne` → `App\Reservation\Entity\Reservation` | non | unique | **existant, réutilisé** |
| | avecCoach | bool | non, défaut false | — | US-PADEL-09 |
| | coachRessource | `ManyToOne` → `App\Reservation\Entity\Ressource` | oui | requis si `avecCoach` | `codeType='coach_padel'` |
| | reservationCoach | `ManyToOne` → `App\Reservation\Entity\Reservation` | oui | requis si `avecCoach` | 2ᵉ réservation socle créée en miroir (§4.8) |
| | ouverte | bool | non, défaut false | — | RG-PADEL-03 |
| | niveauViseMin, niveauViseMax | smallint, smallint | oui | requis si `ouverte` | filtrage §4.3 |
| | statutPartie | string(14) enum `StatutPartieOuverte` | oui | requis si `ouverte` | §4.3 |
| | *(contrainte)* | — | — | **unique** (reservation) | — |

> `placesRestantes` (spec §5) est **dérivé** à la lecture (4 − `count(ParticipantReservation)` de la
> `Reservation` liée), pas persisté — évite une désynchronisation, patron `Ressource.occupationCourante`
> mis à part (celui-là *doit* être un compteur maintenu pour la jauge temps réel ; ici une simple
> requête suffit).

### 1.4 Niveau de jeu (US-PADEL-04)

| Entité | Champ | Type | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **NiveauJoueur** (`padel_niveau_joueur`) | id, joueur, etablissement | uuid, `ManyToOne` → `Beneficiaire` (M4), `ManyToOne` → `Etablissement` | non | **unique** (joueur, etablissement) | décision n°7 |
| | niveau | smallint | non | ∈ [`ParametragePadel.echelleNiveauMin`, `…Max`] | — |
| | statut | string(8) enum `StatutNiveauJoueur` | non, défaut `propose` | — | §4.4 |
| | valideParUtilisateur | `ManyToOne` → `Utilisateur` | oui | requis si `valide` | — |
| | dateValidation | datetime_immutable | oui | requis si `valide` | — |
| **HistoriqueNiveauJoueur** (`padel_historique_niveau`, append-only) | id, niveauJoueur | uuid, `ManyToOne` → `NiveauJoueur` | non | requis | RG-SOCLE-07 |
| | ancienneValeur, nouvelleValeur | smallint, smallint | non | — | — |
| | auteur | `ManyToOne` → `Utilisateur` | non | — | — |
| | horodatage | datetime_immutable | non | — | — |

### 1.5 Tournois & ligues (US-PADEL-05/06/07)

| Entité | Champ | Type | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **Tournoi** (`padel_tournoi`) | id, etablissement | uuid, `ManyToOne` → `Etablissement` | non | index | cahier §4 |
| | nom | string(150) | non | — | — |
| | format | string(8) enum `FormatTournoi` | non | requis | US-PADEL-05 |
| | categorie | string(60) | oui | — | — |
| | niveauRequisMin, niveauRequisMax | smallint, smallint | oui | — | filtre inscription |
| | fraisInscription | decimal(10,2) | non | ≥ 0 | par paire |
| | dateDebut, dateFin | date_immutable | non | fin ≥ début | — |
| | statut | string(20) enum `StatutTournoi` | non, défaut `ouvert_inscriptions` | — | — |
| **Poule** (`padel_poule`) | id, tournoi | uuid, `ManyToOne` → `Tournoi` | non | requis si `format=poules` | générée automatiquement |
| | libelle | string(40) | non | ex. « Poule A » | — |
| **InscriptionTournoi** (`padel_inscription_tournoi`, la « paire ») | id, tournoi | uuid, `ManyToOne` → `Tournoi` | non | requis | US-PADEL-05 |
| | joueur1, joueur2 | `ManyToOne` → `Beneficiaire` ×2 | non | — | — |
| | poule | `ManyToOne` → `Poule` | oui | affecté à la génération | — |
| | statutPaiement | string(11) enum `StatutPaiementInscriptionTournoi` | non, défaut `en_attente` | — | — |
| | venteRattachee | `ManyToOne` → `App\Vente\Entity\Vente` | oui | requis si `paye` | frais §4.5 |
| **MatchTournoi** (`padel_match_tournoi`) | id, tournoi | uuid, `ManyToOne` → `Tournoi` | non | requis | poule ou tableau |
| | poule | `ManyToOne` → `Poule` | oui | requis si `format=poules` | — |
| | tour | smallint | oui | requis si `format=tableau` | 1=finale, 2=demies… |
| | paireA, paireB | `ManyToOne` → `InscriptionTournoi` ×2 | non | — | — |
| | terrain | `ManyToOne` → `TerrainPadel` | non | — | — |
| | reservationBlocage | `ManyToOne` → `App\Reservation\Entity\Reservation` | non | — | créneau système bloqué, §4.5 |
| | score | string(60) | oui | requis si `joue` | libre (ex. « 6-4 3-6 10-8 ») |
| | vainqueur | `ManyToOne` → `InscriptionTournoi` | oui | requis si `joue` | US-PADEL-06 |
| | statut | string(8) enum `StatutMatchTournoi` | non, défaut `a_jouer` | — | — |

> **`ClassementTournoi` n'est pas une table** — dérivé à la lecture par un provider non-Doctrine
> (`ClassementTournoiProvider`, §3), même patron que `App\Piscine\State\PossEtatLiveProvider`/
> `App\Sport\State\TableauBordImpayesProvider` : calculé depuis les `MatchTournoi.statut=joue` d'un
> tournoi, jamais persisté (évite une désynchronisation avec les scores saisis).

### 1.6 Location de matériel & caution (US-PADEL-08)

| Entité | Champ | Type | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **LocationMateriel** (`padel_location_materiel`) | id, reservation | uuid, `ManyToOne` → `App\Reservation\Entity\Reservation` | non | index | rattachée à la réservation du créneau |
| | article | uuid | non | réf. logique `App\Offre\Entity\Produit` (type `location`) | raquette/balles |
| | quantite | smallint | non | > 0 | — |
| | venteRattachee | `ManyToOne` → `App\Vente\Entity\Vente` | oui | — | encaissement location |
| | statutRetour | string(9) enum `StatutRetourMateriel` | non, défaut `en_cours` | — | — |
| **CautionMateriel** (`padel_caution_materiel`) | id, location | uuid, `ManyToOne` → `LocationMateriel` | non | requis | patron **exact** `CautionCasier` (Piscine) |
| | locationActive | uuid | oui | dénormalisé (= `location.id` tant que `statut ≠ liberee`, sinon `null`), **unique** | garantit 1 caution active/location |
| | montant | decimal(10,2) | non | ≥ 0 | — |
| | statut | string(9) enum `StatutCautionMateriel` | non, défaut `encaissee` | — | — |
| | montantRetenu | decimal(10,2) | oui | requis si `retenue` | issu de `GrilleRetenueMateriel` |
| | dateEncaissement, dateLiberation | datetime_immutable ×2 | oui | — | — |
| **GrilleRetenueMateriel** (`padel_grille_retenue_materiel`) | id, etablissement | uuid, `ManyToOne` → `Etablissement` | non | index | §4.7, paramétrable |
| | typeArticle | string(40) | non | ex. `raquette`, `balles` | — |
| | motif | string(20) | non | ex. `perdu`, `casse` | — |
| | montantRetenue | decimal(10,2) | non | ≥ 0 | — |

### 1.7 Éclairage automatique (US-PADEL-10, RG-PADEL-05) `critique — capacité nouvelle`

| Entité | Champ | Type | Null | Contrainte | Notes |
|---|---|---|---|---|---|
| **RelaisEclairageTerrain** (`padel_relais_eclairage`) | id, terrain | uuid, `OneToOne` → `TerrainPadel` | non | unique | §4.9 |
| | identifiantRelais | string(80) | non | requis | opaque, protocole non précisé (Risque n°4) |
| | modeRepli | string(6) enum `ModeRepliEclairage` | non, défaut `manuel` | — | — |
| | statut | string(12) enum `StatutRelaisEclairage` | non, défaut `operationnel` | — | — |
| **EvenementEclairage** (`padel_evenement_eclairage`, append-only) | id, terrain | uuid, `ManyToOne` → `TerrainPadel` | non | index (terrain, horodatage) | traçabilité §4.9 |
| | reservation | `ManyToOne` → `App\Reservation\Entity\Reservation` | oui | requis sauf repli manuel hors réservation | — |
| | action | string(10) enum `ActionEclairage` | non | requis | — |
| | horodatage | datetime_immutable | non | — | — |
| | statut | string(20) enum `StatutEvenementEclairage` | non | requis | — |
| | operateur | `ManyToOne` → `Utilisateur` | oui | requis si repli manuel | RG-SOCLE-07 |
| | motif | string(255) | oui | requis si repli manuel | tracé |

---

## 2. Ports & adaptateurs

### 2.1 Pilotage éclairage — `App\Padel\Port\PiloteEclairage`

```php
interface PiloteEclairage
{
    /** Commande l'allumage/extinction du relais, retourne succès/échec. */
    public function commander(RelaisEclairageTerrain $relais, ActionEclairage $action): ResultatCommandeEclairage;

    /** Vivacité du relais (opérationnel/en défaut), alimente le repli manuel. */
    public function heartbeat(RelaisEclairageTerrain $relais): StatutRelaisEclairage;
}
```

- **Adaptateur par défaut** `App\Padel\Adapter\SimulateurEclairageAdapter` — commande toujours réussie
  en mémoire, `heartbeat()` retourne toujours `operationnel` ; même rôle que
  `App\Acces\Adapter\SimulateurAccesAdapter` (lu, patron réel) pour débloquer dev/tests sans matériel.
  Sélection par alias configurable (`config/services.yaml`), remplaçable sans toucher le domaine.
- **`ResultatCommandeEclairage`** (`App\Padel\Dto`) — DTO propre à Padel (`{ok: bool, message: string}`),
  **dupliqué** localement plutôt que réutilisé depuis `App\Acces\Dto\ResultatCommande` : forme triviale,
  éviter un couplage Padel→Acces sans besoin fonctionnel (constitution §4 : la duplication à éviter
  porte sur les **capacités**, pas sur un DTO de 2 champs).
- **Protocole matériel réel non spécifié** (contact sec, domotique, IoT) — hors périmètre fonctionnel
  de la spec (§4.9 point ouvert n°10) et donc de ce plan ; **Risque n°4**.

### 2.2 Pourquoi ce découpage ne duplique pas le socle

- Le relais d'éclairage n'existe **nulle part ailleurs** dans le dépôt (L3 pilote lecteurs/tourniquets,
  pas des actionneurs) → port neuf légitime, spec §2 le confirme explicitement.
- La tarification/le paiement/le no-show/la récurrence/l'accès badge sont **entièrement** consommés via
  `App\Reservation\*`/`App\Vente\*`/`App\Acces\*` (§0/§1) — **aucun** nouveau port n'est créé pour ces
  capacités.
- La caution matériel réutilise le **patron** Piscine (`CautionCasier`) mais pas son **code** (module
  différent, pas de FK inter-verticales) — cohérent avec l'absence de capacité socle transverse
  « caution » (même diagnostic que `plan-piscine.md`).

---

## 3. API (API Platform)

Toutes ressources Padel : `#[ApiResource]`, `security` via `is_granted('PERM', 'padel.<action>')`
(module `padel`). Cadrage établissement via `ContexteEtablissement` (RG-SOCLE-05). Les processors Padel
appellent en interne les services du socle Réservation (création de `Creneau`/`Reservation`/
`ParticipantReservation`, `PayerPartProcessor`) **sans** repasser par une seconde vérification
`reservation.*` (décision n°8) — sauf mention contraire.

| Ressource | Opérations | `security:` | Groupes | Notes |
|---|---|---|---|---|
| **TerrainPadel** | GET coll/item | `padel.lire` | `terrain:read` | — |
| | POST/PATCH | `padel.gerer_terrain` | `terrain:write` | crée en cascade la `Ressource` socle (`codeType='terrain_padel'`) |
| **ParametragePadel** | GET item ; PATCH | `padel.lire` / `padel.parametrer` | `parametrage:read/write` | 1-1 établissement |
| **PlageHoraire** | GET coll ; POST/PATCH | `padel.lire` / `padel.parametrer` | `plage:read/write` | — |
| **GrilleTarifaireTerrain** | GET coll ; POST/PATCH | `padel.lire` / `padel.parametrer` | `grille:read/write` | — |
| **ReservationPadel** (terrain) | `POST /padel/terrains/{id}/reservations` | `padel.reserver` ou `padel.reserver_soi` | `reservation_padel:read/write` | custom — `ReserverTerrainProcessor` (CA-1/CA-2/CA-10), calcule le tarif (§1.2), crée `Creneau`+`Reservation`+`ParticipantReservation` socle, et la 2ᵉ réservation coach si `avecCoach` |
| | GET coll/item | `padel.lire` ou (`padel.lire_soi` + `JoueurLieVoter`) | `reservation_padel:read` | — |
| **Parties ouvertes** | `GET /padel/parties-ouvertes` | `padel.lire` ou `padel.partie_rejoindre_soi` | `partie:read` | filtrable niveau/date/terrain, provider composant `ReservationPadel.ouverte=true` |
| | `POST /padel/parties-ouvertes/{id}/rejoindre` | `padel.partie_rejoindre_soi` | — | custom — `RejoindrePartieProcessor` (CA-3), ajoute `ParticipantReservation` + paiement (délègue au `PayerPartProcessor` socle) |
| **NiveauJoueur** | GET coll/item | `padel.lire` ou (`padel.lire_soi` + `JoueurLieVoter`) | `niveau:read` | masque le niveau `proposé` non éligible au filtrage (CA-5) |
| | `POST /padel/niveaux/declarer` | `padel.niveau_declarer_soi` | `niveau:write` | custom — crée/actualise le niveau `proposé` du joueur courant |
| | `POST /padel/niveaux/{id}/valider` | `padel.niveau_valider` | — | custom — `ValiderNiveauProcessor` (CA-5), trace `HistoriqueNiveauJoueur` |
| **Tournoi** | GET coll/item ; POST/PATCH | `padel.lire` / `padel.tournoi_gerer` | `tournoi:read/write` | — |
| | `POST /padel/tournois/{id}/generer-poules` | `padel.tournoi_gerer` | — | custom — `GenererPoulesEtBlocageHandler` (CA-6) |
| **InscriptionTournoi** | GET coll ; `POST /padel/tournois/{id}/inscriptions` | `padel.lire` / (`padel.tournoi_inscrire_soi` ou `padel.tournoi_gerer`) | `inscription_tournoi:read/write` | custom — inscrit une paire, frais via M2 |
| **MatchTournoi** | GET coll/item | `padel.lire` | `match:read` | — |
| | `PATCH /padel/matchs/{id}/score` | `padel.tournoi_gerer` | `match:write` | custom — `SaisirScoreHandler` (CA-7) |
| **ClassementTournoi** | `GET /padel/tournois/{id}/classement` | `padel.lire` | `classement:read` | custom, non-Doctrine — `ClassementTournoiProvider` |
| **LocationMateriel** | GET coll ; `POST /padel/reservations/{id}/materiel` | `padel.lire` / (`padel.materiel_gerer` ou `padel.reserver_soi` pour sa propre réservation) | `location:read/write` | custom — `LouerMaterielProcessor` (CA-9), caution si paramétrée |
| | `POST /padel/locations/{id}/retour` | `padel.materiel_gerer` | — | custom — `RetournerMaterielProcessor`, applique `GrilleRetenueMateriel` si `non_rendu` |
| **RelaisEclairageTerrain** | GET coll/item ; POST/PATCH | `padel.lire` / `padel.configurer_eclairage` | `relais:read/write` | — |
| **EvenementEclairage** | GET coll | `padel.lire` | `evenement_eclairage:read` | lecture seule, pilotée système |
| | `POST /padel/terrains/{id}/eclairage/repli-manuel` | `padel.acces_forcer` | — | custom — `ForcerEclairageManuelProcessor` (CA-11), motif requis, tracé |

- **Custom vs CRUD** : toutes les transitions métier (réserver, rejoindre une partie, valider un niveau,
  générer les poules, saisir un score, louer/retourner du matériel, forcer un repli d'éclairage) sont des
  **opérations métier** (State Processors → handlers testables), pas du CRUD Doctrine brut — même
  logique que L3/L6/Sport.
- **Groupes de sérialisation** : pattern read/write par ressource. `NiveauJoueur:read` **masque**
  `niveau` tant que `statut≠valide` aux consommateurs qui filtrent par niveau (le joueur voit son propre
  niveau proposé via `lire_soi`, mais un tiers filtrant les parties ouvertes ne voit que les niveaux
  validés — implémenté côté requête du provider « parties ouvertes »/« inscriptions tournoi », pas par
  un groupe de sérialisation à lui seul).

---

## 4. Sécurité & droits

- **Permissions Padel (module `padel`)** — dérivées du tableau Acteurs & droits de la spec (⚠
  HYPOTHÈSE non littérale dans les sources, à figer avec M8, même posture que `sport.*`/`piscine.*`) :
  `padel.reserver`, `padel.reserver_soi`, `padel.partie_rejoindre_soi`, `padel.niveau_declarer_soi`,
  `padel.niveau_valider`, `padel.tournoi_gerer`, `padel.tournoi_inscrire_soi`, `padel.materiel_gerer`,
  `padel.acces_forcer`, `padel.gerer_terrain`, `padel.configurer_eclairage`, `padel.parametrer`,
  `padel.lire`, `padel.lire_soi`, `padel.coach_lire_soi`.
  - `padel.gerer_terrain` est un **ajout** de ce plan (la spec dit « Créer/gérer un terrain » côté
    Gestionnaire sans nommer de permission dédiée) — même démarche que `sport.facturer` dans
    `plan-sport.md`.
- **Permissions réutilisées** (non redéfinies) — aucune : les processors Padel appellent les **services**
  socle en interne (décision n°8), pas les endpoints `reservation.*`/`vente.*` protégés ; seule
  l'écriture Doctrine sur les entités socle (`Reservation`, `ParticipantReservation`, `Vente`,
  `LigneVente`) est effectuée par du code applicatif Padel, à l'image de
  `PropagationAccesFitnessHandler` (Sport) écrivant directement sur `DroitAcces`.
- **Voter** — `App\Padel\Security\JoueurLieVoter` (attribut `PADEL_LIE`), patron
  `App\Crm\Entity\Client::estLieA()`/`AbonnementFitness::estLieA()` : autorise si
  `Security::getUser()` correspond au `Beneficiaire.client` de l'organisateur, d'un
  `ParticipantReservation`, d'un `InscriptionTournoi.joueur1/2` ou du `NiveauJoueur.joueur` consulté.
- **Cadrage établissement** — `ContexteEtablissement`, extension Doctrine `PerimetrePadelExtension`
  (même mécanique que L1/L3/L5/L6/Sport) sur toutes les entités racines Padel (§1).
- **Traçabilité** — `HistoriqueNiveauJoueur` (append-only, RG-SOCLE-07), `EvenementEclairage`
  (repli manuel tracé auteur/motif) ; extension de
  `App\Audit\Doctrine\AuditWriteSubscriber::CLASSES_SURVEILLEES` avec `NiveauJoueur`, `Tournoi`,
  `CautionMateriel`, `RelaisEclairageTerrain` (mêmes garde-fous que L1-L6/Sport).

---

## 5. Migrations

- **Migration structurelle** `VersionPadel_schema` : tables `padel_terrain`,
  `padel_parametrage_etablissement`, `padel_plage_horaire`, `padel_grille_tarifaire_terrain`,
  `padel_reservation`, `padel_niveau_joueur`, `padel_historique_niveau`, `padel_tournoi`, `padel_poule`,
  `padel_inscription_tournoi`, `padel_match_tournoi`, `padel_location_materiel`,
  `padel_caution_materiel`, `padel_grille_retenue_materiel`, `padel_relais_eclairage`,
  `padel_evenement_eclairage`.
  - **Index/contraintes** : unique `TerrainPadel.ressource`, `ReservationPadel.reservation`,
    `RelaisEclairageTerrain.terrain`, `CautionMateriel.locationActive` (dénormalisée, pattern
    `CautionCasier`), `ParametragePadel.etablissement` ; unique composite
    `(terrain, plageHoraire, statutJoueur, dureeMinutes)` sur `GrilleTarifaireTerrain` ; unique
    `(joueur, etablissement)` sur `NiveauJoueur` ; checks `montant ≥ 0`/`prix ≥ 0`/`quantite > 0` sur
    toutes les tables concernées. FKs vers `App\Reservation\Entity\{Ressource,Reservation}` (**suppose
    la migration socle Réservation jouée d'abord** — dépendance d'ordre explicite, Risque n°1), FKs vers
    `organisation_etablissement`, `crm_beneficiaire`, `securite_utilisateur`, `vente_vente`. Les
    références vers `App\Offre\Entity\{Produit,TypeTarif}` restent des **UUID logiques** (comme
    `ServiceInclus.activiteRef`/`LigneVente.produit`), pas de FK réelle — évite un couplage dur au
    catalogue le temps que le `Produit` « terrain padel » soit paramétré côté M1.
- **Migration de données** `VersionPadel_permissions` : insère `Permission(module='padel', action ∈
  {reserver, reserver_soi, partie_rejoindre_soi, niveau_declarer_soi, niveau_valider, tournoi_gerer,
  tournoi_inscrire_soi, materiel_gerer, acces_forcer, gerer_terrain, configurer_eclairage, parametrer,
  lire, lire_soi, coach_lire_soi})`.
- **Modification de fichier partagé (hors migration DB)** — ajout des classes Padel sensibles à
  `AuditWriteSubscriber::CLASSES_SURVEILLEES` (extension additive, pattern L1-L6/Sport).
- Rejouables, versionnées Doctrine ; jamais de `schema:update --force`.

---

## 6. Tests (PHPUnit + ApiTestCase)

| Test | Type | Couvre |
|---|---|---|
| Réservation terrain 90 min heure pleine non-membre → tarif exact (plage×statut), aucune saisie manuelle, `LigneVente.prixForce=true` | API + Unit (`CalculateurTarifTerrainHandler`) | CA-1, RG-PADEL-01/02 |
| Réservation chevauchante sur même terrain → refusée (délègue au chevauchement générique socle, test d'intégration Padel↔Réservation) | API | CA-2, RG-PADEL-01 |
| Partie ouverte à 2 → 3ᵉ puis 4ᵉ joueur (niveau validé) rejoignent → `statutPartie=complete`, chaque joueur `ParticipantReservation.statutPaiement=paye`, notification envoyée | API + Unit (`RejoindrePartieProcessor`) | CA-3, RG-PADEL-03 |
| Rejoindre une partie avec un niveau **proposé non validé** → refusé (non éligible au filtre) | API | CA-5 (volet filtrage) |
| Partie à 3 à l'heure du créneau (4ᵉ non trouvé) → `statutPartie=maintenue_a_3`, jamais annulée, surcoût réparti équitablement entre les 3 et encaissé | API + Unit (`RepartitionSurcoutHandler`, `MaintienPartieA3Command`) | CA-4, décision « Partie ouverte 3/4 » |
| Déclaration de niveau `propose` puis validation par le Gestionnaire → `statut=valide`, `HistoriqueNiveauJoueur` tracé (auteur/date/ancienne-nouvelle valeur), joueur éligible | API + Unit (`ValiderNiveauProcessor`) | CA-5, US-PADEL-04 |
| Génération de poules sur un tournoi avec paires payées → poules créées, terrains bloqués (réservations système via socle) | API + Unit (`GenererPoulesEtBlocageHandler`) | CA-6, US-PADEL-05 |
| Saisie de score d'un match → vainqueur déterminé, classement (poules) ou avancement (tableau) recalculé sans action supplémentaire | API + Unit (`SaisirScoreHandler`, `ClassementTournoiProvider`) | CA-7, US-PADEL-06 |
| Blocage tournoi sur terrain occupé par récurrence → report automatique sur terrain équivalent (intégration socle RG-M5-11) ; à défaut, validation manuelle demandée, occurrence en attente | API (intégration Padel↔Réservation) | CA-8, décision « Récurrence vs tournoi » |
| Location de 2 raquettes → rattachée à la réservation, caution appliquée si paramétrée, statut de retour suivi `en_cours→rendu`/`non_rendu` avec retenue si non rendu | API + Unit (`LouerMaterielProcessor`, `RetournerMaterielProcessor`) | CA-9, US-PADEL-08 |
| Réservation avec coach déjà engagé sur une fenêtre chevauchante → refusée (non-chevauchement ressource coach, délègue au guard socle) ; sinon acceptée avec tarif majoré | API | CA-10, US-PADEL-09 |
| Créneau confirmé avec relais opérationnel → allumage à l'heure de début (`EvenementEclairage.statut=ok`), extinction à la fin ; relais en défaut → repli manuel proposé et tracé (`echec_repli_manuel`) | API + Unit (`PilotageEclairageHandler`, `SimulateurEclairageAdapter`) | CA-11, RG-PADEL-05 |
| Badge joueur valide uniquement sur la fenêtre réservée ± tolérance (intégration `ProjectionAccesReservation` socle, `TerrainPadel.ressource.ouvreAcces=true`) | API (intégration Padel↔Réservation↔Accès) | CA-12, RG-PADEL-05 volet accès |
| Config `délaiFranc=24h` + exonération membre → non-membre annulant à 12h facturé (via `RegleAnnulation` socle), membre exonéré annulant dans les mêmes conditions non facturé | API (intégration Padel↔Réservation, `RegleAnnulation` créée avec `cibleTypeRessource='terrain_padel'`) | CA-13, RG-PADEL-06 |
| Réservation à 4 dont un joueur ne règle pas sa part → reste confirmée, reste dû imputé à l'organisateur (réutilisation intégrale `RG-M5-10`) | API (intégration, réutilise `PaiementPartageTest` du socle en scénario Padel) | CA-14, RG-PADEL-04 |
| Cloisonnement établissement : agent sans affectation → 403/absent sur toutes les ressources `padel.*` | API | RG-SOCLE-05 |
| Architecture : aucun fichier `App\Reservation\*`/`App\Offre\*`/`App\Vente\*`/`App\Crm\*`/`App\Acces\*` modifié par Padel (contrôle statique diff) | Unit (architecture, patron `Sport\Unit\ArchitectureNonRegressionTest`) | non-régression socle |

---

## 7. Tâches (voir tasks-padel.md)

T1 enums Padel → T2 `TerrainPadel`/`ParametragePadel` → T3 `PlageHoraire`/`GrilleTarifaireTerrain` +
`CalculateurTarifTerrainHandler` → T4 `ReservationPadel` overlay + `ReserverTerrainProcessor` (CA-1/2) →
T5 matching parties ouvertes + `RejoindrePartieProcessor` (CA-3) → T6 `RepartitionSurcoutHandler` +
`MaintienPartieA3Command` (CA-4) → T7 `NiveauJoueur`/historique + processors (CA-5) → T8
`Tournoi`/`Poule`/`InscriptionTournoi` + génération poules/blocage (CA-6) → T9 `MatchTournoi` + saisie
score + classement (CA-7) → T10 articulation récurrence↔tournoi (CA-8, intégration) → T11
`LocationMateriel`/`CautionMateriel`/`GrilleRetenueMateriel` (CA-9) → T12 réservation avec coach (CA-10)
→ T13 éclairage : port + adaptateur + entités + pilotage + repli manuel (CA-11) → T14 intégration accès
badge (CA-12, config seule) → T15 configuration no-show padel (CA-13, `RegleAnnulation` socle) → T16
intégration paiement partagé (CA-14, tests seuls) → T17 API Platform restante + sécurité `padel.*` → T18
migrations → T19 fixtures + suite de tests finale. (ordonnées, cf. fichier tâches.)

---

## 8. Risques / à valider

1. **⚠ ORDONNANCEMENT — `App\Reservation\*` n'existe pas encore dans `app/src`** (priorité haute) — ce
   plan **suppose** l'implémentation complète de `specs/reservation/plan-reservation.md` (T1-T31)
   **avant** tout début de code Padel. Si le contrat effectif de `Reservation`/`ParticipantReservation`/
   `RegleAnnulation` diverge de ce qui est documenté au plan réservation (lui-même sujet à évolution
   avant codage), ce plan Padel devra être **réconcilié** en conséquence (même risque que celui déjà
   assumé par `spec-padel.md` §8 point 2, hérité et non résolu par ce plan).
2. **⚠ Tarification pleine/creuse : extension locale, pas de modification M1** (décision n°3) — le
   `prixForce=true` sur `LigneVente` est un contournement légitime **mais** signifie que le prix affiché
   côté catalogue M1 (grille produit) ne reflète **jamais** le tarif réel appliqué au padel ; un agent
   consultant `off_grille_tarifaire` pour ce produit verrait une valeur non pertinente. **À documenter
   clairement dans l'écran catalogue** (risque UX, constitution §2 règle d'or) et à réévaluer si une 2ᵉ
   verticale a besoin d'un axe horaire (facteur commun à extraire vers M1, non fait ici pour ne pas
   anticiper une réécriture du moteur de grille tarifaire).
3. **⚠ Statut membre/non-membre hérite du Risque n°5 de `plan-reservation.md`** — `FormuleBeneficiaireInterface`
   est un **stub** qui renvoie `null` tant qu'aucun lot M1/M4 ne modélise le rattachement formule↔
   bénéficiaire ; en conséquence, **tous les joueurs seront tarifés `non_membre` par défaut** tant que ce
   port n'est pas câblé — comportement dégradé mais non bloquant (documenté, pas un gap introduit par
   Padel).
4. **⚠ Protocole/API du relais d'éclairage non spécifié** (spec §4.9 point 10) — `PiloteEclairage` n'a
   qu'un adaptateur simulateur ; aucune intégration domotique/IoT réelle. **Bloquant pour toute mise en
   service réelle de l'éclairage automatique**, non pour le développement fonctionnel (même statut que
   `CollecteurSepaInterface` dans `plan-sport.md` avant intégration bancaire réelle).
5. **⚠ Caution matériel : entité locale, pas de capacité socle** (décision n°6) — si Musée ou une autre
   verticale a un jour besoin d'une caution, une factorisation (objet socle porté par M2 ou M6) devra
   être arbitrée ; ce plan ne l'anticipe pas (même posture que `plan-piscine.md`).
6. **⚠ `NiveauJoueur` scopé par établissement plutôt que strictement 1:1 joueur** (décision n°7,
   divergence mineure vs. spec §5) — à confirmer produit : un joueur multi-clubs devra-t-il déclarer/
   faire valider un niveau **par club**, ou existe-t-il un niveau « fédéral » unique à terme ?
7. **⚠ Algorithme de génération des poules non précisé** (spec §4.5, hypothèse : aléatoire équilibré par
   niveau) — `GenererPoulesEtBlocageHandler` implémente cette hypothèse par défaut ; à confirmer produit
   avant un usage réel en compétition.
8. **⚠ Articulation récurrence ↔ tournoi (CA-8) repose entièrement sur `RG-M5-11`, non observée en
   fonctionnement réel** — le mécanisme socle (`RecurrenceReportHandler`) est documenté par
   `plan-reservation.md` mais son déclenchement exact lors de la création d'un blocage tournoi
   (chevauchement volontaire côté système) doit être **validé par un test d'intégration bout en bout**
   dès que les deux modules sont codés (T10 de ce plan) — risque d'écart de comportement entre la
   spécification et l'implémentation réservation.
9. **⚠ Cas « partie ouverte qui ne dépasse jamais 2 joueurs »** (spec §4.3/§7, hypothèse : régime
   générique de no-show sur les places non pourvues) — non implémenté explicitement par un handler dédié
   dans ce plan ; `MaintienPartieA3Command` ne couvre que le seuil 3/4 tranché par la décision actée. Le
   cas < 3 suit par défaut le comportement socle standard (place perdue, `RegleAnnulation`), sans logique
   Padel supplémentaire — **à confirmer** que c'est suffisant.
10. **⚠ Permissions `padel.*` non littérales dans les sources** (comme pour Sport/Piscine) — à arbitrer
    avec M8 avant mise en production, en particulier `padel.gerer_terrain` (ajout de ce plan).
11. **⚠ Rôle Coach sans écran dédié** (spec §3 hypothèse) — `padel.coach_lire_soi` limité à la
    consultation du planning propre ; aucune gestion de remplacement/indisponibilité automatique n'est
    couverte par ce plan (spec §7 renvoie à une analogie M5 non détaillée ici).
