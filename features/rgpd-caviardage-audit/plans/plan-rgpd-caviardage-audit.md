# Plan — rgpd-caviardage-audit

**Spec :** `features/rgpd-caviardage-audit/specs/spec-rgpd-caviardage-audit.md` (CP-1 validé 2026-11-25) · **Issue :** Mdesb/fluvia#363 · **Branche :** `feature/rgpd-caviardage-audit`
**Statut :** prêt pour CP-2 · **Zone sensible** (audit + RGPD) : `relecteur` et `security-reviewer` en mode adversarial à l'étape 9.

En clair : quand une fiche client est anonymisée, ses anciennes lignes du journal d'audit (création, modifications) gardent aujourd'hui le nom, l'email, etc. Ce plan les remplace par `[caviardé]`, dans la même opération que l'anonymisation, laisse une trace « combien de lignes », et fournit une commande pour traiter les fiches déjà anonymisées.

## Objectifs repris de la spec

| Id | Objectif |
|---|---|
| G-1 | Caviardage des entrées `Client` de la fiche à l'anonymisation (3 voies), même transaction |
| G-2 | Marqueur `"[caviardé]"` sur les valeurs d'identité non nulles ; rien d'autre ne change ; 11 champs, une seule liste |
| G-3 | Entrée d'audit « caviardage » (qui, quand, combien), sans donnée personnelle |
| G-4 | Périmètre : la fiche client seule, tout le reste identique octet pour octet |
| G-5 | Commande de rattrapage, `--dry-run`, `--limit`, idempotente |
| G-6 | Lecture (API du journal, export) inchangée : les entrées sortent avec le marqueur |
| G-7 | Registre T7 (phrase factuelle seulement) + mode opératoire dans `infra/README.md` |

## Constats qui fondent les décisions (VERIFIED, lus dans le code)

- `EffacementRgpdHandler::anonymiser()` (l. 87) est le point unique ; il ne flush pas. Les trois appelants flushent : `TraiterDemandeRgpdProcessor` (l. 38), `ApplyPersonalDataPurgeProcessor` (l. 85), `AppliquerConservationCommand` (l. 112, un flush par groupe).
- `AuditWriteSubscriber::onFlush` crée l'entrée d'anonymisation **pendant le flush** (l. 205-232) et ignore les `EntreeAudit` (l. 237). Donc : au moment où le caviardage s'exécute (avant le flush), l'entrée d'anonymisation n'existe pas encore en base. Elle ne peut pas être comptée ni réécrite par erreur, et elle est déjà sans identité (l. 245-247). Aucun conflit d'ordre.
- Les entrées du subscriber portent `cibleType = App\Crm\Entity\Client` (FQCN). Les entrées de `JournalAudit` pour le CRM portent le nom court `'Client'` (`ApplyPersonalDataPurgeProcessor` l. 83, `AppliquerConservationCommand` l. 109, `PersonalDataExportProvider` l. 153) et **aucune valeur** : rien à caviarder. `RenouvellementMajoriteHandler` (l. 58-63) écrit en FQCN sans valeur non plus. Le caviardage ne cible donc que le FQCN, comme la spec (G-4).
- `InstantaneEntiteBuilder::normaliserValeur` rend des scalaires ou `null` (dates en ATOM, enums en valeur, tableaux/objets en `null`) : les valeurs d'identité sont des scalaires, un remplacement par chaîne est sûr.
- `EntreeAudit` : aucun index sur `(cible_type, cible_id)` (seul `idx_audit_date`). `cible_type` VARCHAR(180) + `cible_id` VARCHAR(64) en utf8mb4 = 976 octets, sous la limite d'index InnoDB.
- Aucun SQLFilter Doctrine, aucune fonction SQL JSON (`JSON_SET`, etc.) dans `app/src` ni `app/migrations` : on lit et réécrit en PHP.

## 1. Décisions

