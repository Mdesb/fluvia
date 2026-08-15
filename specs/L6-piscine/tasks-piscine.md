# Tâches — Verticale Piscine (`Piscine` / lot `L6`)

- **Plan source :** specs/L6-piscine/plan-piscine.md
- **Rappel architecture** : aucune tâche ne modifie `app/src/Acces/*` (L3). Le module `App\Piscine\*`
  est additif ; les seuls fichiers socle partagés touchés sont `AuditWriteSubscriber.php` (T13, ajout de
  classes à une liste, pattern déjà utilisé par L1/L2/L3/L4) et les migrations de permissions.

| # | Tâche | Fichiers | Dépend de | Test associé | État |
|---|---|---|---|---|---|
| T1 | Enums Piscine (`PerimetrePoss`, `EtatLigneEau`, `TypeEncadrement`, `StatutCreneauBassin`, `TypePublic`, `ModeProrata`, `EtatCasier`, `StatutCaution`) | `app/src/Piscine/Enum/*.php` | — | Unit (valeurs, mapping Doctrine `enumType`) | ⬜ |
| T2 | `ParametrePiscineEtablissement` (1-1 établissement, délai forçage, montant caution défaut, mode prorata défaut) | `app/src/Piscine/Entity/ParametrePiscineEtablissement.php` | T1, socle L0 | API — lecture/écriture 1-1, valeurs par défaut | ⬜ |
| T3 | `Poss` (OneToOne `EspaceAcces` L3) + `PossModeSeuilGuard` (listener Doctrine additif imposant `modeSeuil=blocage`) | `app/src/Piscine/Entity/Poss.php`, `app/src/Piscine/EventListener/PossModeSeuilGuard.php` | T1, L3 `EspaceAcces` (existant, lu non modifié) | Unit (`PossModeSeuilGuard`) — création/MAJ refusée si `alerte` | ⬜ |
| T4 | `Bassin` + `LigneEau` (unique `(bassin,numero)`, `espaceAccesDedie` optionnel) | `app/src/Piscine/Entity/{Bassin,LigneEau}.php` | T1, socle `Espace` | API — CRUD, contrainte unique lignes | ⬜ |
| T5 | `CreneauBassin` + `QualificationEncadrant` + `AffectationEncadrant` + `ValiderCreneauBassinHandler` (garde encadrant qualifié, CA-4) | `app/src/Piscine/Entity/{CreneauBassin,QualificationEncadrant,AffectationEncadrant}.php`, `app/src/Piscine/Service/ValiderCreneauBassinHandler.php` | T4 | API + Unit (`ValiderCreneauBassinHandler`) — CA-4 | ⬜ |
| T6 | `CreneauPublic` (ManyToMany `LigneEau`) + `AffectationLigneGuard` (garde chevauchement temporel, CA-6) | `app/src/Piscine/Entity/CreneauPublic.php`, `app/src/Piscine/Service/AffectationLigneGuard.php` | T5 | API + Unit (`AffectationLigneGuard`) — CA-6 | ⬜ |
| T7 | `JaugeGrandPublicCalculee` + `PossProrataCalculator` (formule par défaut `lignes`, hooks `surface`/`forfait`) | `app/src/Piscine/Entity/JaugeGrandPublicCalculee.php`, `app/src/Piscine/Service/PossProrataCalculator.php` | T6 | Unit (`PossProrataCalculator`) — CA-7 | ⬜ |
| T8 | `Casier` + `CautionCasier` (index unique partiel « un seul actif », pattern `Appairage.support_actif`) + `RelanceCasier` + `ForcageCasier` + handlers (`AttribuerCasierHandler`, `LibererCasierHandler`, `RelancerCasierHandler`, `ForcerCasierHandler`) | `app/src/Piscine/Entity/{Casier,CautionCasier,RelanceCasier,ForcageCasier}.php`, `app/src/Piscine/Service/*Handler.php` | T2 | API — CA-9 (attribution/libération/relance/forçage) | ⬜ |
| T9 | `BraceletEtanche` (OneToOne `Support` L3, valide `type=RFID`, `beneficiaireRef` logique) | `app/src/Piscine/Entity/BraceletEtanche.php` | L3 `Support` (existant, lu non modifié) | API — CA-10 | ⬜ |
| T10 | `ModelePiscineTypeGenerator` (catalogue « piscine type » : crée `Produit`/`Formule`/`CarteMultiEntrees` via Offre, gabarit paramétrable non figé en dur) + endpoint d'instanciation | `app/src/Piscine/Service/ModelePiscineTypeGenerator.php`, `app/src/Piscine/State/InstancierModelePiscineTypeProcessor.php`, `config/packages/piscine.yaml` (gabarit par défaut) | Offre L1 (`Produit`,`Formule`,`CarteMultiEntrees`, existants, lus non modifiés) | API — CA-1 | ⬜ |
| T11 | `PossEtatLive` (ApiResource) + `PossEtatLiveProvider` (compose `JaugeFmi` L3 + réservations piscine : présents/POSS/pré-alerte/places réservées) | `app/src/Piscine/ApiResource/PossEtatLive.php`, `app/src/Piscine/State/PossEtatLiveProvider.php` | T3, T6/T7, L3 `JaugeFmi` (existant, lu non modifié) | API — CA-3 | ⬜ |
| T12 | Ressources API Platform restantes (`Poss`, `Bassin`, `LigneEau`, `CreneauBassin`, `CreneauPublic`, `JaugeGrandPublicCalculee`, `QualificationEncadrant`, `AffectationEncadrant`, `Casier`, `BraceletEtanche`, `ParametrePiscineEtablissement`) : opérations, `security:`, groupes de sérialisation | `app/src/Piscine/Entity/*.php` (attributs `#[ApiResource]`) | T1-T11 | API — sérialisation + 403 hors permission | ⬜ |
| T13 | Sécurité & droits : permissions `piscine.{configurer,gerer_casier,forcer_casier,lire,gerer}` (migration de données) + extension `AuditWriteSubscriber::CLASSES_SURVEILLEES` | `app/migrations/VersionL6_permissions.php`, `app/src/Audit/Doctrine/AuditWriteSubscriber.php` | T1-T9 | API — 403/journal (`EntreeAudit` créée pour `Poss`/`Casier`/`ForcageCasier`) | ⬜ |
| T14 | Migration structurelle `VersionL6_piscine` (tables + index/contraintes listés au plan §5) | `app/migrations/VersionL6_piscine.php` | T1-T9, socle L0 + M1 + L3 jouées | Migration rejouable (up/down) | ⬜ |
| T15 | Suite de tests complète (10 CA + garde `PossModeSeuilGuard` + journalisation seuil + concurrence caution + architecture non-régression L3) | `app/tests/Piscine/**` | T1-T14 | Cf. tableau §6 du plan | ⬜ |

État : ⬜ à faire · 🟦 en cours · ✅ fait · ⛔ bloqué
