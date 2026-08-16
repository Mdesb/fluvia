# Tâches — Reporting & pilotage multi-niveaux (`M7` / lot `L11`)

- **Plan source :** specs/L11-reporting/plan-reporting.md

| # | Tâche | Fichiers | Dépend de | Test associé | État |
|---|---|---|---|---|---|
| T1 | Enums `App\Reporting\Enum\*` (`NiveauEntite`, `TypeAxeAnalytique`, `UniteIndicateur`, `ModeCalculIndicateur`, `NatureIndicateur`, `SourceModuleIndicateur`, `GranulariteMesure`, `RegimeExploitantMesure`, `StatutCompletude`, `FormatExport`, `PeriodiciteRapport`, `EtatRapportPlanifie`, `StatutExport`) (§1.10) | `app/src/Reporting/Enum/*.php` | — | Unit (valeurs) | ⬜ |
| T2 | VO `App\Reporting\ValueObject\Periode` (§1.11) | `app/src/Reporting/ValueObject/Periode.php` | T1 | Unit | ⬜ |
| T3 | Trait `App\Reporting\Entity\Trait\RattachementNiveauTrait` (§1.1) | `app/src/Reporting/Entity/Trait/RattachementNiveauTrait.php` | T1 | Unit | ⬜ |
| T4 | Entité `AxeAnalytique` (§1.2), `#[ApiResource]` Get/GetCollection/Post/Patch (pas de Delete) | `app/src/Reporting/Entity/AxeAnalytique.php` | T1 | API — CRUD, pas de Delete exposé | ⬜ |
| T5 | Entité `Indicateur` (§1.3), `#[ApiResource]` idem T4 | `app/src/Reporting/Entity/Indicateur.php` | T1 | API — CRUD, pas de Delete exposé | ⬜ |
| T6 | Entité `Mesure` (§1.4), `#[ApiResource]` Get/GetCollection uniquement, index composites, `cleAgregation` unique | `app/src/Reporting/Entity/Mesure.php` | T1, T3, T5 | Unit — contrainte unique `cleAgregation` | ⬜ |
| T7 | Entité `ObjectifIndicateur` (§1.5), CRUD `reporting.configurer`/`reporting.lire` | `app/src/Reporting/Entity/ObjectifIndicateur.php` | T1, T3, T5 | API — CRUD | ⬜ |
| T8 | Entité `TableauDeBord` (§1.6), CRUD, `Assert\Count(min:1)` sur `indicateurs` | `app/src/Reporting/Entity/TableauDeBord.php` | T1, T3, T5 | API — Post sans indicateur ⇒ 422 | ⬜ |
| T9 | Entités `RapportPlanifie` + `DestinataireRapport` (§1.7, §1.8), cascade persist/remove | `app/src/Reporting/Entity/RapportPlanifie.php`, `DestinataireRapport.php` | T1, T3, T8 | Unit — cascade | ⬜ |
| T10 | Entité `Export` (§1.9) | `app/src/Reporting/Entity/Export.php` | T1, T3, T9 | Unit | ⬜ |
| T11 | Migration de permissions `reporting.lire`/`planifier`/`configurer` (§5.2) | `app/migrations/VersionYYYYMMDDHHMMSS_l11_permissions.php` | — | API — 403 sans permission | ⬜ |
| T12 | Migration structurelle (toutes tables `report_*`, FKs, index) (§5.1) | `app/migrations/VersionYYYYMMDDHHMMSS_l11_schema.php` | T4–T10 | Migration rejouable (up/down) | ⬜ |
| T13 | Migration de données — référentiel `AxeAnalytique` + `Indicateur` de base (§5.3) | `app/migrations/VersionYYYYMMDDHHMMSS_l11_referentiel.php` | T12 | Unit — 6 axes / 9 indicateurs créés | ⬜ |
| T14 | Interfaces de projection `App\Reporting\Projection\*Interface` (§2.1) | `app/src/Reporting/Projection/ProjectionVenteInterface.php`, `ProjectionAccesInterface.php`, `ProjectionComptaInterface.php`, `ProjectionReservationInterface.php`, `ProjectionRecouvrementInterface.php` | T2 | — | ⬜ |
| T15 | Adaptateur `ProjectionVenteDoctrine` (`caEncaisse`) | `app/src/Reporting/Projection/Doctrine/ProjectionVenteDoctrine.php` | T14 | Unit — CA jour = somme `Vente` scellées | ⬜ |
| T16 | Adaptateur `ProjectionAccesDoctrine` (`frequentationCumulee`, `jaugesFmi`, `etablissementHorsLigne`) | `app/src/Reporting/Projection/Doctrine/ProjectionAccesDoctrine.php` | T14 | Unit — cumul = `COUNT(Passage)`, jauges lues telles quelles | ⬜ |
| T17 | Adaptateur `ProjectionComptaDoctrine` (`fondDeCaisseTheorique`, `regimeExploitant`, `syntheseRegimeIsolee`) | `app/src/Reporting/Projection/Doctrine/ProjectionComptaDoctrine.php` | T14 | Unit | ⬜ |
| T18 | Adaptateurs `ProjectionReservationDoctrine`, `ProjectionRecouvrementDoctrine` | `app/src/Reporting/Projection/Doctrine/ProjectionReservationDoctrine.php`, `ProjectionRecouvrementDoctrine.php` | T14 | Unit | ⬜ |
| T19 | Câblage des alias d'interfaces vers les adaptateurs Doctrine | `app/config/services.yaml` | T15–T18 | — | ⬜ |
| T20 | `App\Reporting\Security\PerimetreReporting` (VO) + `PerimetreReportingResolver` (§2.2) | `app/src/Reporting/Security/PerimetreReporting.php`, `PerimetreReportingResolver.php` | T3 | Unit — établissements/régions/groupes couverts, cas non contigu ⇒ `[]` | ⬜ |
| T21 | `App\Reporting\Doctrine\PerimetreReportingExtension` (`Mesure`, `TableauDeBord`, `RapportPlanifie`, `Export`) (§4) | `app/src/Reporting/Doctrine/PerimetreReportingExtension.php` | T6, T8, T9, T10, T20 | API — CA-1 (cloisonnement) | ⬜ |
| T22 | `App\Reporting\Service\AgregateurMesuresService` — passe site (dispatch `sourceModule` → projection, upsert par `cleAgregation`) (§2.1) | `app/src/Reporting/Service/AgregateurMesuresService.php` | T6, T14–T19 | Unit — idempotence, CA calculé | ⬜ |
| T23 | `AgregateurMesuresService` — passe région/groupe (agrégation ascendante, `modeCalcul`) (§2.1) | `app/src/Reporting/Service/AgregateurMesuresService.php` | T22 | Unit — somme région = somme des sites | ⬜ |
| T24 | `AgregateurMesuresService` — FMI (échantillonnage `GREATEST`, `FMI_MAX_SOMME_SITES`/`FMI_MAX_SITE_CRITIQUE`) (§2.5) | `app/src/Reporting/Service/AgregateurMesuresService.php` | T23 | Unit — CA-6, deux indicateurs distincts, libellés explicites | ⬜ |
| T25 | `AgregateurMesuresService` — consolidation multi-régime (`comparabiliteRegime`) (§2.7) | `app/src/Reporting/Service/AgregateurMesuresService.php` | T23, T17 | Unit — CA-9 | ⬜ |
| T26 | `AgregateurMesuresService` — complétude (`etablissementHorsLigne`, `seuilCompletudeMinutes`, `sitesManquants`) (§2.6) | `app/src/Reporting/Service/AgregateurMesuresService.php` | T23, T16 | Unit — CA-10 | ⬜ |
| T27 | Commande `reporting:agreger` (`App\Reporting\Command\AgregerMesuresCommand`, options `--depuis`/`--jusqu-a`) (§2.9) | `app/src/Reporting/Command/AgregerMesuresCommand.php` | T22–T26 | Command — exécution complète sans erreur, idempotente | ⬜ |
| T28 | `App\Reporting\Service\CalculComparaisonService` (écarts n-1/objectif, code couleur) (§2.4) | `app/src/Reporting/Service/CalculComparaisonService.php` | T6, T7 | Unit — écart valeur/pourcentage/couleur | ⬜ |
| T29 | ApiResource DTO `DashboardEtablissementVue` + `DashboardEtablissementProvider` (§2.4, CA-2) | `app/src/Reporting/ApiResource/DashboardEtablissementVue.php`, `app/src/Reporting/State/DashboardEtablissementProvider.php` | T15–T17, T20 | API — CA-2 | ⬜ |
| T30 | ApiResource DTO `DashboardRegionVue` + `DashboardRegionProvider` (classements, écarts, drill-down, vues régime isolées) (§2.4, §2.7, CA-3) | `app/src/Reporting/ApiResource/DashboardRegionVue.php`, `app/src/Reporting/State/DashboardRegionProvider.php` | T6, T17, T20, T28 | API — CA-3, CA-9 | ⬜ |
| T31 | ApiResource DTO `DashboardGroupeVue` + `DashboardGroupeProvider` (tendances/saisonnalité, benchmarks, drill-down) (§2.4, CA-4) | `app/src/Reporting/ApiResource/DashboardGroupeVue.php`, `app/src/Reporting/State/DashboardGroupeProvider.php` | T30 | API — CA-4 | ⬜ |
| T32 | ApiResource DTO `ExplorateurResultatVue` + `ExplorateurProvider` (axes libres, filtre régime) (§2.7, CA-5) | `app/src/Reporting/ApiResource/ExplorateurResultatVue.php`, `app/src/Reporting/State/ExplorateurProvider.php` | T6, T20 | API — CA-5 | ⬜ |
| T33 | `RapportPlanifieProcessor` (destinataires ⊆ périmètre créateur, 422 sinon) (§2.3) | `app/src/Reporting/State/RapportPlanifieProcessor.php`, câblage sur `RapportPlanifie` | T9, T20 | API — destinataire hors périmètre ⇒ 422 | ⬜ |
| T34 | `TableauDeBordProcessor` (≥1 indicateur) | `app/src/Reporting/State/TableauDeBordProcessor.php`, câblage sur `TableauDeBord` | T8 | API — 0 indicateur ⇒ 422 | ⬜ |
| T35 | `App\Reporting\Service\GenerateurExportInterface` + `CsvGenerateurExport` (réel) (§2.8) | `app/src/Reporting/Service/GenerateurExportInterface.php`, `Export/CsvGenerateurExport.php` | T6 | Unit — CSV valide, en-têtes correctes | ⬜ |
| T36 | `PdfGenerateurExportStub` / `XlsxGenerateurExportStub` (`GenerationExportNonSupporteeException`) (§2.8) | `app/src/Reporting/Service/Export/PdfGenerateurExportStub.php`, `XlsxGenerateurExportStub.php`, `app/src/Reporting/Exception/GenerationExportNonSupporteeException.php` | T35 | Unit — exception explicite levée | ⬜ |
| T37 | `App\Reporting\Service\StockageExportInterface` + `StockageExportLocal` (filesystem) (§2.8) | `app/src/Reporting/Service/StockageExportInterface.php`, `Export/StockageExportLocal.php` | — | Unit — stocker/récupérer round-trip | ⬜ |
| T38 | `App\Reporting\Notification\RapportPlanifieMailer` (patron `ReinitialisationMailer`) (§2.8) | `app/src/Reporting/Notification/RapportPlanifieMailer.php` | — | Unit — mail généré (transport `null://null`) | ⬜ |
| T39 | Commande `reporting:executer-rapports` (sélection actifs, génération par destinataire, envoi, `dernierEnvoi`/`prochainEnvoi`) (§2.8) | `app/src/Reporting/Command/ExecuterRapportsCommand.php` | T9, T10, T33, T35–T38 | Command — CA-7, CA-8 | ⬜ |
| T40 | `ExportManuelProcessor` (`POST /reporting/exports`) + câblage `Export` | `app/src/Reporting/State/ExportManuelProcessor.php`, câblage sur `Export` | T10, T20, T35–T37 | API — export manuel borné au périmètre | ⬜ |
| T41 | Contrôleur `GET /reporting/exports/{id}/telecharger` | `app/src/Reporting/Controller/ExportTelechargerController.php` | T37, T40 | API — téléchargement, 403 hors périmètre/hors propriétaire | ⬜ |
| T42 | Fixtures `L11Fixtures` (établissements multi-régime, `Mesure` de test, affectations `reporting.*`, contrôleur hors-ligne) | `app/src/Reporting/DataFixtures/L11Fixtures.php` | T12, T13 | — | ⬜ |
| T43 | Base de test `ReportingApiTestCase` (patron `SecuriteApiTestCase`/`CrmApiTestCase`) | `app/tests/Reporting/ReportingApiTestCase.php` | T42 | — | ⬜ |
| T44 | Suite de tests fonctionnels complète (§6 du plan, CA-1 à CA-11 + cas limites) | `app/tests/Reporting/**` | T1–T43 | voir §6 plan-reporting.md | ⬜ |
| T45 | Non-régression : suites existantes (Vente, Accès, Compta, Réservation, Recouvrement, Securite) rejouées sans modification après ajout des projections en lecture | `app/tests/Vente/**`, `app/tests/Acces/**`, `app/tests/Compta/**`, `app/tests/Reservation/**`, `app/tests/Recouvrement/**` (existants) | T15–T18 | 0 régression sur suites existantes | ⬜ |

État : ⬜ à faire · 🟦 en cours · ✅ fait · ⛔ bloqué