- **D-1. Un seul service, `App\Audit\Service\ClientAuditRedactor`** (nom anglais, règle D5). C'est le seul code qui écrit une valeur sur une `EntreeAudit` existante. Il porte la constante des 11 champs (`IDENTITY_FIELDS`) et le marqueur (`MARKER = '[caviardé]'`). Le subscriber la lit au lieu de la sienne (`CHAMPS_IDENTITE_CLIENT` disparaît, l. 193-196) : une seule liste (G-2). Placé dans `Audit/Service` (à côté de `JournalAudit`) pour que « qui écrit sur l'audit » reste lisible en un dossier.
- **D-2. Réécriture en PHP via l'ORM, pas en SQL.** Requête DQL paramétrée `SELECT e FROM EntreeAudit e WHERE e.cibleType = :type AND e.cibleId = :id` (`:type = Client::class`), puis `setValeurAvant/Apres` sur les entités gérées. Pas de fonction JSON SQL (aucune n'est utilisée dans le dépôt ; MariaDB 11.4 stocke le JSON en texte, la lecture-réécriture PHP est plus simple à tester et à relire).
- **D-3. Garantie de transaction, sans code de transaction.** Le service ne flush **jamais** : il modifie des entités gérées et persiste la trace. Tout part dans le flush de l'appelant, que Doctrine exécute dans une transaction unique avec l'anonymisation de la fiche. Les trois voies passent par `anonymiser()`, donc un seul point d'appel suffit. Aucun `UPDATE` DBAL direct (il sortirait de la transaction du flush). Le test de l'étape 5 le prouve par un échec injecté en cours d'écriture.
- **D-4. Le caviardage s'appelle dans `EffacementRgpdHandler::anonymiser()`** (après avoir vidé les champs), avec un paramètre `?string $auteur` ajouté : `null` pour une demande et une purge (l'utilisateur courant est l'auteur), `'tache:crm:rgpd:appliquer-conservation'` pour la conservation (même chaîne que `AppliquerConservationCommand` l. 109, pour ne pas laisser une trace sans auteur dans une tâche planifiée).
- **D-5. Forme du caviardage (G-2).** Pour chaque clé de `IDENTITY_FIELDS` **présente** dans le tableau et dont la valeur n'est ni `null` ni déjà le marqueur : remplacée par le marqueur. Aucune clé ajoutée, aucune clé retirée, nulls inchangés. Une fonction pure `redactValues(?array): ?array` porte cette règle (testable sans base) ; elle rend le tableau inchangé s'il n'y a rien à faire.
- **D-6. Idempotence par la donnée.** Une entrée « déjà caviardée » est une entrée où `redactValues` ne change rien. Le service compte les entrées effectivement modifiées ; **s'il y en a 0, il n'écrit aucune trace** (G-5). Pas de drapeau, pas de colonne ajoutée.
- **D-7. Trace (G-3).** Via `JournalAudit::enregistrer('crm.rgpd.caviardage', 'Client', <id>, <établissement de création de la fiche>, $auteur)` puis `setValeurApres(['entrees' => n])`. Même convention que les autres `crm.rgpd.*` (nom court `'Client'`, action préfixée). L'établissement est celui de `Client::getEtablissementCreation()`, comme le résolveur d'audit (`AuditEstablishmentResolver::PATHS`), pour que l'entrée soit lue par le bon établissement et pas seulement par l'éditeur. Elle ne contient ni identité ni identifiant de personne autre que l'UUID de la fiche, déjà présent dans toutes les entrées. Sans `cibleType = FQCN`, elle n'est jamais re-sélectionnée par le caviardage.
- **D-8. Index `(cible_type, cible_id)` : oui**, `idx_audit_cible`. Sans lui, chaque fiche anonymisée coûte un balayage complet d'`audit_entree` (la préprod en compte déjà plus de 76 000 pour la seule classe `Utilisateur`, voir `AuditEstablishmentResolver`), et la purge en traite 500 par appel, le rattrapage tout l'arriéré. Le coût d'écriture de l'index est négligeable sur une table append-only. Migration écrite à la main, SQL demandé à Doctrine avant, dérive contrôlée après (CLAUDE.md « Migrations »).
- **D-9. Commande de rattrapage `crm:rgpd:redact-audit`**, classe `App\Crm\Command\RedactAnonymisedAuditCommand` (nom anglais ; calquée sur `AppliquerConservationCommand`).
  - Parcourt les fiches `StatutClient::Anonymise` **par ordre d'identifiant, avec reprise après le dernier identifiant lu** (pas de `OFFSET`), par pages de 100.
  - `--limit` (défaut 500, entier ≥ 1, sinon `Command::INVALID` comme `AppliquerConservationCommand` l. 72-76) borne le nombre de **fiches réellement caviardées** par passage, pas les fiches examinées : sinon un second passage retomberait toujours sur les mêmes premières fiches déjà propres et n'atteindrait jamais la suite. Une fiche propre coûte une requête indexée.
  - `--dry-run` appelle `countRedactable()` (lecture seule, aucun `persist`, aucun flush) et affiche fiches et entrées.
  - Un flush et un `clear()` par page ; chaque fiche est caviardée puis tracée dans la même page, donc dans la même transaction que sa trace. Auteur de la trace : `'tache:crm:rgpd:redact-audit'`.
  - Pas de filtre par groupe : c'est une tâche d'exploitation lancée une fois, qui n'expose rien (sortie : des compteurs). Elle travaille fiche par fiche, avec l'identifiant de la fiche, jamais une entrée d'un autre `cibleType`.
