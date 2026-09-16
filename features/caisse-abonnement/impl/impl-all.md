# Suivi d'implémentation — caisse-abonnement

**État :** build — incrément É1+É2 **VÉRIFIÉ VERT** (50 garde-fous + test d'intégration 4/4, 54 assertions), committé local. Suite : migration down(), coordination SEPA, puis É4→É10.
**Branche (build) :** `feature/caisse-abonnement-v2` (worktree `/home/debian/wt/caisse-abonnement-v2`) — LOCAL, rien poussé. L'ancienne branche `feature/caisse-abonnement` (claude-A) reste en lecture (référence de portage).
**Spec :** features/caisse-abonnement/specs/spec-caisse-abonnement.md (CP-1)
**Plan :** features/caisse-abonnement/plans/plan-caisse-abonnement.md (CP-2, 11 étapes É0→É10)
**Analyse de l'existant :** features/caisse-abonnement/refs/analyse-branche-claude-A.md

## PASSATION — pour qui reprend (16/09, limite hebdo approchée)

**Décisions verrouillées** (CP-1 + CP-2, ne pas re-trancher) : périmètre COMPTOIR seul ; abonnement créé APRÈS le commit de la vente (jamais dans la transaction scellée NF525) ; canal porté par l'abonnement → régime lit le canal (rétractation en-ligne seul) ; bénéficiaire=client + SIRET si personne morale ; paiement = 1er mois encaissé câblé + mandat par lien (carte/empreinte → lot PSP) ; mode « mandat existant » = option visible caissier ; complétion back-office = filet exceptionnel AVEC motif tracé (jamais mandat Actif sans consentement) ; `isSepaActif` = toute facette Formule crée l'abonnement.

**Ce qui est FAIT (É1+É2), posé sur le worktree et committé :**
- Port `app/src/Vente/Port/SaleSubscriptionInterface.php` (défini côté Vente, 0 dépendance Membership) + son implémentation `app/src/Membership/Adapter/SaleSubscriptionAdapter.php` (idempotence via `source_sale_line_id`, gardes cloisonnement : bénéficiaire hors groupe→404, quantité>1, vente anonyme) + `app/src/Membership/Repository/SubscriptionRepository.php` (`findOneBySourceSaleLine`).
- Crochet APRÈS commit dans `app/src/Vente/State/ValiderVenteProcessor.php` : après `service->valider()`, appelle `createSubscriptionsFromSale($data)` — jamais dans la transaction scellée (patron D7-bis / boutique en ligne).
- Mandat `StatutMandatSepa::EnAttente` (`app/src/Sepa/Enum/StatutMandatSepa.php`, additif) — l'abonnement naît sans mandat Actif ; +2 params optionnels sur `SouscriptionAbonnementHandler::souscrire()` ; mapping `source_sale_line_id` sur `Membership.php` ; binding du port dans `services.yaml`.
- Migration `app/migrations/Version20260916180000.php` (colonne + index UNIQUE `source_sale_line_id` sur `sport_abonnement_fitness`).
- Test `app/tests/Membership/Api/CaisseAbonnementCreationTest.php` (4 cas : création après commit ; sans facette→0 ; échec adaptateur→vente valide, pas de rollback ; idempotence).
- **50 garde-fous verts** (1 non exécuté : outil manquant). Un bug corrigé : ligne vide en tête de `ValiderVenteProcessor.php` (declare(strict_types) pas 1re instruction → fatal), retirée.
- **Test d'intégration VERT** : `OK (4 tests, 54 assertions)` — dont « échec du port ne fait PAS rollback du scellement » (G-5, la correction du montage « atomique » de claude-A). Le log « Bénéficiaire introuvable » est le cas de cloisonnement attendu et asséré (test négatif qui passe).

**RESTE À FAIRE (ordre) :**
0. ~~Migration `down()`~~ **RELUE — inversion propre** : `up` ajoute (`source_sale_line_id BINARY(16)` uuid nullable + index UNIQUE `uniq_abo_source_sale_line`), `down` retire dans le bon ordre (DROP index puis DROP colonne), inverse exact ; pas de `@drop-voulu` requis (DROP en `down`), pas de piège `addSql`-différé ; `up()` déjà joué par le montage du schéma au test. Un run up/down/up sur un dump reste le contrôle en or (faible enjeu pour un simple add-column). Pour relancer le test si besoin (le worktree a SON PROPRE vendor) :
   - Vendor du worktree (déjà installé une fois ; à refaire si absent) : `IMG=$(docker inspect billetterie-preprod-php-1 --format '{{.Config.Image}}'); docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer -e COMPOSER_CACHE_DIR=/tmp/composer/cache -v /home/debian/wt/caisse-abonnement-v2/app:/app -w /app "$IMG" composer install --no-interaction --no-scripts`
   - Cycle test : `cd /home/debian/wt/caisse-abonnement-v2 && ./infra/test-stack.sh up jarvis-caisseabo && ./infra/test-stack.sh run jarvis-caisseabo tests/Membership/Api/CaisseAbonnementCreationTest.php --testdox && ./infra/test-stack.sh down jarvis-caisseabo`
   - ⚠ chemin **relatif à `app/`** (`tests/…`, PAS `app/tests/…`). ⚠ jeton contient l'identité (`jarvis-caisseabo`). ⚠ un déploiement préprod retire les dev-deps du clone servi (`./infra/reinstaller-dev.sh` + redémarre FPM) — mais le worktree a son vendor à part.
2. **⚠ COORDINATION SEPA avant tout merge** : `StatutMandatSepa::EnAttente` (additif) touche `App\Sepa`, périmètre de la session SEPA (spec : « de concert »). Prévenir avant de fusionner.
3. Puis É4→É10 du plan : prix + Paiement 1er mois (É4) ; mandat par lien + `Consentement` tracé (É5, calquer `Boutique/EnregistrerConsentementPanierProcessor`) ; canal→régime `SubscriberRegime` (É6, coordonner avec `spec-regime-par-profil` #96) ; SIRET/personne morale (É7) ; écran caissier 3 étapes (É8) ; réécrire/compléter le test + révocation (É9) ; security-reviewer + coordination Vente/SEPA (É10). Rien poussé sans accord de Maxime.

## Checkpoints

- [x] **CP-1** — spec validée — Maxime, 16/09 (challengée contradicteur + finance/juridique/simplificateur).
- [x] **CP-2** — plan validé — Maxime, 16/09 (4 décisions + Q3/Q4).
- [ ] **CP-3** — revue avant merge (security-reviewer + coordination Vente/SEPA/Membership).
