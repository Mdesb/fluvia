# Suivi d'implémentation — rgpd-caviardage-audit

**État :** build (étapes 1 à 8 faites ; étape 9 = relectures, à Maxime) <!-- discovery → spec → plan → build → review → done -->
**Branche :** feature/rgpd-caviardage-audit
**Spec :** features/rgpd-caviardage-audit/specs/spec-rgpd-caviardage-audit.md
**Plan :** features/rgpd-caviardage-audit/plans/plan-rgpd-caviardage-audit.md
**Issue :** Mdesb/fluvia#363

## Checkpoints

- [x] **CP-1** — spec validée par Maxime, 2026-11-25
- [x] **CP-2** — porte automatique ; Maxime a dispensé de présentation (2026-11-25)
- [ ] **CP-3** — revue avant merge (date + qui)

## Étapes (reprises du plan)

- [x] Étape 1 — Index `idx_audit_cible (cible_type, cible_id)` + migration `Version20261125120000` (couvre G-1, G-5)
- [x] Étape 2 — Une seule liste des 11 champs : `ClientAuditRedactor::IDENTITY_FIELDS`, lue par le subscriber (couvre G-2)
- [x] Étape 3 — `redactValues()` : règle pure de caviardage d'un tableau (couvre G-2)
- [x] Étape 4 — `redact()` / `countRedactable()` + trace `crm.rgpd.caviardage` (couvre G-1, G-3, G-4)
- [x] Étape 5 — Branché dans `EffacementRgpdHandler::anonymiser()`, trois voies + test de transaction (couvre G-1, G-4)
- [x] Étape 6 — Lecture inchangée : test seul (couvre G-6)
- [x] Étape 7 — Commande `crm:rgpd:redact-audit` (couvre G-5)
- [x] Étape 8 — Registre T7 (phrase factuelle) + mode opératoire `infra/README.md` (couvre G-7)
- [ ] Étape 9 — Vérification de clôture (relecteur, security-reviewer, /verifier-specs) : à Maxime

## Journal de Session

