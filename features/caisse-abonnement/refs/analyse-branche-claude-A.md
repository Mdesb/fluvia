# Analyse — branche `feature/caisse-abonnement` (claude-A, 07/09) vs spec CP-1

> Revue read-only du 16/09 (4 agents + synthèse architecte). La branche existe toujours (5 commits, ~1158 lignes, jamais mergée). **Ne pas la rebaser ni la reprendre comme base de code** — la garder en lecture pour porter les briques ci-dessous.

## Verdict : reprendre-les-idées-refaire

Écart racine : le cœur « mode comptoir, **ATOMIQUE / APPELÉE DANS LA TRANSACTION DE SCELLEMENT** » (`SaleSubscriptionAdapter` docblock, `ValiderVenteService` : `$apresScellement` dans `connection->transactional()`) crée l'abonnement **dans** la transaction scellée NF525 ; un mandat refusé **rollback le scel**. C'est l'inverse de G-1 (« après commit, jamais imbriqué ») — l'anti-patron que le contradicteur du 16/09 a fait exclure. La branche précède aussi le déplacement Sport→Membership (10-11/09) et la spec (16/09).

## À porter tel quel (briques saines)

- **Idempotence** : contrainte UNIQUE `source_sale_line_id` + court-circuit `findOneBySourceSaleLine()` avant création (migration `Version20260907120514.php:26-29`, repo `:29-33`, adaptateur `:79-83`). → sous `App\Membership\Repository` (dossier à créer), migration **ré-horodatée**.
- **Enum `StatutMandatSepa::EnAttente`** + règle « exclu des remises jusqu'à Actif » (`Sepa/Enum/StatutMandatSepa.php:11-13`).
- **Gardes de cloisonnement** de l'adaptateur : bénéficiaire hors groupe → 404 avant `forPurchase` (qui ne cloisonne pas), refus quantité>1, refus vente anonyme (`SaleSubscriptionAdapter.php:86-118`).
- **Port `MandateChoice` + `SaleSubscriptionInterface`** (inversion de dépendance, aucun symbole Sport en dur ; Vente 0 commit sur main depuis la base de fusion) — structure à garder, champs à étendre (lien signature, SIRET).
- **`SubscriptionPriceResolver::forFormula(formule, Canal::Guichet, date)`** — déjà conforme G-4.
- **Méthodologie du test** E2E (`CaisseAbonnementTest.php`) : pilotage API réel, témoin positif avant mesure d'absence — **comme patron**, pas comme fichier (il importe `App\Sport\*` disparu → ne compile plus contre main).
- **Périphérie** (Vente/Sepa/services.yaml/client.js) : 0-3 commits main depuis la base → report quasi mécanique une fois le callback sorti de la transaction.

## À NE PAS reprendre / à refaire

- Le montage transactionnel « atomique » (rollback-in-scel) → **après commit** (G-1).
- Le mode « counter » par défaut qui fabrique un mandat `Actif` depuis un IBAN tapé (viole G-3/D19) → **mandat par lien + `Consentement` tracé**.
- Absents : écran caissier (G-6), canal→régime (G-7), SIRET (D107), Paiement 1er mois tracé (G-2, `montantPremiereCentimes` codé en dur à 0).
- Test caduc + prémisse rollback ; révocation non couverte.

Base de fusion : `0c298ce6`. Branche servie de référence : `feature/caisse-abonnement`.
