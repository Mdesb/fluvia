# Tâches — Verticale Centre de Padel (`Padel` / lot post-MVP, V2 L16)

- **Plan source :** specs/padel/plan-padel.md
- **Pré-requis d'ordonnancement (Risque n°1 du plan)** — `App\Reservation\*` doit être **entièrement
  implémenté** (T1-T31 de `specs/reservation/tasks-reservation.md`) avant de démarrer T2 de ce fichier.
  T1 (enums) peut être développé en parallèle, sans dépendance.
- **Rappel architecture** : aucune tâche ne modifie `app/src/Reservation/*`, `app/src/Offre/*`,
  `app/src/Vente/*`, `app/src/Crm/*` ni `app/src/Acces/*`. Le module `App\Padel\*` est additif ; les
  seuls fichiers socle partagés touchés sont `AuditWriteSubscriber.php` (T17, ajout de classes à une
  liste, pattern déjà utilisé par L1-L6/Sport) et les migrations de permissions.
- **Rappel décision n°3 (tarification)** — aucune tâche ne crée de `off_grille_tarifaire` (saison) pour
  le padel ; T3 force le prix (`LigneVente.prixForce=true`), voir plan §1.2/§8 point 2.

| # | Tâche | Fichiers | Dépend de | Test associé | État |
|---|---|---|---|---|---|
| T1 | Enums Padel (`TypeTerrain`, `LibellePlageHoraire`, `StatutJoueurTarif`, `ModeRepartitionSurcout`, `StatutPartieOuverte`, `StatutNiveauJoueur`, `FormatTournoi`, `StatutTournoi`, `StatutPaiementInscriptionTournoi`, `StatutMatchTournoi`, `StatutRetourMateriel`, `StatutCautionMateriel`, `StatutRelaisEclairage`, `ActionEclairage`, `StatutEvenementEclairage`, `ModeRepliEclairage`) | `app/src/Padel/Enum/*.php` | — | Unit (valeurs, mapping Doctrine `enumType`) | ⬜ |
| T2 | `TerrainPadel` (overlay 1:1 `Reservation\Ressource`, `codeType='terrain_padel'`) + `ParametragePadel` (1-1 établissement) | `app/src/Padel/Entity/{TerrainPadel,ParametragePadel}.php` | T1, `App\Reservation\Entity\Ressource` (existant, lu non modifié), socle L0 | API — création terrain + `Ressource` socle en cascade, valeurs par défaut `ParametragePadel` | ⬜ |
| T3 | `PlageHoraire` + `GrilleTarifaireTerrain` + `CalculateurTarifTerrainHandler` (résout plage×statut→prix, force `LigneVente.prixUnitaire`/`prixForce`) | `app/src/Padel/Entity/{PlageHoraire,GrilleTarifaireTerrain}.php`, `app/src/Padel/Service/CalculateurTarifTerrainHandler.php` | T2 | Unit — CA-1 (résolution tarif, matrice pleine/creuse × membre/non-membre) | ⬜ |
| T4 | `ReservationPadel` (overlay 1:1 `Reservation\Reservation`) + `ReserverTerrainProcessor` (crée `Creneau`+`Reservation`+`ParticipantReservation` socle via appel service direct, applique T3) | `app/src/Padel/Entity/ReservationPadel.php`, `app/src/Padel/State/ReserverTerrainProcessor.php` | T3, `App\Reservation\Entity\{Creneau,Reservation,ParticipantReservation}` (existants, service socle appelé en interne) | API + Unit — CA-1, CA-2 (chevauchement délégué au socle) | ⬜ |
| T5 | Parties ouvertes : champs `ouverte`/`niveauVise*`/`statutPartie` sur `ReservationPadel` + `RejoindrePartieProcessor` (ajoute `ParticipantReservation`, délègue le paiement à `PayerPartProcessor` socle, filtre par `NiveauJoueur.statut=valide`) | `app/src/Padel/State/RejoindrePartieProcessor.php` | T4, T7 (niveau validé requis pour le filtre — développable en parallèle, brancher le filtre en dernier) | API + Unit — CA-3 | ⬜ |
| T6 | `RepartitionSurcoutHandler` (recalcule `ParticipantReservation.partMontant` pour les 3 présents, applique `ParametragePadel.modeRepartitionSurcout`) + `MaintienPartieA3Command` (commande planifiée, scrute les créneaux à l'heure de début avec 3 joueurs) | `app/src/Padel/Service/RepartitionSurcoutHandler.php`, `app/src/Padel/Command/MaintienPartieA3Command.php` | T5 | API + Unit — CA-4 | ⬜ |
| T7 | `NiveauJoueur` + `HistoriqueNiveauJoueur` + `DeclarerNiveauProcessor` + `ValiderNiveauProcessor` (trace l'historique, RG-SOCLE-07) | `app/src/Padel/Entity/{NiveauJoueur,HistoriqueNiveauJoueur}.php`, `app/src/Padel/State/{DeclarerNiveauProcessor,ValiderNiveauProcessor}.php` | T2 (échelle via `ParametragePadel`) | API + Unit — CA-5 | ⬜ |
| T8 | `Tournoi` + `Poule` + `InscriptionTournoi` + `InscrireTournoiProcessor` (frais via M2, `Vente`/`LigneVente` existants lus non modifiés) + `GenererPoulesEtBlocageHandler` (génère les poules, crée les créneaux système de blocage via le service socle) | `app/src/Padel/Entity/{Tournoi,Poule,InscriptionTournoi}.php`, `app/src/Padel/State/InscrireTournoiProcessor.php`, `app/src/Padel/Service/GenererPoulesEtBlocageHandler.php` | T2, T7 (niveau requis pour tête de série) | API + Unit — CA-6 | ⬜ |
| T9 | `MatchTournoi` + `SaisirScoreHandler` (détermine le vainqueur, fait avancer le tableau) + `ClassementTournoiProvider` (non-Doctrine, calcule le classement des poules à la lecture) | `app/src/Padel/Entity/MatchTournoi.php`, `app/src/Padel/Service/SaisirScoreHandler.php`, `app/src/Padel/State/ClassementTournoiProvider.php` | T8 | API + Unit — CA-7 | ⬜ |
| T10 | Test d'intégration récurrence↔tournoi (vérifie que le blocage créé en T8 déclenche bien `RecurrenceReportHandler`/`RG-M5-11` côté socle — pas de nouveau code Padel, uniquement un test bout en bout, cf. plan Risque n°8) | `app/tests/Padel/Integration/RecurrenceTournoiTest.php` | T8, `App\Reservation\Service\RecurrenceReportHandler` (existant) | API (intégration) — CA-8 | ⬜ |
| T11 | `LocationMateriel` + `CautionMateriel` + `GrilleRetenueMateriel` + `LouerMaterielProcessor` (rattache à la réservation, encaisse via M2) + `RetournerMaterielProcessor` (libère/retient la caution) | `app/src/Padel/Entity/{LocationMateriel,CautionMateriel,GrilleRetenueMateriel}.php`, `app/src/Padel/State/{LouerMaterielProcessor,RetournerMaterielProcessor}.php` | T4 | API + Unit — CA-9 | ⬜ |
| T12 | Réservation avec coach : champs `avecCoach`/`coachRessource`/`reservationCoach` sur `ReservationPadel` + `ReserverAvecCoachHandler` (crée la 2ᵉ réservation socle miroir sur la ressource coach, tarif majoré) | `app/src/Padel/Service/ReserverAvecCoachHandler.php` (étend `ReserverTerrainProcessor` de T4) | T4 | API — CA-10 | ⬜ |
| T13 | Port `PiloteEclairage` + `SimulateurEclairageAdapter` + `RelaisEclairageTerrain` + `EvenementEclairage` + `PilotageEclairageHandler` (allumage/extinction sur fenêtre créneau) + `CommanderEclairageCommand` (cron) + `ForcerEclairageManuelProcessor` | `app/src/Padel/Port/PiloteEclairage.php`, `app/src/Padel/Adapter/SimulateurEclairageAdapter.php`, `app/src/Padel/Dto/ResultatCommandeEclairage.php`, `app/src/Padel/Entity/{RelaisEclairageTerrain,EvenementEclairage}.php`, `app/src/Padel/Service/PilotageEclairageHandler.php`, `app/src/Padel/Command/CommanderEclairageCommand.php`, `app/src/Padel/State/ForcerEclairageManuelProcessor.php` | T2 | API + Unit (`SimulateurEclairageAdapter`) — CA-11 | ⬜ |
| T14 | Intégration accès badge : `TerrainPadel.ressource.ouvreAcces=true` en fixture/configuration + test d'intégration `ProjectionAccesReservation` (aucun code Padel supplémentaire, `ToleranceEntreeBadgeMinutes` de `ParametragePadel` passée à la création de réservation en T4) | `app/tests/Padel/Integration/AccesBadgeTest.php` | T4, `App\Reservation\Entity\ProjectionAccesReservation` (existant) | API (intégration) — CA-12 | ⬜ |
| T15 | Configuration no-show padel : fixture/endpoint de création d'une `RegleAnnulation` socle (`cibleTypeRessource='terrain_padel'`, `delaiFrancMinutes=1440`, exonération membre) — pas de nouvelle entité Padel | `app/src/Padel/DataFixtures/PadelFixtures.php` (partiel, complété en T19) | T2, `App\Reservation\Entity\RegleAnnulation` (existant) | API (intégration) — CA-13 | ⬜ |
| T16 | Test d'intégration paiement partagé (réutilise `PaiementPartageTest` du socle en scénario padel 4 joueurs, aucun code Padel supplémentaire au-delà de T4/T5) | `app/tests/Padel/Integration/PaiementPartageTest.php` | T4, T5 | API (intégration) — CA-14 | ⬜ |
| T17 | Ressources API Platform restantes (`ParametragePadel`, `PlageHoraire`, `GrilleTarifaireTerrain`, `NiveauJoueur`, `Tournoi`, `InscriptionTournoi`, `MatchTournoi`, `LocationMateriel`, `RelaisEclairageTerrain`, `EvenementEclairage`) : attributs `#[ApiResource]`, `security:`, groupes de sérialisation + `JoueurLieVoter` + permissions `padel.*` (migration de données) + extension `AuditWriteSubscriber::CLASSES_SURVEILLEES` | `app/src/Padel/Entity/*.php` (attributs), `app/src/Padel/Security/JoueurLieVoter.php`, `app/migrations/VersionPadel_permissions.php`, `app/src/Audit/Doctrine/AuditWriteSubscriber.php` | T1-T13 | API — sérialisation, 403 hors permission | ⬜ |
| T18 | Migration structurelle `VersionPadel_schema` (tables + index/contraintes listés au plan §5) + `App\Padel\Doctrine\PerimetrePadelExtension` (cloisonnement établissement) | `app/migrations/VersionPadel_schema.php`, `app/src/Padel/Doctrine/PerimetrePadelExtension.php` | T1-T13, socle L0 + M1 + M2 + M4 + `App\Reservation` jouées | Migration rejouable (up/down) ; API — cloisonnement RG-SOCLE-05 | ⬜ |
| T19 | Suite de tests complète (14 CA + cloisonnement + architecture non-régression) + `PadelFixtures` (terrains, grille tarifaire, règle d'annulation, tournoi de démo) | `app/tests/Padel/**`, `app/src/Padel/DataFixtures/PadelFixtures.php` | T1-T18 | Cf. tableau plan §6 | ⬜ |

État : ⬜ à faire · 🟦 en cours · ✅ fait · ⛔ bloqué

## Notes d'ordonnancement

- **T1** est indépendante et peut démarrer immédiatement (aucune dépendance vers `App\Reservation`).
- **T2-T4** posent le socle Padel minimal (terrain, tarification, réservation simple) — bloquent tout le
  reste ; nécessitent `App\Reservation\*` codé (T1-T31 du plan réservation).
- **T5-T6** (matching/complétion 3-4) et **T7** (niveau) sont interdépendants côté filtrage mais
  développables en parallèle jusqu'au branchement final du filtre (T5 dépend de T7 seulement pour ce
  point précis).
- **T8-T10** (tournois) dépendent de T2/T4/T7 mais sont indépendants de T11-T13.
- **T11** (matériel), **T12** (coach), **T13** (éclairage) sont indépendantes entre elles, à paralléliser
  selon les ressources dev disponibles — toutes dépendent uniquement de T2/T4.
- **T14-T16** sont des tests d'intégration purs (aucun nouveau code de domaine), à placer en fin de
  développement des dépendances respectives.
- **T17-T19** referment le lot (API Platform restante, sécurité fine, migrations, fixtures, suite de
  tests finale, non-régression architecture).
