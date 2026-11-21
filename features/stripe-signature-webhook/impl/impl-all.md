# Suivi d'implémentation — stripe-signature-webhook

**État :** build
**Branche :** feature/stripe-signature-webhook
**Spec :** features/stripe-signature-webhook/specs/spec-stripe-signature-webhook.md
**Plan :** features/stripe-signature-webhook/plans/plan-stripe-signature-webhook.md

## Checkpoints

- [x] **CP-1** — spec validée par l'humain (2026-11-21)
- [x] **CP-2** — plan validé (porte automatique, 2026-11-21)
- [ ] **CP-3** — revue avant merge (date + qui)

## Étapes (reprises du plan)

- [x] Étape 1 — supprimée en CP-2 (pas de symfony/clock)
- [x] Étape 2 — Contrat : interface, exception, enum de motifs (G-3, G-8)
- [x] Étape 3 — Adaptateur + vérificateur pur de signature (G-1, G-2, G-4, G-8)
- [x] Étape 4 — Contrôleur : garde avant tout accès (G-3, G-4, G-6)
- [x] Étape 5 — Test d'API Stripe de bout en bout (+ doublure `FakeStripeServer`, bloc `when@test`, test du contrôleur avec logger espion)
- [x] Étape 6 — Faux secrets générés à l'exécution ; aucun test existant modifié ni affaibli (seulement des ajouts)
- [x] Étape 7 — Documentation d'exploitation (`infra/README.md`, section Stripe)

## Journal de Session

- 2026-11-21 (lot D5-B, étapes 2-4) : le vérificateur du plan `StripeSignatureVerifier` est renommé
  `StripeSignatureChecker` : le garde-fou D5 refuse « Verifier » (français « vérifier »). Signature inchangée
  (`verify(header, body, secret, now, tolerance = 300)`).
- `app/.env` illisible pour l'agent : aucun marqueur `STRIPE_WEBHOOK_SECRET` ajouté (le préfixe `default::` rend la variable facultative).
- Plusieurs `t` ou `v0` seul → `Malformed` ; secret vide testé avant l'en-tête → `NotConfigured`.
- Suite `tests/Boutique` (étapes 2-4) : 202 tests verts. Garde-fous : 3 échecs attendus (PHP 8.3 de l'hôte).
- Corrections de relecture : (1) message du contrôleur générique (« secret de signature des notifications non configuré »), docblock mis à jour ; (2) `testNothingSecretReachesTheLogger` remplacé par `testTheRejectionCarriesNoSecretHeaderOrBody` (message, `getTraceAsString()`, `(string) $e`) ; la preuve « logger espion » est dans `OnlinePaymentNotificationControllerTest` ; (3) cas ajoutés : en-tête vide + secret vide → `NotConfigured`, `t` négatif, flottant, `V1=`, espace interne ; (4) `use` triés.
- Étape 5 : le bloc `when@test` de `services.yaml` ne reprend PAS les `_defaults` : sans `autoconfigure: true` explicite, l'adaptateur perd son étiquette `boutique.paiement_en_ligne` et le bouchon `PspCbStubAdapter` répond à sa place (piège : `/payer` rendait quand même `cb_psp`, les notifications rendaient 404). Les profils exploitants de la vitrine A sont passés en `GroupePrive` dans le test. Compteur SQL : `doctrine.debug_data_holder` (`DebugDataHolder`), avec témoin positif ; compteur d'appels Stripe : `FakeStripeServer::calls()`, avec témoin positif.
- Étape 7 : le témoin du tableau de bord ne peut pas rendre 200 (un événement de test ne porte pas une de nos sessions) : la doc dit « pas 400 » (404 « inconnu » attendu).
- Suite `tests/Boutique` après étapes 5-7 : 215 tests, 1743 assertions, verts. Garde-fous : 3 échecs attendus (manifeste/catalogue, filtres déclarés, table de routes : PHP 8.3).

## Journal de Rétropropagation

<!-- Rempli par /verifier-specs : date, écart trouvé, décision prise, fichier spec modifié. -->

### Rétropropagation (21/11/2026)
- **Écart :** la spec (UNVERIFIED de déploiement) et la fiche disaient qu'un événement de test du tableau de bord Stripe « rend 200 ». C'est faux : la session de test n'est pas une commande Fluvia, donc la réponse est 404 « inconnu », une fois la signature acceptée.
- **Décision :** écart légitime sur le témoin, sans changement de comportement. Le témoin devient « 404 et non 400 », et 200 pour un vrai paiement en mode test.
- **Fichiers corrigés :** `specs/spec-stripe-signature-webhook.md`, `docs/references/stripe-webhooks.md`.