- **Étape 1.** `doctrine:schema:update --dump-sql` (stack cloud1) a proposé `CREATE INDEX idx_audit_cible ON audit_entree (cible_type, cible_id)` ; seule cette ligne est reprise (l'autre ligne du dump, `finance_treasury_cash_alert`, est une dérive préexistante hors lot). Base reconstruite PAR LES MIGRATIONS (pile jetable `d363`, 247 migrations) : `doctrine:schema:update --dump-sql --complete` ne mentionne plus `audit_entree`, `SHOW INDEX` montre les deux index. `down()` exécuté : l'index est de nouveau proposé (réversible). `bin/verifier-derive-schema.sh` n'a pas pu servir tel quel : son `docker run` n'a pas le `zz-memory.ini`, la migration meurt en 128 Mo ; la même mesure a été refaite à la main avec `memory_limit=1G`. Pile `d363` supprimée.
- **Étape 4, ordre de test.** `ClientAuditRedactor` n'est injecté nulle part avant l'étape 5 : le conteneur de test le supprime, donc les tests de l'étape 4 le construisent avec `new` (`JournalAudit` pris au conteneur).
- **Étape 4, requête.** Le plan prévoyait du DQL `e.cibleId = :id`. Le garde-fou D58 (références libres) juge par le NOM de propriété et confond `EntreeAudit::cibleId` (VARCHAR) avec `OperationScellee::$cibleId` (`uuid`) ; il exige un type `'uuid'`, faux ici. Remplacé par `getRepository(EntreeAudit::class)->findBy(['cibleType' => Client::class, 'cibleId' => $id])` : paramétré, couvert par l'index, garde-fou vert, `bin/` inchangé.
- **Étape 4, JSON.** Le JSON stocké échappe l'Unicode (`é`) : les tests relisent le JSON (décodé) avant de chercher le marqueur ou une valeur.
- **Étape 5, transaction.** Montage `postUpdate` retenu tel que le plan le décrit (seconde écriture parmi `Client`/`EntreeAudit`). Il est déterministe ici : la panne tombe sur la 2e des 3 mises à jour (1 `Client` + 2 `EntreeAudit`), donc une première écriture est partie ; témoin positif `vues === 2`. Après l'échec, lecture sur connexion neuve : audit identique ligne à ligne, fiche toujours `actif`, aucune trace. Le test construit `EffacementRgpdHandler` à la main (le service n'est pas public) ; les trois tests de voie passent, eux, par le conteneur réel.
- **Étape 6, export.** `GET /audit/export` ne porte AUCUNE valeur (colonnes : id, dateHeure, auteur, action, cibleType, cibleId, etablissement). Le critère G-6 « l'export montre le marqueur » ne peut donc pas être vrai à la lettre : le test vérifie que l'export liste les entrées de la fiche et ne contient aucune ancienne valeur. L'API JSON, elle, rend le marqueur.
- **Étape 7, dry-run et limite.** `--dry-run` respecte `--limit` (il annonce ce qu'un passage réel ferait). Le mode opératoire dit donc d'utiliser `--limit=100000` pour mesurer tout l'arriéré. Accepte aussi `--limit=abc` comme invalide (`filter_var`, plus strict que le cast de `AppliquerConservationCommand`).
- **Étape 7, test.** Les fiches « déjà anonymisées » sont construites en passant le statut à `anonymise` par DBAL (`UNHEX` : `crm_client.id` est un BINARY(16)). Page de 100 non couverte par un test (il faudrait > 100 fiches) : voir les UNVERIFIED.
- **Étape 8.** Seule la phrase de la ligne « Catégories de données » de T7 est remplacée ; « Durée de conservation », « À valider », version, D5 : inchangés (`grep "À arbitrer"` rend les mêmes 3 lignes). La date de livraison n'est pas écrite (la phrase dit « depuis #363 ») : à compléter au merge si Maxime le veut.
- **Champ nominatif hors des 11 ?** Relu les propriétés de `Client` : aucun champ nominatif en plus. `preferredPaymentMethodCode`, `regionGeographique` (région déduite du code postal, donnée géographique grossière), `caCumule`, dates et `majPar` ne sont pas dans la liste. À signaler seulement : `regionGeographique` est une donnée dérivée de l'adresse. `adresse` et `champManuel` sont des tableaux, que `InstantaneEntiteBuilder` normalise en `null` : ils ne sont de fait jamais écrits dans l'audit.

## Relectures adversariales (correctifs appliqués)

1. **Migration en collision (bloquant).** `feature/payfip-numcli-par-exploitant` porte déjà `Version20261125100000` et `Version20261125110000`. Fichier ET classe renommés en `Version20261125120000` (les mentions du journal ci-dessus ont suivi) ; commentaire corrigé (elle passe après les migrations du lot D2-B, plus « après la dernière de `main` »). Contrôle de dérive refait : pile `d363` neuve, 247+ migrations, `schema:update --dump-sql --complete` ne mentionne plus `audit_entree`, `SHOW INDEX` montre `idx_audit_date` et `idx_audit_cible` ; `down()` exécuté, l'index est de nouveau proposé ; pile supprimée.
2. **Mémoire.** `ClientAuditRedactor::entriesOf()` est un générateur : lots de 500 (`findBy` ordonné par id, avec OFFSET : la sélection ne change pas pendant la lecture, caviarder une entrée ne touche ni son type ni sa cible), et chaque entrée qui n'a rien à écrire est détachée (`detach`) aussitôt ; `countRedactable` détache tout. Une entrée MODIFIÉE reste gérée jusqu'au flush de l'appelant (le service ne flushe jamais) : c'est incompressible. Mesure (test à 602 entrées, plus d'un lot) : environ 6 Mo, soit 10 Ko par entrée modifiée. La commande traite désormais **une fiche, un flush, un `clear()`** (elle ne garde plus un lot de 100 fiches) ; elle lit des identifiants, pas des entités. **Voie purge** (`ApplyPersonalDataPurgeProcessor`, jusqu'à 500 fiches en un flush) : NON modifiée. Un flush par fiche exigerait de `clear()` l'unité de travail, donc de recharger les fiches et l'administrateur et de changer la sémantique tout-ou-rien de l'endpoint ; limite documentée dans `infra/README.md` (tient jusqu'à environ 100 entrées par fiche en moyenne sous `memory_limit = 512M`). Test à 601+ entrées : `testRedactReadsACustomerWithManyEntriesInBatches`.
3. **`--dry-run` tronqué.** Quand la limite est atteinte, la commande affiche « limite atteinte : il peut en rester » (essai comme passage réel) ; absent quand la limite n'est pas atteinte (témoin). Test `testLimitReachedIsAnnouncedInDryRunAndRealRun`.
4. **`infra/README.md`.** Formes préprod (`docker compose exec php php bin/console …`) et production (`docker compose -f infra/compose.prod.yaml --env-file infra/.env.prod exec -T php php bin/console …`) ; « ne jamais lancer deux exécutions en même temps » ; limite de l'épuration.
5. **Registre T7.** « sont à traiter par une commande de rattrapage (passage en préprod puis en production : à consigner) » ; seule cette ligne du registre est modifiée.
6. **Nommage.** Identifiants des fichiers ajoutés passés en anglais (tests et commande) ; `setNom/setPrenom/setTelephone` sans effet retirés ; constantes avant le constructeur dans `ClientAuditRedactor`. Le paramètre ajouté à `EffacementRgpdHandler::anonymiser()` s'appelle `$author`.
7. **Notes (pas de code).**
   - `auteur` d'une entrée est le courriel de l'utilisateur courant. **Chemin d'écriture par l'usager lui-même : oui.** `PATCH /api/clients/{id}` accepte `crm.modifier_soi` si `object.estLieA(user)` (`Client.php`), et le rôle système « Client final » (`CreationCompteHandler`, `BoutiqueFixtures`) porte `crm.modifier_soi`. Une modification de sa propre fiche par un client de la boutique écrit donc son propre courriel dans `auteur` (non caviardé, le champ est hors périmètre de la spec). À la création du compte (`CreationCompteHandler`), la fiche est créée sans utilisateur connecté : `auteur` y est `null`. Les entrées d'audit de l'`Utilisateur` de ce même client (cibleType `Utilisateur`, courriel et nom en valeurs) ne sont pas non plus caviardées : hors périmètre, à ajouter au lot suivant.
   - Les entités liées (`Famille`, `Beneficiaire`, `CompteClient`) restent hors périmètre (lot suivant).

## Journal de Rétropropagation

<!-- Rempli par /verifier-specs : date, écart trouvé, décision prise, fichier spec modifié. -->