- **D-10. Pas de planification** de la commande : une fois en préprod, une fois en prod, sous supervision (G-5). Les nouvelles anonymisations sont couvertes par D-4.
- **D-11. Lecture inchangée (G-6)** : aucun fichier de lecture n'est touché ; une étape de test seulement.
- **D-12. Fiches de documentation** : aucune (voir section 5).

## 2. Garde-fous `bin/` et tests existants examinés (VERIFIED par lecture, à confirmer par exécution à l'étape 9)

- Aucun garde-fou de `bin/` ne mentionne un setter ni une écriture sur `EntreeAudit` : la seule occurrence (`bin/garde-fou-references-libres.php` l. 35) concerne la colonne `etablissement`. `garde-fou-post-sans-suppression`, `etablissement-ecrivable`, `ecriture-transfrontiere` et `cloisonnement` ne regardent que des opérations API Platform (`Post/Patch/Put`, `$uriVariables`) : sans objet, la commande et le service ne sont pas exposés.
- À surveiller à l'exécution : `garde-fou-nommage-anglais` (identifiants des fichiers ajoutés, lexique `bin/nommage.lexique-francais.txt`), `garde-fou-vacuite-tests` (tout test doit asserter), `garde-fou-migrations-immuables` (on n'ajoute qu'une migration, on ne modifie aucune ancienne), `garde-fou-defauts-mapping`, `garde-fou-drop-migrations` (le `down()` ne contient qu'un `DROP INDEX`, à vérifier contre la règle).
- `AuditTenantIsolationTest` : son test `testChaqueClasseAuditeeADecideDeSonEtablissement` (l. 124) liste les classes de `CLASSES_SURVEILLEES`. Aucune classe n'est ajoutée à l'audit : il n'est pas modifié. `testUneFicheClientNeSeLitPasDansLAuditDUnAutreClient` (l. 30) lit des entrées client : il doit rester vert tel quel (le marqueur n'est pas une fuite).
- `PersonalDataPurgeTest` l. 81-85 vérifie déjà que l'entrée d'anonymisation ne recopie pas le nom : il reste vert et sert de témoin.

## 3. Levée de l'UNVERIFIED « écritures d'audit hors subscriber »

Lu : `JournalAudit`, `SynchroOperationsProcessor` (l. 322, 328), `ServiceAutorisation::journaliserRefus` (l. 153-165), `GestionnaireEscalade`, `PersonalDataExportProvider` (l. 153), et tous les `setValeurAvant/Apres` de `app/src`.

