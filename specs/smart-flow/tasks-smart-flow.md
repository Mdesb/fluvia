# Tâches — Smart Flow (`SF-0` / `SF-2`)

- **Plan source :** specs/smart-flow/plan-smart-flow.md
- **Spec source :** specs/smart-flow/spec-smart-flow.md
- **Ordre imposé (claude-A, 24/08) :** I1 (report de no-show, SF-2) d'abord ; I3 (affluence) en dernier, bloqué par `access.recorded` (SF-1, hors périmètre claude-E).
- **⚠ Exécution :** l'implémentation exige la stack PHP/Composer + `infra/test-stack.sh` (VPS). La session desktop claude-E n'a pas de PHP local (design uniquement). Voir `RAPPORTS/claude-E.md`.

## Incrément I1 — Report de no-show (SF-2, prioritaire, non bloqué)

| # | Tâche | Fichiers | Dépend de | Test associé | État |
|---|---|---|---|---|---|
| T1 | Enum `RescheduleProposalStatus` ; entité `RescheduleProposal` ; migration `..._1` ; DTO `SlotSnapshot`/`ReservationSnapshot` ; service `ReservationSlotReader` (lecture seule + revérif établissement, §0.3) | `app/src/SmartFlow/{Enum,Entity,Dto,Service}/`, `app/migrations/` | — | `CompatibleSlotFinderTest` (indirect) | ⬜ |
| T2 | `PerimetreSmartFlowExtension` (§0.10) + `#[ApiResource]` lecture `RescheduleProposal` (filtre établissement + « own » client) | `app/src/SmartFlow/{Doctrine,Entity}/` | T1 | `CloisonnementSmartFlowTest::testPropositionDunAutreEtablissementInvisible404`, `testClientNeVoitQueSesPropositions` | ⬜ |
| T3 | `CompatibleSlotFinder` (RG-SF-09, tolérance même ressource OU `codeType`, §0.6) | `app/src/SmartFlow/Service/` | T1 | `CompatibleSlotFinderTest::testMemeRessourceOuMemeCodeTypeDansLaFenetre`, `testCapaciteResiduelleNulleExclue` | ⬜ |
| T4 | Port `ClientNotificationInterface` + adaptateur log (§0.8) ; `RescheduleRequestedListener` (abonné `booking.reschedule_requested` → crée proposition, cherche créneau, notifie) ; best-effort try/catch (D7) | `app/src/SmartFlow/{Port,Port/Adapter,EventListener}/` | T1, T3 | `RescheduleFlowEndToEndTest::testAucunCreneauCompatibleResteEnAttente`, `SmartFlowListenerBestEffortTest` | ⬜ |
| T5 | `AcceptRescheduleProposalProcessor` / `DeclineRescheduleProposalProcessor` (§0.9, clôture seule, revérif IDOR établissement+customerId) | `app/src/SmartFlow/State/`, `app/src/SmartFlow/Entity/` (routes) | T2, T4 | `RescheduleFlowEndToEndTest::testNoShowRestitueDeclencheReportJusquaConfirmation`, `testDeclineFermeImmediatement`, `testAcceptAvecReservationDunAutreClientRefuse422` | ⬜ |
| T6 | `SmartFlowModule implements ModuleManifest` (§0.11, v0.1.0, permissions/événements de I1) | `app/src/SmartFlow/SmartFlowModule.php` | T1–T5 | `SmartFlowModuleManifestTest`, `tests/Platform` (`ManifestCatalogueTest`) | ⬜ |

## Incrément I2 — Créneaux libérés + liste d'attente Smart Flow (non bloqué)

