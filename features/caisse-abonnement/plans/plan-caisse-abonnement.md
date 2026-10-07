# Plan — Caisse → abonnement (branche neuve, portage guidé par la spec)

**Statut :** validé (CP-2) — Maxime, 16/09/2026
**Spec :** features/caisse-abonnement/specs/spec-caisse-abonnement.md (CP-1)
**Origine :** branche `feature/caisse-abonnement` (claude-A, 07/09) analysée et **écartée** comme base de code — son cœur « atomique » créait l'abonnement DANS la transaction scellée NF525 (un refus rollback le scel), l'inverse de G-1. On repart neuf, on **porte les briques saines**.

## Décisions CP-2

- **D-a — Branche neuve** depuis `main`, `feature/caisse-abonnement-v2` (Membership). L'ancienne branche reste en lecture (référence de portage). Rebase impossible (symboles renommés Sport→Membership + prémisse transactionnelle fausse sur le handler central).
- **D-b — Lien de signature du mandat : e-mail d'abord**, SMS ajouté ensuite (ne pas bloquer le lot si l'infra SMS n'est pas câblée au comptoir).
- **D-c — Mode « mandat existant » = option VISIBLE** dans l'écran caissier (le caissier peut choisir de réutiliser un mandat actif du même payeur ; cloisonné payeur+établissement, refus fermé sinon).
- **D-d — Complétion back-office = filet EXCEPTIONNEL avec trace de justification** (pas une simple saisie d'IBAN) — pour le client sans e-mail/téléphone valide ; motif/autorisation tracé (cf. patron `motif-legitime`), jamais un mandat `Actif` sans consentement ni trace.

## Taille attendue

~10-14 fichiers backend touchés/créés (frontière transactionnelle, adaptateur + repository Membership, migration ré-horodatée, Paiement 1er mois, mandat par lien + `Consentement`, canal→régime, SIRET), 1 écran React, 1 test d'intégration réécrit. Ordre de grandeur : proche d'une réécriture guidée, avec ~1/3 porté quasi mécaniquement de l'ancienne branche.

## Étapes (chacune un test de fait, `impl-all.md` mis à jour après)

- **É0 — Ouvrir `feature/caisse-abonnement-v2`** depuis `origin/main` (worktree isolé). Ligne de base des garde-fous établie avant toute écriture.
- **É1 — Frontière transactionnelle (G-1, G-5·2)** : appeler `souscrire()` **après** le commit de la vente (patron en ligne `SouscriptionAbonnementEnLigneHandler` l.171→193), jamais dans `connection->transactional()`. *Fait :* un échec de souscription laisse la vente **scellée ET valide** (pas de rollback), avec reprise explicite.
- **É2 — Domaine Membership (G-1, G-5·1)** : porter/réécrire l'adaptateur + le repository sous `App\Membership\Adapter` et `App\Membership\Repository` (dossiers à créer), ciblant l'entité `Membership`. Reprendre l'**idempotence** (UNIQUE `source_sale_line_id` + court-circuit) et les **gardes de cloisonnement** (bénéficiaire hors groupe → 404, quantité>1, vente anonyme). *Fait :* build/lint vert, signature publique de `Membership` inchangée pour guichet/boutique.
- **É3 — Migration** : reprendre le SQL de la branche (table `sport_abonnement_fitness` non renommée) **ré-horodaté** à l'UTC de reprise. *Fait :* jouée up() **et** down() sur une copie réelle.
- **É4 — Prix + 1er mois (G-2, G-4)** : `SubscriptionPriceResolver::forFormula(formule, Canal::Guichet, date)` ; créer un **`Paiement` tracé** pour le 1er mois proratisé, `GenerateurEcheancierHandler` pour la suite en SEPA. *Fait :* test — Paiement 1er mois présent sur la Vente, échéance suivante en SEPA.
- **É5 — Mandat par lien (G-3, D-b, D-d)** : token de signature, endpoint public de signature, **`Consentement` tracé** (calqué sur `Boutique/EnregistrerConsentementPanierProcessor`), abonnement créé mandat `EnAttente` jusqu'à signature. E-mail d'abord. Retirer le mode « counter » qui fabrique un mandat `Actif`. Garder la **complétion back-office comme filet exceptionnel** (D-d) avec **motif tracé**. *Fait :* test — aucun mandat `Actif` ni prélèvement avant signature ; le filet back-office exige un motif.
- **É6 — Canal → régime (G-7)** : persister `canal=Guichet` sur `Membership` ; câbler `SubscriberRegime` pour lire le canal (coordination avec `spec-regime-par-profil` #96, déjà mergée). *Fait :* test — `Guichet` → pas de rétractation ; `EnLigne` → 14 j inchangés.
- **É7 — SIRET/personne morale (D107)** : étendre le volet client avec raison sociale + SIRET + adresse si payeur moral. *Fait :* 422 si moral sans SIRET, 200 avec.
- **É8 — Écran caissier (G-6, D-c)** : parcours 3 étapes (Client pré-rempli / encaissement 1er mois / création + lien), vocabulaire Abonnement/Client/Prélèvement, **option visible « réutiliser un mandat existant »** (D-c), erreurs dans le volet sans perdre la vente. *Fait :* build front vert, parcours vérifié sur l'environnement de test.
- **É9 — Test d'intégration** sous `tests/Membership/Api/`, méthodologie E2E de la branche (API réelle, témoin positif), assertions neuves : succès → vente scellée + Membership + Paiement 1er mois + mandat `EnAttente` + lien émis ; échec → vente **valide** + reprise, 0 avoir ; + couverture de la **révocation** (manquait). *Fait :* suite verte contre `main`.
- **É10 — Revue + coordination** : `security-reviewer` obligatoire (Vente/Caisse = zones sensibles) ; **coordonner** avec les sessions actives sur `App\Vente` (claude-G) et `App\Sepa`/`Membership` (session SEPA) avant tout merge. **Rien poussé sans accord de Maxime.**

## Ce qu'on ne construit pas (dans ce lot)

- Trace de Vente de la souscription **guichet back-office** (dépend de D44-bis) ; **vraie CB comptoir + empreinte CB** (lot prestataire carte) ; cycle de vie complet, onglet Exploitation, correctifs SEPA (cloisonnement, remise auto) ; `App\Subscription` ; renommage `Membership`.