- **Aucun champ nominatif d'un client** (nom, prénom, email, téléphone, naissance, adresse, SIRET, raison sociale, référence externe) n'est écrit par ces chemins.
  - `JournalAudit` CRM (`crm.rgpd.export`, `epuration`, `conservation-echue`) : pas de valeurs.
  - `SynchroOperationsProcessor` : `cibleType 'Vente'`, valeurs = raison d'écart, clé d'idempotence, numéro/séquence locale, montant, booléen `payeParCettePersonne`. L'UUID de la vente seulement, comme dans NF525.
  - `Autorisation` : `operation`, `montant`, `plafond`, `perimetre`. L'**auteur** est l'email d'un utilisateur de l'établissement (personnel), hors périmètre (spec).
  - `PersonalDataExportProvider` : action seule.
- **À signaler, hors périmètre, sans l'élargir :** `JournalFusion` (`crm_journal_fusion.snapshotAvant`) conserve l'instantané des fiches fusionnées, donc de l'identité. Ce n'est pas une entrée d'audit (c'est une table métier) ; ses entrées `EntreeAudit` (`cibleType JournalFusion`) n'ont que des scalaires, les tableaux étant normalisés en `null`. Il ne relève pas de ce lot : à porter au lot suivant déjà annoncé au registre (« Données liées non effacées »). **À dire à Maxime en CP-2.**
- L'UNVERIFIED de la spec est donc levé : aucun autre `cibleType` n'est à caviarder.

## 4. Étapes

Chaque étape : un commit, le build/lint passe (`./bin/garde-fous.sh` sur le diff, tests ciblés verts). Lignes = estimation du code hors tests/migration ; tous les commits restent sous 400. Les tests sont écrits **avant** le code de l'étape (rouge puis vert). Suite PHP : `./infra/test-stack.sh up <jeton>` puis `run <jeton> <chemins>` dans un worktree.

### Étape 1 — Index `(cible_type, cible_id)` (D-8) — couvre : support de G-1, G-5
- **Fichiers :** `app/src/Audit/Entity/EntreeAudit.php` (ajouter `#[ORM\Index(name: 'idx_audit_cible', columns: ['cible_type', 'cible_id'])]` l. 29) ; `app/migrations/Version<horodatage>.php` (nouvelle, à la main).
- **Procédure :** ajouter l'attribut ; `doctrine:schema:update --dump-sql` et ne garder que la ligne `audit_entree` (attendu : `CREATE INDEX idx_audit_cible ON audit_entree (cible_type, cible_id)`) ; écrire `up()` avec cette seule requête ; `down()` : `DROP INDEX idx_audit_cible ON audit_entree`. Ne pas toucher `idx_audit_date`, ne pas lancer `migrations:diff`.
- **Fait quand :** la migration passe sur une base vide ; `doctrine:schema:update --dump-sql` ne propose plus rien pour `audit_entree` ; `bin/verifier-derive-schema.sh <jeton>` n'ajoute aucune dérive sur `audit_entree` ; garde-fous verts.
- **Tests :** pas de test PHP propre (la dérive est la preuve). Un test d'intégration existant d'audit (`AuditAvantApresTest`) reste vert.
- **Taille :** ~2 lignes de code + migration ~25.