| # | Tâche | Fichiers | Dépend de | Test associé | État |
|---|---|---|---|---|---|
| T7 | Enum `SlotWaitlistEntryStatus` ; entités `SlotWaitlistEntry`/`SlotReleaseTrace` ; migrations `..._2`/`..._3` ; `ALTER` ajoutant `sourceWaitlistEntryRef` sur `RescheduleProposal` (§1, `originReservationRef` nullable) | `app/src/SmartFlow/{Enum,Entity}/`, `app/migrations/` | T1 | — | ⬜ |
| T8 | `SlotFreedListener` (abonné `booking.cancelled`/`booking.no_show`) : relit dispo réelle (RG-SF-02), écrit `SlotReleaseTrace` idempotent (§0.4), publie `slot.released` plat (§0.5) | `app/src/SmartFlow/EventListener/` | T7 | `SlotReleaseListenerTest::testBookingCancelledPubliesSlotReleasedSiPlaceRestante`, `testPromotionListeAttenteInterneDejaConsommeeNePubliePas`, `testIdempotenceMemeTriggerNePublieQuUneFois` | ⬜ |
| T9 | `SlotReleasedListener` (auto-consommé, promotion FIFO RG-SF-06, matérialise `RescheduleProposal` liée) ; commande planifiée `smart-flow:waitlist:expirer` (RG-SF-07) | `app/src/SmartFlow/{EventListener,Command}/` | T7, T4 | `SlotWaitlistPromotionTest::testPromotionFifoSurRessourceALaReceptionSlotReleased`, `testExpirationSansConfirmationTenteLInscriptionSuivante` | ⬜ |
| T10 | `CreateSlotWaitlistEntryProcessor` + `#[ApiResource]` `SlotWaitlistEntry` (`read:false`, établissement serveur, revérif `resourceId`) | `app/src/SmartFlow/{State,Entity}/` | T2, T7 | `CloisonnementSmartFlowTest::testInscriptionListeAttenteRessourceHorsPerimetreRefusee422` | ⬜ |
| T11 | MàJ `SmartFlowModule` (permissions/événements I2 : `slot.released` émis, feature `slot_recovery`) + revue de cohérence | `app/src/SmartFlow/SmartFlowModule.php` | T6, T7–T10 | `SmartFlowModuleManifestTest` | ⬜ |

## Incrément I3 — Affluence (⛔ bloqué : `access.recorded` non émis, SF-1 hors périmètre)

| # | Tâche | Fichiers | Dépend de | Test associé | État |
|---|---|---|---|---|---|
| T12 | Entité `FootfallAggregate` ; migration `..._4` ; `FootfallListener` (abonné `access.recorded`/`access.card_recharged`, upsert par `(establishment, periodStart)`) | `app/src/SmartFlow/{Entity,EventListener}/`, `app/migrations/` | T1 | `FootfallAggregationTest::testAccessRecordedIncrementeGrantedOuDenied`, `testCardRechargedAlimenteUnCompteurDistinct` (événement simulé) | ⛔ |
| T13 | `#[ApiResource]` lecture `FootfallAggregate` + MàJ finale `SmartFlowModule` (feature `footfall_reporting`) | `app/src/SmartFlow/{Entity,SmartFlowModule.php}` | T12 | — | ⛔ |

## Transverse

| # | Tâche | Fichiers | Dépend de | Test associé | État |
|---|---|---|---|---|---|
| T14 | Revue de cohérence (constitution §8) : `GET /health`, non-régression `tests/Reservation`+`tests/Acces` (aucun fichier de ces modules touché), i18n des libellés de modales (clés `smart_flow.*`, D13). **`mapping.paths` : ajouter `src/SmartFlow/Entity` — signalé à l'intégrateur (§2), non fait ici.** | `tests/SmartFlow/`, i18n | T6 | suite `tests/SmartFlow` + `tests/Platform` | ⬜ |

État : ⬜ à faire · 🟦 en cours · ✅ fait · ⛔ bloqué

## Arbitrages claude-A qui conditionnent l'implémentation (voir plan §7 + `RAPPORTS/claude-E.md`)

1. Dérogation **RG-SF-17** (lecture Doctrine directe via `ReservationSlotReader` vs route API) — §0.3.
2. Écart catalogue **`slot.released`** (payload plat vs objets) — §0.5, à corriger au catalogue.
3. Correction spec §5 : **`originReservationRef` nullable** (fusion proposition/promotion) — §1.
4. `capability()` = `null` vs `CapaciteCode::SmartFlow` ; `dependencies()` = `[]` transitoire — §7.6/7.7.
5. Tolérance « compatible », fenêtres/délais par défaut, permissions `smart_flow.*` (M8) — §0.6/0.7/3.