### Étape 2 — Une seule liste des 11 champs (D-1) — couvre : G-2 (liste partagée)
- **Fichiers :** `app/src/Audit/Service/ClientAuditRedactor.php` (nouveau, **constantes seulement** : `IDENTITY_FIELDS` = les 11 champs, `MARKER`) ; `app/src/Audit/Doctrine/AuditWriteSubscriber.php` (retirer la constante privée l. 177-196 en gardant le commentaire explicatif #344, le déplacer sur la constante du service ; l. 246 lit `ClientAuditRedactor::IDENTITY_FIELDS`).
- **Test d'abord :** `app/tests/Audit/Unit/ClientAuditRedactorTest.php` — `testIdentityFieldsAreTheElevenExpectedOnes` (liste exacte, 11 entrées) ; le test existant `PersonalDataPurgeTest::testApplyAnonymisesSelectionAndIgnoresForeignOrProtectedIds` sert de non-régression de l'expurgation.
- **Fait quand :** `ClientAuditRedactorTest` et `PersonalDataPurgeTest` verts ; `grep CHAMPS_IDENTITE_CLIENT app/src` ne rend plus rien ; garde-fous verts.
- **Taille :** ~25 lignes (surtout un déplacement de commentaire).

### Étape 3 — Règle de caviardage d'un tableau (D-5, D-6) — couvre : G-2
- **Fichiers :** `ClientAuditRedactor.php` (ajouter `public function redactValues(?array $values): ?array`, pure, sans dépendance).
- **Tests d'abord** (même fichier unitaire) : valeurs d'identité non nulles → marqueur ; `null` reste `null` ; clé absente non ajoutée ; champs non personnels (`statut`, `dateMaj`, `majPar`, `type`) inchangés ; clés et ordre inchangés ; `null` en entrée → `null` ; déjà caviardé → tableau identique (idempotence) ; valeur `''` ou `0` non nulle → marqueur (la règle est « non nul »).
- **Fait quand :** tests unitaires verts, sans base.
- **Taille :** ~20 lignes.

### Étape 4 — Caviardage d'une fiche + trace (D-2, D-3, D-6, D-7) — couvre : G-1 (cœur), G-3, G-4
- **Fichiers :** `ClientAuditRedactor.php` (constructeur : `EntityManagerInterface`, `JournalAudit` ; méthodes `redact(Client $client, ?string $auteur = null): int` et `countRedactable(string $clientId): int`).
  - `redact` : DQL paramétrée (D-2) ; pour chaque entrée, applique `redactValues` à `valeurAvant` et `valeurApres` ; ne touche l'entité que si l'un des deux a changé ; si le nombre d'entrées modifiées est 0, retourne 0 sans trace ; sinon trace D-7 et retourne le nombre. Ne flush pas.
  - `countRedactable` : même requête, même règle, sans aucune écriture (pour `--dry-run`).
- **Tests d'abord** : `app/tests/Audit/Api/ClientAuditRedactionTest.php` (base de test, gabarit `CrmApiTestCase` comme `PersonalDataPurgeTest`) :
  - Deux fiches U1/U2 avec chacune une entrée `creation` et une `modification` (écrites par le vrai flush, donc par le subscriber). **Témoin positif** : avant, la recherche des valeurs exactes de U1 (nom, prénom, email, téléphone) dans le JSON des entrées de U1 les trouve.
  - `redact(U1)` + `flush` : plus aucune valeur de U1 dans ses entrées ; le marqueur est présent ; clés/date/auteur/action inchangés ; les entrées de U2, une entrée d'une autre classe (ex. `Famille`) et une entrée NF525 (`OperationScellee`/`Vente`) sont **identiques ligne à ligne** (comparaison des colonnes lues par DBAL avant/après).
  - Trace : une entrée `crm.rgpd.caviardage`, `cibleType 'Client'`, `valeurApres == ['entrees' => 2]`, établissement = celui de création de la fiche, aucune valeur d'identité dans son JSON.
  - Idempotence : second `redact` → 0, aucune nouvelle entrée de trace (nombre de lignes `audit_entree` identique).
  - `countRedactable` : retourne 2 avant, 0 après ; aucune ligne modifiée par l'appel avant `redact`.
- **Fait quand :** ces tests verts ; `AuditTenantIsolationTest` et `AuditAvantApresTest` verts.
- **Taille :** ~65 lignes.

### Étape 5 — Brancher sur l'anonymisation, les trois voies (D-3, D-4) — couvre : G-1, G-4
- **Fichiers :** `app/src/Crm/Service/EffacementRgpdHandler.php` (constructeur : + `ClientAuditRedactor` ; `anonymiser(Client $client, string $trace, ?string $auteur)` appelle `$this->redactor->redact($client, $auteur)` après le vidage des champs, avant le flush de l'appelant ; `traiter` passe `null`, `anonymiseWithoutRequest` passe `null` pour `Purge` et `'tache:crm:rgpd:appliquer-conservation'` pour `Retention`) ; mettre à jour le commentaire de classe (l. 17-27) et celui de `anonymiser`.
- **Tests d'abord** (dans `PersonalDataPurgeTest` ou `app/tests/Crm/Api/RgpdAuditRedactionTest.php`, selon la taille du fichier existant ; `seed()` y existe) :
  - **Les trois voies**, chacune avec U1/U2 (création + modification, valeurs exactes en témoin positif avant) : demande (`traiter` via `/api/…` du `RgpdTest`), purge (`POST /api/rgpd/purge`), conservation (`CommandTester` sur `crm:rgpd:appliquer-conservation`, modèle l. 21-22 du test existant). Après : aucune valeur de U1 dans ses entrées ; entrées de U2 / autre entité / NF525 identiques ; une trace par fiche.
  - **Transaction** : un écouteur Doctrine de test, enregistré sur `postUpdate`, lève une exception au **second** `postUpdate` vu parmi `Client` et `EntreeAudit` (ordre d'écriture non garanti : on casse la seconde, donc la première a déjà été écrite). Après l'échec du flush : fiche toujours `Actif` avec son nom, entrées d'audit identiques, aucune trace (lecture DBAL sur une connexion fraîche). C'est le témoin qu'une seule transaction couvre les deux écritures ; si l'ordre d'exécution de Doctrine rend ce montage non déterministe, le développeur documente l'alternative retenue dans le test, sans retirer l'exigence G-1.
  - Témoin que l'entrée d'anonymisation elle-même est inchangée par rapport à aujourd'hui (pas de marqueur dedans : l'identité en est absente, pas masquée) et n'est pas comptée dans `entrees`.
- **Fait quand :** tests verts ; `PersonalDataPurgeTest`, `RgpdTest`, `FusionTest` verts ; garde-fous verts.
- **Taille :** ~15 lignes.

### Étape 6 — Lecture inchangée (G-6) : test seul — couvre : G-6
- **Fichiers :** `app/tests/Audit/Api/ClientAuditRedactionReadTest.php` (nouveau). Aucun code de production.
- **Test :** après une anonymisation, `GET /api/entree_audits?cibleType=App\Crm\Entity\Client` (filtre existant, `EntreeAudit` l. 38-40) et l'export `ExportAuditController` rendent les entrées de la fiche avec `[caviardé]` et sans l'ancien nom. Témoin positif : sans le filtre `cibleType`, la collection n'est pas vide (un filtre muet rendrait aussi vide). Vérifier le chemin réel de la route dans `ExportAuditController` avant d'écrire le test.
- **Fait quand :** test vert sans modification de lecture. Si une lecture casse (ex. un champ attendu non nul), c'est un défaut à signaler en CP-3, pas à contourner.
- **Taille :** 0 ligne de production.

### Étape 7 — Commande de rattrapage (D-9, D-10) — couvre : G-5
- **Fichiers :** `app/src/Crm/Command/RedactAnonymisedAuditCommand.php` (nouveau, `#[AsCommand(name: 'crm:rgpd:redact-audit', …)]`). Dépend de `EntityManagerInterface` et `ClientAuditRedactor`. Options `--dry-run`, `--limit` (défaut `500`), même validation que `AppliquerConservationCommand` l. 60-76. Sortie : « N fiche(s), M entrée(s) » ; en `--dry-run` : « Simulation : rien n'a été écrit ».
- **Tests d'abord** (`app/tests/Crm/Api/RedactAnonymisedAuditCommandTest.php`, `CommandTester`) : trois fiches **déjà anonymisées avec audit non caviardé** (construites en base par DBAL ou en restaurant les valeurs dans les entrées, pour reproduire l'arriéré) + une fiche active + une fiche anonymisée déjà propre.
  - `--dry-run` : compte 3 fiches / N entrées ; lignes `audit_entree` identiques avant et après (comparaison complète des lignes).
  - `--limit=2` : 2 fiches caviardées, la 3e intacte ; un second passage `--limit=2` caviarde la 3e (preuve que la limite compte les fiches caviardées, pas examinées).
  - Passage réel complet : plus aucune valeur d'identité dans l'audit des 3 fiches ; la fiche active et ses entrées inchangées ; une trace par fiche caviardée.
  - Second passage : 0 fiche, 0 entrée, **aucune nouvelle ligne** `audit_entree`.
  - `--limit=0` → code `Command::INVALID`.
  - Cloisonnement : fiches de deux groupes différents toutes traitées par fiche (c'est voulu, tâche d'exploitation) et la trace de chacune porte l'établissement de création de **sa** fiche.
- **Fait quand :** tests verts ; `php bin/console list crm:rgpd` montre la commande ; garde-fous verts (dont nommage anglais).
- **Taille :** ~90 lignes.

### Étape 8 — Documentation (G-7) — couvre : G-7
- **Fichiers :** `docs/juridique/registre-traitements.md` (T7, ligne « Catégories de données » l. 111 uniquement) ; `infra/README.md` (nouvelle sous-section avant « Points de vigilance », l. 348).
- **Registre :** remplacer la phrase « Depuis le 05/11/2026, l'anonymisation d'une fiche n'y recopie plus l'identité effacée » par : à partir de cette livraison, l'anonymisation d'une fiche remplace aussi, dans les entrées antérieures de cette fiche, ses champs d'identité par `[caviardé]` (une entrée « caviardage » en garde la trace) ; les fiches déjà anonymisées sont traitées par la commande de rattrapage. **Ne pas toucher** : la ligne « Durée de conservation » (l. 113), la section « À valider » (l. 151), la mention de version (l. 157) ni toute validation D5. Date exacte et numéro d'Issue (#363) renseignés à la livraison. Aucune mention nouvelle de `JournalFusion` dans le registre : à Maxime de décider (voir section 3).
- **`infra/README.md` :** mode opératoire, dans l'ordre : (1) lancer `php bin/console crm:rgpd:redact-audit --dry-run` et relever fiches/entrées ; (2) sauvegarde de la base (dump) avant le premier passage réel, l'opération réécrit des lignes d'audit ; (3) passage réel avec `--limit` (ex. `--limit=500`), à répéter tant que le compteur n'est pas à 0 ; (4) contrôle : un second passage doit rendre 0 fiche, 0 entrée, sans nouvelle trace ; (5) préprod d'abord, puis prod sous supervision de Maxime ; (6) la commande est sûre à relancer (idempotente) et ne s'applique qu'aux fiches `anonymise`.
- **Fait quand :** relecture ; `grep -n "À arbitrer" docs/juridique/registre-traitements.md` retrouve les mêmes lignes qu'avant ; le diff du registre ne touche que la ligne T7 « Catégories de données » ; garde-fous verts (coherence).
- **Taille :** 0 ligne de code.

### Étape 9 — Vérification de clôture
- Suite PHP complète sur les chemins touchés : `app/tests/Audit`, `app/tests/Crm`, `app/tests/Securite/Api/CloisonnementResiduelTest.php`, `app/tests/SocleTest.php`, puis la suite entière si le temps le permet (la CI ne la lance pas).
- `./bin/garde-fous.sh` entier ; `git checkout -- app/config/reference.php` avant commit.
- `relecteur` et `security-reviewer` en mode adversarial, avec ces questions : une anonymisation peut-elle réussir sans caviardage (voie oubliée, exception avalée) ? Le caviardage peut-il toucher une autre fiche, un autre `cibleType`, une entrée NF525 ? Le service est-il appelé ailleurs que par `anonymiser()` et la commande ? Une entrée réécrite perd-elle une clé ?
- `/verifier-specs` (code ↔ spec), puis `/point-projet`.
- **Fait quand :** garde-fous verts, suite verte, revues consignées, aucun écart spec/code non expliqué.

## 5. Tests — récapitulatif par étape

| Étape | Test | Type |
|---|---|---|
| 1 | dérive de schéma, `AuditAvantApresTest` | contrôle schéma + existant |
| 2 | `ClientAuditRedactorTest` (liste), `PersonalDataPurgeTest` | unitaire + existant |
| 3 | `ClientAuditRedactorTest` (règle pure) | unitaire |
| 4 | `ClientAuditRedactionTest` | intégration (base) |
| 5 | trois voies + transaction (`PersonalDataPurgeTest` ou `RgpdAuditRedactionTest`) | intégration |
| 6 | `ClientAuditRedactionReadTest` | intégration API |
| 7 | `RedactAnonymisedAuditCommandTest` | intégration console |
| 8 | relecture, diff ciblé | revue |
| 9 | suite + garde-fous + revues adversariales | clôture |

## 6. Couverture Goal → Étapes

| Goal | Étapes |
|---|---|
| G-1 Caviardage à l'anonymisation, même transaction | 4, 5 (support : 1) |
| G-2 Forme (marqueur, rien d'autre, 11 champs partagés) | 2, 3, 4 |
| G-3 Trace du caviardage | 4, 5, 7 |
| G-4 Périmètre fiche client seule | 4, 5, 7 |
| G-5 Rattrapage (`--dry-run`, `--limit`, idempotent) | 7 (support : 1, 4) |
| G-6 Lecture inchangée | 6 |
| G-7 Documentation | 8 |

Chaque étape couvre au moins un G-N, et aucune ne couvre un objectif absent de la spec (l'étape 9 est une vérification transverse).

## 7. Fiches à produire

**Aucune.** Le plan n'utilise aucune API tierce nouvelle : DQL paramétrée, `EntityManagerInterface`, événements Doctrine (`postUpdate`, déjà utilisés par `AuditWriteSubscriber` pour `onFlush`), `Symfony\Component\Console` (`#[AsCommand]`, `InputOption`, `SymfonyStyle`, `CommandTester`) recopiés de `AppliquerConservationCommand` et `PersonalDataPurgeTest`. Versions installées : à lire dans `app/composer.lock` si une question de signature se pose pendant le développement, et à ce moment seulement lancer `documentaliste`.

## 8. UNVERIFIED restants (non bloquants pour CP-2, à lever en cours de construction)

1. **Volumétrie d'`audit_entree` en préprod et durée de `CREATE INDEX`** : non mesurée (pas d'accès à la base ici). Seul chiffre connu : 76 912 entrées `Utilisateur` au 01/10 (commentaire de `AuditEstablishmentResolver`). À mesurer (`SELECT COUNT(*)`) avant le déploiement de l'étape 1 ; l'index est de toute façon retenu. Taille de page du rattrapage (100) à confirmer sur le premier `--dry-run` préprod.
2. **Test de transaction à l'étape 5** : le montage `postUpdate` dépend de l'ordre d'exécution interne de Doctrine ; l'étape dit quoi faire s'il est non déterministe. À confirmer au développement.
3. **Chemin exact de la route d'export d'audit** (`ExportAuditController`) pour le test de l'étape 6 : à lire au moment d'écrire le test.
4. **Les 11 champs couvrent-ils toute l'identité de `Client` ?** La spec fixe les 11 champs (G-2) et le plan ne les change pas. Je n'ai pas relu l'inventaire complet des colonnes de `Client` (hors `importBatchRef`) : si un champ nominatif supplémentaire existe, c'est une décision de Maxime, pas de ce lot.
5. **`JournalFusion.snapshotAvant`** : identité conservée hors audit (section 3). À signaler à Maxime en CP-2 ; le lot ne bouge pas.

## 9. Estimation des lignes (code de production, hors tests et migration)

| Étape | Lignes |
|---|---|
| 1 | ~2 |
| 2 | ~25 |
| 3 | ~20 |
| 4 | ~65 |
| 5 | ~15 |
| 6 | 0 |
| 7 | ~90 |
| 8 | 0 (doc) |
| **Total** | **~220**, aucun commit au-dessus de 400 |
