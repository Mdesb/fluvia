# Plan — nf525-ordre-cles (#369)

**Spec :** `features/nf525-ordre-cles/specs/spec-nf525-ordre-cles.md` (CP-1 validé, Maxime, 18/11/2026)
**Faits :** `features/nf525-ordre-cles/refs/faits-chercheur.md`
**Branche :** `feature/nf525-ordre-cles` — **Statut :** prêt pour CP-2
**Jeton de test :** `<jeton>` = le jeton propre à la session (`./infra/test-stack.sh up <jeton>` une fois).

## Résumé pour le responsable produit

On apprend aux trois chaînes NF525 (vente, compta, facturation) une règle : une clé qu'une chaîne a quittée n'y
revient jamais. Cette règle est écrite une fois, dans le trousseau, et appliquée à trois endroits : la
vérification (rapport « chaîne rompue »), le refus de sceller (409), et le contrôle de déploiement. Le refus de
sceller ne relit pas la chaîne : il interroge un index sur la clé (début et fin de chaque clé dans la chaîne),
ce qui demande **une migration purement additive : trois index, aucune donnée touchée** (voir D-2, point à
confirmer par Maxime puisque l'arbitrage 1 dit « pas de migration »).

## Objectifs repris de la spec

G-1 vérif. vente · G-2 vérif. compta · G-3 vérif. facturation · G-4 archive fiscale · G-5 refus de sceller ·
G-6 `app:nf525:check-keys` · G-7 chaînes légitimes intactes · G-8 README.

## 1. Décisions

**D-1 — Une règle, deux formes équivalentes, un seul module.** La règle vit dans `app/src/Platform/Nf525/`
(à côté de `SealKeyring.php`, qui est déjà le point commun des trois chaînes ; les trois vérificateurs restent
dupliqués volontairement, voir `ScellementEcritureHandler.php:13-21`). Deux formes de la **même** définition
(« régression » = spec, lignes 26-28) :
- *forme parcours* `SealKeyOrderTracker` : état minimal (clé courante, clés quittées), alimenté lien par lien
  pendant la boucle de chaque vérificateur. Donne la séquence fautive (G-1 à G-4). Aucune requête.
- *forme intervalles* `SealKeyOrder` : à partir de la liste `KeyRun` = (clé ou NULL, première séquence, dernière
  séquence) de chaque clé dans une chaîne. Deux clés distinctes dont les intervalles se chevauchent
  s'entrelacent forcément (les séquences sont distinctes) ; des intervalles disjoints ne s'entrelacent pas ;
  NULL doit venir en premier. Sert au refus de sceller (G-5) et à `check-keys` (G-6), qui n'ont pas les maillons.
- Garde contre la dérive entre les deux formes : un test **exhaustif** (toutes les suites de longueur ≤ 6 sur
  {NULL, A, B, C}) exige que les deux formes donnent le même verdict sur « la chaîne contient une régression ».

**D-2 — Requête ciblée par chaîne ; migration = trois index additifs (à confirmer).** Mesuré dans le code :
- vente : `UNIQUE uniq_op_pdv_sequence (point_de_vente_id, numero_sequence)` (`OperationScellee.php:28`) ;
- compta : `UNIQUE uniq_ecriture_profil_journal_sequence (profil_exploitant_id, journal_id, numero_sequence)`
  (`EcritureComptable.php:34`) ;
- facturation : `idx_facture_chaine (profil_exploitant_id, nature, numero_sequence)` (`Facture.php:69`) — `nature`
  est au milieu, inutilisable pour une recherche par clé ;
- **aucun index ne porte `seal_key_id`** (colonne ajoutée par `Version20261108190000`, sans index).

Sans index sur la clé, savoir « la clé active a-t-elle été quittée ? » exige de lire la chaîne entière à chaque
scellement (cas courant d'une chaîne à une seule clé : rien à trouver, donc tout lire). Ce que la spec interdit
(ligne 58). Avec un index `(colonnes de la chaîne, seal_key_id, numero_sequence)`, `MIN`/`MAX(numero_sequence)`
d'**une** clé est une recherche d'index (coût logarithmique, indépendant de la longueur de la chaîne).
- `idx_op_pdv_key_seq (point_de_vente_id, seal_key_id, numero_sequence)`
- `idx_ecriture_chain_key_seq (profil_exploitant_id, journal_id, seal_key_id, numero_sequence)`
- `idx_facture_chain_key_seq (profil_exploitant_id, seal_key_id, numero_sequence)`
Migration **écrite à la main**, SQL demandé d'abord à Doctrine (`doctrine:schema:update --dump-sql`), absence de
dérive vérifiée après (CLAUDE.md). Additive, réversible (`DROP INDEX`), ne touche aucun maillon (garde
d'inaltérabilité hors de cause : c'est du DDL d'index). **Point à faire confirmer par Maxime avant l'étape 7**
car l'arbitrage 1 dit « ni migration » (lu par le plan comme « pas de migration de données ni de rang »).
Alternative **sans migration** (si Maxime refuse) : lire la chaîne par l'index existant `(chaîne, séquence)` à
chaque scellement, en O(longueur de chaîne) ; elle viole la contrainte de coût de la spec, donc non retenue.

**D-3 — Une requête par chaîne, une recherche par clé connue.** `SealKeyRunQuery` interroge, pour chaque clé du
trousseau (active + retirées) et pour NULL, `MIN`/`MAX(numero_sequence)` (un `UNION ALL`, paramètres liés, noms
de table/colonnes en dur dans trois méthodes `forSales/forAccounting/forInvoicing`, jamais fournis par l'appelant).
Limite assumée et documentée : un identifiant **absent du trousseau** au milieu de la chaîne n'est pas vu par
le refus de sceller ; il l'est par la vérification (« clé inconnue », G-1 à G-3) et par `check-keys`, qui lit tous
les identifiants (`GROUP BY`). À mesurer en étape 7 (`EXPLAIN` : l'index est utilisé, « Select tables optimized
away » ou `range` de une ligne) ; UNVERIFIED jusque-là.

**D-4 — Refus de sceller : une méthode à côté, `assertCanExtend` inchangée.** `SealKeyring::assertCanExtend`
(`:168`) garde sa signature : ses 15+ appelants de test et les trois handlers restent verts, et le contrôle de
signature du dernier maillon reste distinct du contrôle d'ordre. On ajoute
`SealKeyring::assertCanExtendInOrder(list<KeyRun> $runs, string $chainLabel): void`, appelée **après**
`assertCanExtend`. Même exception (`SealingRefusedException`, 409), même niveau d'alerte (`critical`) et mêmes
règles de journal : identifiants seulement (principe : le journal existant, `SealKeyring.php:181-188`).

**D-5 — Règle du refus (sur les `KeyRun` de la chaîne, G-5).** Refuser si :
 (i) la clé active a un intervalle `[f, l]` et une autre clé (NULL compris) a une dernière séquence `> f`
     (la chaîne a quitté la clé active : `A,B` avec active `A`) ;
 (ii) la clé du **dernier** maillon (celle de l'intervalle à la plus grande dernière séquence) est en
     régression : NULL alors qu'une clé non NULL existe, ou une autre clé a une dernière séquence `> f` de
     celle-ci (forge `A,B,A` avec active `C`).
Vérifié à la main sur G-7 : `NULL…NULL,A…A` active A (NULL.l < A.f : passe) ; `A,B,C` active C ; chaîne
vide ; chaîne entièrement NULL, active A ; `A,B` active `B` ou `C` (témoin positif) ; tout passe.
Hors du refus, par fidélité à G-5 : une régression ancienne au milieu d'une chaîne dont le dernier maillon est sain
et la clé active neuve (`A,B,A,C`, active C) ne bloque pas le scellement ; elle rend la chaîne « non intacte »
(G-1 à G-3) et fait échouer `check-keys` (G-6).

**D-6 — Où s'appelle le refus.**
- compta : `ScellementEcritureHandler::sceller` (`:37`), requête ancrée sur `profil + journal` de l'écriture ;
- facturation : `ScellementFactureHandler::sceller` (`:49`), ancrée sur `profil` ;
- vente : **`ScellementHandler::sceller`** (`Vente/Nf525/ScellementHandler.php:28`, seul appelant de
  `SignataireOperation::scelle`, VERIFIED par grep), ancrée sur le point de vente. `HashChainSignataire` n'a ni
  base ni test avec base (six constructions dans `HashChainTest`, `SealKeyRotationTest` vente) : on évite de
  changer son constructeur. `ScellementHandler` reçoit le trousseau `app.nf525.keyring.sales` par
  `#[Autowire]` (il n'est construit que par le conteneur : `CloisonnementChaineScelleeTest.php:221`).
- les deux handlers compta/facturation gardent leur constructeur (`new …Handler($em, $keyring…)` dans les tests) :
  la requête utilise `$this->em->getConnection()`, donc **la même transaction** que le scellement.

**D-7 — Vérificateurs.** Chacun appelle un `SealKeyOrderTracker` neuf avant sa boucle et l'alimente pour **chaque**
maillon (qu'il soit par ailleurs en anomalie ou non), après le contrôle de signature :
`HashChainSignataire::verifieSegment` (`:78`), `ScellementEcritureHandler::verifieChaine` (`:93`),
`ScellementFactureHandler::verifieChaine` (`:110`). Un maillon en régression ajoute **une** anomalie
(`sequence`, `probleme`, plus `type` pour la vente comme les autres). **G-4 est obtenu sans code propre** :
`FiscalArchiveChecker::verifierChaine` (`:86-129`) appelle `verifieSegment` par point de vente ; l'étape 6 le
prouve par un test (la règle s'applique à l'intérieur de chaque segment, comme dit la spec).

**D-8 — Libellés** (ajoutés dans `SealKeyring`, qui porte déjà `problem()` et `retiredVariable()`) :
- anomalie : « régression de clé : le maillon n° N porte la clé d'identifiant X, que cette chaîne avait déjà
  quittée (elle était passée à la clé Y). Une clé quittée ne revient jamais dans une chaîne : maillon forgé ou
  rotation incorrecte. » ; variante NULL : « maillon sans identifiant de clé après un maillon signé par la clé
  X ». Aucun mot « signature invalide » ni « clé inconnue ».
- refus : « Scellement refusé (NF525, <chaîne>) : la chaîne a déjà quitté la clé de scellement active
  (identifiant X, variable NF525_…_SEAL_KEY) ; sceller avec elle ferait revenir la chaîne en arrière. Ne
  remettez pas une ancienne clé : passez à une clé NEUVE (nouvelle valeur de NF525_…_SEAL_KEY, l'ancienne
  active ajoutée à NF525_…_SEAL_RETIRED_KEYS). Procédure : infra/README.md, « Trousseau NF525 ». » Variante
  « dernier maillon en régression » pour (ii) : « le dernier maillon est lui-même en régression (clé X
  réapparue après Y) : vérifiez la chaîne (…/verifier-chaine) ».

**D-9 — Conventions.** Fichiers ajoutés : identifiants anglais (D5). Fichiers existants : leur langue
(messages et commentaires en français ; noms existants conservés).

## 2. Conteneurs qui scellent, et conséquence sur la rotation (G-8)

Mesuré dans `infra/compose.prod.yaml` et `infra/compose.preprod.yaml` :
- **php** (API : ventes, factures, génération compta) — **scelle**. Porte les 6 variables `NF525_*SEAL*`
  (prod `:63-70`, `:126-133`, `:184-191` ; trois blocs d'environnement : php, scheduler, worker).
- **scheduler** — **peut sceller** : `ScheduleCatalog.php:53` (`vente:cloture:journee` : clôtures scellées) et
  `:406` (`compta:pca:reprise-mensuelle` : écritures scellées). Quelles tâches tournent dépend de la liste
  blanche d'`infra/ordonnanceur.sh` (UNVERIFIED : à lire à l'étape 12). ⚠ Le service est sous
  `profiles: ["ordonnanceur"]` (prod `:95`, préprod `:115`) : **`docker compose up -d` sans le profil ne le recrée
  pas** (`deploy-prod.sh:162`, `deploy-preprod.sh:116`). Après une rotation de clé, il peut donc continuer à
  tourner avec l'**ancien** trousseau et sceller `A` après que php a scellé `B` : `A,B,A`, le faux positif
  bloquant que la spec prévoit.
- **worker** (`messenger:consume async`) — **ne scelle pas** aujourd'hui : les trois seuls
  `#[AsMessageHandler]` du code (`Social/PublishSocialPublicationHandler`, `Social/CollectSocialMetricsHandler`,
  `PublicApi/DeliverPartnerWebhookHandler`) ne touchent à aucune chaîne (VERIFIED par grep). Il reçoit pourtant
  les mêmes variables : il est redémarré avec les autres par prudence.
- web, db, sauvegarde, front : ne scellent pas.

Procédure de rotation retenue pour le README (étape 12) : (1) **arrêter** scheduler et worker ; (2) ajouter la
clé active actuelle à `*_RETIRED_KEYS`, poser la **nouvelle** clé (jamais une clé déjà vue) ; (3) mettre à jour la
sauvegarde hors serveur ; (4) `up -d` avec `--profile ordonnanceur` pour recréer **php, worker et scheduler
ensemble** (`--force-recreate` si l'environnement n'a pas changé de son point de vue) ; (5) `app:nf525:check-keys`.
Aucun changement de `compose.*.yaml` ni des scripts de déploiement n'est prévu : c'est une procédure
documentée (risque noté en R-3).

## 3. Étapes

Taille = lignes de code de production (tests comptés à part). Le kit refuse un commit > 400 lignes de code
sans justification : aucune étape ne s'en approche ; on **commit par étape** (PR en brouillon).
Commande de validation commune à chaque étape, en plus de celle indiquée :
`./bin/garde-fous.sh` doit rester vert, et `git checkout -- app/config/reference.php` avant commit.

### Étape 1 — La règle pure (aucun accès base)
- **Objectif :** `KeyRun`, `KeyRegression`, `SealKeyOrderTracker`, `SealKeyOrder` ; rien n'est encore branché.
- **G-N :** G-1, G-2, G-3, G-4, G-5, G-6 (la règle commune), G-7.
- **Fichiers (tous nouveaux) :** `app/src/Platform/Nf525/KeyRun.php`, `KeyRegression.php` (type de régression : clé
  revenue / NULL après clé ; séquence ; clé ; clé quittée), `SealKeyOrderTracker.php`, `SealKeyOrder.php`
  (`hasRegression(list<KeyRun>)`, `extensionBlock(list<KeyRun>, string $activeKeyId): ?…` selon D-5, `regressions` de
  `check-keys`).
- **Tests d'abord :** `app/tests/Platform/Unit/SealKeyOrderTest.php` : suites de G-7 (tout NULL ; NULL puis A ;
  A,B,C ; une clé) = aucune régression, et témoin positif de chaque cas de la spec : `A,A,B,B` ok ; `A,A,B,A`
  régression à la séquence 4 ; `A,A,B,NULL` régression à 4 (NULL après clé) ; `A,B,A,B` deux ; **test exhaustif
  tracker ≡ intervalles** (D-1) ; tables du refus de D-5 (`A,B` active A → bloqué ; `A,B,A` active C → bloqué ;
  `A,B` active B ou C → passe ; chaîne vide passe).
- **Validation :** `./infra/test-stack.sh run <jeton> app/tests/Platform/Unit/SealKeyOrderTest.php` puis `./bin/garde-fous.sh`.
- **Taille :** ≈ 160 lignes de code, ≈ 200 de tests.

### Étape 2 — Libellés et refus dans le trousseau
- **Objectif :** `SealKeyring::regressionProblem(KeyRegression): string` et `assertCanExtendInOrder(...)`
  (D-4, D-8). `assertCanExtend` et `problem()` inchangés.
- **G-N :** G-1, G-2, G-3 (libellé), G-5 (mécanisme).
- **Fichiers :** `app/src/Platform/Nf525/SealKeyring.php` (modifié, en-tête de classe : point 5 ajouté « ordre des
  clés »).
- **Tests d'abord (étendre `SealKeyringTest.php`) :** le libellé de régression contient séquence et identifiants
  et **ni** « signature invalide » **ni** « clé inconnue » **ni** aucune clé (valeur) ; `assertCanExtendInOrder`
  lève `SealingRefusedException` (409) avec le message de rotation vers une clé neuve ; l'alerte `critical` est
  émise (logger espion) et **le contexte du journal ne contient aucune clé, seulement des identifiants** ;
  témoin positif : ne lève rien pour `A,B` active B / C.
- **Validation :** `./infra/test-stack.sh run <jeton> app/tests/Platform/Unit/SealKeyringTest.php` (les tests existants restent verts) + garde-fous.
- **Taille :** ≈ 70 / ≈ 90.

### Étape 3 — Vérification vente (G-1) et archive (G-4)
- **Objectif :** `verifieSegment` alimente un tracker et ajoute l'anomalie. G-4 se vérifie par le test d'archive.
- **G-N :** G-1, G-4, G-7.
- **Fichiers :** `app/src/Vente/Nf525/HashChainSignataire.php` (+ ~12 lignes dans la boucle, `:90-132`) ; le
  docblock de `SignataireOperation::verifieChaine` (`:24-30`) cite la nouvelle anomalie.
- **Tests d'abord :** étendre `app/tests/Vente/Unit/SealKeyRotationTest.php` : chaîne construite avec trois
  trousseaux successifs (`SealKeyring` A, B, A, comme les tests existants) : `A,A,B` + maillon forgé
  correctement chaîné et signé par A → `intacte: false`, anomalie « régression de clé » à la séquence 4 ; forge
  `NULL` après B (signée sans id) → régression ; **témoin positif** `A,A,B,B` intacte ; G-7 : chaîne tout NULL,
  `NULL,A`, `A,B,C` intactes. Pour G-4 : dans `app/tests/Vente/Api/FiscalArchiveTest.php`, une archive dont le CSV
  `sealed_operations.csv` porte `A,B,A` (chaîne reconstruite avec trois trousseaux, manifeste et signature du
  ZIP refaits comme dans `testUnMaillonFalsifieEstDetecteParLeRecalculDeLaChaine`, `:108-139`) → chaîne
  `false`, archive non conforme (409) ; l'archive normale reste conforme (tests existants).
- **Validation :** `./infra/test-stack.sh run <jeton> app/tests/Vente/Unit app/tests/Vente/Api/FiscalArchiveTest.php` + garde-fous.
- **Taille :** ≈ 20 / ≈ 150.

### Étape 4 — Vérification compta (G-2)
- **Fichiers :** `app/src/Compta/Nf525/ScellementEcritureHandler.php` (`verifieChaine`, +~12 lignes).
- **G-N :** G-2, G-7.
- **Tests d'abord :** `app/tests/Compta/Api/SealKeyOrderTest.php` (nouveau, patron de
  `Compta/Api/SealKeyRotationTest.php`) : écritures en mémoire chaînées par trois handlers à trousseaux A, B, A
  (ou `A,A,B` + forge A) → `intacte: false`, anomalie à la séquence 4 ; NULL après B ; **témoin positif**
  `A,A,B,B` ; G-7.
- **Validation :** `./infra/test-stack.sh run <jeton> app/tests/Compta/Api/SealKeyOrderTest.php app/tests/Compta/Api/SealKeyRotationTest.php`.
- **Taille :** ≈ 15 / ≈ 90.

### Étape 5 — Vérification facturation (G-3)
- Identique à l'étape 4 pour `ScellementFactureHandler::verifieChaine` (`:110-201`, le bloc s'insère avant
  `$empreintePrecedente = …`, `:196`, hors de la chaîne `elseif` des clés pour ne pas la perturber).
- **G-N :** G-3, G-7. **Tests :** `app/tests/Facturation/SealKeyOrderTest.php` (patron `Facturation/SealKeyRotationTest.php`).
- **Validation :** `./infra/test-stack.sh run <jeton> app/tests/Facturation/SealKeyOrderTest.php app/tests/Facturation/SealKeyRotationTest.php`.
- **Taille :** ≈ 15 / ≈ 90.

### Étape 6 — Remontée hors connexion : le refus d'ancrage (conséquence de G-1)
- **Objectif :** prouver, sans changer de code (`SynchroOperationsProcessor.php:116-119` consomme déjà
  `verifieChaine`), qu'une chaîne vente en régression fait refuser l'ancrage (409).
- **G-N :** G-1.
- **Fichiers :** test seul, `app/tests/Vente/Api/OfflineSalesReplayTest.php` (nouveau cas) ; le cas est écrit en
  falsifiant en base (SQL direct, comme `Facturation/SealKeyRotationTest.php:84`) la clé de deux maillons *puis* en
  rescellant : si reconstruire une chaîne signée est trop lourd en API, le test n'exerce que la méthode
  `verifieChaine` via `ScellementHandler::chaine` (même chemin que `SynchroOperationsProcessor`).
- **Témoin positif :** la remontée d'une chaîne saine passe (tests existants du fichier).
- **Validation :** `./infra/test-stack.sh run <jeton> app/tests/Vente/Api/OfflineSalesReplayTest.php`.
- **Taille :** 0 / ≈ 60.

### Étape 7 — Index (phase base de données isolée)
- **Objectif :** trois index (D-2). Aucune autre modification.
- **G-N :** G-5, G-6 (condition de coût).
- **Avant d'écrire :** faire confirmer par Maxime le point D-2 ; demander le SQL à Doctrine
  (`doctrine:schema:update --dump-sql` dans la pile de test) après avoir ajouté les attributs `#[ORM\Index]` aux
  trois entités ; ne garder **que** les trois `CREATE INDEX` dans la migration (jamais le diff brut).
- **Fichiers :** `app/src/Vente/Nf525/Entity/OperationScellee.php` (+1 attribut), `app/src/Compta/Entity/EcritureComptable.php`
  (+1), `app/src/Facturation/Entity/Facture.php` (+1) ; `app/migrations/Version<horodatage UTC après la dernière>.php`
  (écrite à la main, `down()` = `DROP INDEX`, docblock du pourquoi comme `Version20261108190000`).
- **Tests d'abord :** `app/tests/Platform/Nf525/SealKeyIndexTest.php` : pour chaque table, `EXPLAIN SELECT
  MIN(numero_sequence), MAX(numero_sequence) … WHERE <chaîne> AND seal_key_id = ?` (et `IS NULL`) cite l'index
  `idx_*_key_seq` (**témoin positif** de la mesure D-3) et n'examine pas plus de quelques lignes ; vérification
  d'absence de dérive : `doctrine:schema:validate`/`schema:update --dump-sql` vide pour ces tables.
- **Validation :** `./infra/test-stack.sh run <jeton> app/tests/Platform/Nf525/SealKeyIndexTest.php` + migration
  jouée sur la pile de test (`up`/`down`/`up`) + garde-fous (dont ceux sur les migrations).
- **Taille :** ≈ 45 / ≈ 70.

### Étape 8 — Requête des intervalles par chaîne
- **Objectif :** `SealKeyRunQuery` (D-3) : trois méthodes statiques `forSales(Connection, SealKeyring, string
  $pointDeVenteId)`, `forAccounting(Connection, SealKeyring, string $profilId, string $journalId)`,
  `forInvoicing(Connection, SealKeyring, string $profilId)` → `list<KeyRun>`. Une requête par appel, `UNION ALL`,
  paramètres liés ; compta et facturation filtrent `numero_sequence > 0` comme `dernierMaillon`.
- **G-N :** G-5.
- **Fichiers :** `app/src/Platform/Nf525/SealKeyRunQuery.php` (nouveau).
- **Tests d'abord :** `app/tests/Platform/Nf525/SealKeyRunQueryTest.php` : lignes insérées en SQL direct (patron
  `executeStatement` des tests existants) ; les intervalles de `A,A,B,NULL…` sont ceux attendus ; une autre chaîne
  (autre point de vente / autre journal / autre exploitant) **n'apparaît pas** (cloisonnement, invariant serveur) ;
  chaîne vide → liste vide.
- **Validation :** `./infra/test-stack.sh run <jeton> app/tests/Platform/Nf525/SealKeyRunQueryTest.php`.
- **Taille :** ≈ 90 / ≈ 110.

### Étape 9 — Refus de sceller, compta et facturation (G-5)
- **Objectif :** appeler `assertCanExtendInOrder` dans `ScellementEcritureHandler::sceller` (après le bloc `:42-49`)
  et `ScellementFactureHandler::sceller` (après `:54-61`).
- **G-N :** G-5, G-7.
- **Fichiers :** les deux handlers (+~8 lignes chacun).
- **Tests d'abord (base réelle, étendre `Compta/Api/SealKeyOrderTest.php` et `Facturation/SealKeyOrderTest.php`) :**
  trousseau actif A (B retirée), chaîne `A,B` persistée → `sceller` lève `SealingRefusedException` (409) et rien n'est
  scellé (`numeroSequence` reste 0) ; logger espion : une alerte `critical`, aucune clé dans le contexte ;
  chaîne `A,B` + maillon forgé `A` en fin, active `C` → refusé ; **témoins positifs** : `A,B` active B → passe,
  active C → passe ; G-7 : chaque chaîne légitime se prolonge (écriture/facture scellée avec succès) ; cloisonnement :
  la chaîne en régression d'un exploitant (ou d'un journal) ne bloque pas l'autre.
- **Validation :** `./infra/test-stack.sh run <jeton> app/tests/Compta app/tests/Facturation` (dossiers entiers :
  non-régression de tous les scellements).
- **Taille :** ≈ 18 / ≈ 160.

### Étape 10 — Refus de sceller, vente (G-5)
- **Objectif :** `ScellementHandler::sceller` appelle `SealKeyRunQuery::forSales` puis `assertCanExtendInOrder`
  avant `signataire->scelle` ; trousseau injecté (`#[Autowire(service: 'app.nf525.keyring.sales')]`). Le libellé
  de chaîne reprend celui de `HashChainSignataire.php:44`.
- **G-N :** G-5, G-7.
- **Fichiers :** `app/src/Vente/Nf525/ScellementHandler.php` (+~14 lignes).
- **Tests d'abord :** `app/tests/Vente/Api/SealKeyOrderRefusalTest.php` (patron `CloisonnementChaineScelleeTest.php:221`
  pour obtenir le service) : mêmes cas que l'étape 9, sur une chaîne par point de vente, et un second point de vente
  sain non bloqué ; test de non-régression d'une vente complète (API) avec une chaîne `NULL…NULL` puis `A` (legacy)
  qui continue de se prolonger.
- **Validation :** `./infra/test-stack.sh run <jeton> app/tests/Vente` (dossier entier : toutes les ventes scellent)
  + garde-fous.
- **Taille :** ≈ 18 / ≈ 130.

### Étape 11 — `app:nf525:check-keys` sur toute la chaîne (G-6)
- **Objectif :** après le contrôle du dernier maillon existant, une seconde requête **par table**
  (`SELECT <chaîne>, seal_key_id, MIN(numero_sequence), MAX(numero_sequence) … GROUP BY <chaîne>, seal_key_id`,
  index de l'étape 7 en couverture) donne les `KeyRun` de **chaque** chaîne ; `SealKeyOrder::hasRegression`
  décide. Sortie : une ligne `✗` par chaîne en régression (référence de chaîne, identifiants des clés
  concernées, jamais les clés) ; le compteur `blocked` les inclut ; `Command::FAILURE`. Le message d'erreur final
  (`:101-106`) est réécrit pour couvrir les deux causes et renvoyer à la règle « clé neuve » du README. Lecture
  seule conservée.
- **G-N :** G-6, G-7.
- **Fichiers :** `app/src/Platform/Command/CheckSealKeysCommand.php` (≈ +50 lignes).
- **Tests d'abord :** `app/tests/Platform/Command/CheckSealKeysOrderTest.php` (patron `CommandTester` de
  `Facturation/SealKeyRotationTest.php:78`) sur chaque des trois tables : `A,B,A` dont le **dernier maillon est
  valide** (signature et clé connues) → code d'échec ; **témoin positif** `NULL,A,B` → succès ; chaîne saine
  → succès ; la sortie ne contient aucune valeur de clé.
- **Validation :** `./infra/test-stack.sh run <jeton> app/tests/Platform app/tests/Facturation/SealKeyRotationTest.php`.
- **Taille :** ≈ 55 / ≈ 110.

### Étape 12 — README et procédure (G-8)
- **Objectif :** dans `infra/README.md`, section « Trousseau NF525 » (`:265-316`), (a) la règle (une clé
  quittée ne revient jamais, rotation = clé neuve, l'ancienne active ajoutée à `*_RETIRED_KEYS`) ; (b) ce que fait
  l'application si elle est violée : vérification « régression de clé », refus 409 avec alerte critique,
  échec de `check-keys` ; (c) la procédure de rotation complète de la section 2 ci-dessus (arrêter scheduler et
  worker, recréer php/worker/scheduler **ensemble**, avec le profil `ordonnanceur`) ; (d) la phrase `:285-290`
  (« vérifie le dernier maillon ») mise à jour ; (e) à lire d'abord : la liste blanche d'`infra/ordonnanceur.sh`
  pour dire exactement quelles tâches du scheduler scellent. Entrée *append-only* dans `COORDINATION/DECISIONS.md`
  (arbitrages 1 à 4, D-2 inclus).
- **G-N :** G-8.
- **Fichiers :** `infra/README.md`, `COORDINATION/DECISIONS.md`.
- **Tests :** relecture par `relecteur` (critère G-8) ; `./bin/garde-fous.sh` (garde-fous de documentation/nommage).
- **Taille :** doc seule (≈ 40 lignes).

### Étape 13 — Clôture
- Suite complète : `./infra/test-stack.sh run <jeton>` (la CI ne la lance pas) ; `./bin/garde-fous.sh` ;
  `/garde-fous-sdd:revue-sensible` consignée **avant** le commit de l'étape 9 (première étape qui change le
  comportement d'écriture) puis rappelée avant la PR ; revues de la section 6 ; `/verifier-specs` ; Issue séparée
  pour la signature du ZIP d'archive (hors périmètre, à ouvrir) ; `/point-projet`.
- PR en **brouillon** pendant le travail, « prête » seulement CI verte et revues faites.

## 4. Tests — vue d'ensemble

| Niveau | Fichier | Étape |
|---|---|---|
| Unitaire règle (+ exhaustif tracker ≡ intervalles) | `Platform/Unit/SealKeyOrderTest.php` | 1 |
| Unitaire trousseau (libellés, alerte sans clé) | `Platform/Unit/SealKeyringTest.php` (étendu) | 2 |
| Vérification vente / archive | `Vente/Unit/SealKeyRotationTest.php`, `Vente/Api/FiscalArchiveTest.php` | 3 |
| Vérification compta / facturation | `Compta/Api/SealKeyOrderTest.php`, `Facturation/SealKeyOrderTest.php` | 4, 5 |
| Ancrage hors connexion | `Vente/Api/OfflineSalesReplayTest.php` | 6 |
| Index utilisés (EXPLAIN) | `Platform/Nf525/SealKeyIndexTest.php` | 7 |
| Requête des intervalles, cloisonnée | `Platform/Nf525/SealKeyRunQueryTest.php` | 8 |
| Refus de sceller | mêmes fichiers compta/facturation ; `Vente/Api/SealKeyOrderRefusalTest.php` | 9, 10 |
| Déploiement | `Platform/Command/CheckSealKeysOrderTest.php` | 11 |

Les tests existants `SealKeyringTest`, `SealKeyRotationTest` (4 fichiers), `HashChainTest`, `FiscalArchiveTest`,
`CloisonnementChaineScelleeTest` restent verts sans modification de leurs assertions (D-4).

## 5. Couverture Goal → Étapes

| Goal | Étapes |
|---|---|
| G-1 vérification vente | 1, 2, 3, 6 |
| G-2 vérification compta | 1, 2, 4 |
| G-3 vérification facturation | 1, 2, 5 |
| G-4 archive fiscale | 1, 3 (test d'archive) |
| G-5 refus de sceller (3 chaînes) | 1, 2, 7, 8, 9 (compta, facturation), 10 (vente) |
| G-6 `check-keys` | 1, 7, 11 |
| G-7 chaînes légitimes | 1, 3, 4, 5, 9, 10, 11 |
| G-8 README | 12 |

Aucune étape ne couvre un objectif absent de la spec (les étapes 7, 8 sont des conditions techniques de G-5/G-6 ;
l'étape 13 est la clôture de process).

## 6. Risques et stratégie de revue

- **R-1 — Faux positif bloquant (échec fermé).** Une chaîne légitime refusée arrête les ventes d'un point de vente.
  Parades : tests G-7 à chaque étape ; D-5 vérifié sur les cas légitimes ; test exhaustif tracker ≡ intervalles ;
  les chaînes de préprod (maillons d'avant le 08/11, `NULL` possibles) : **mesurer avant déploiement**
  `SELECT seal_key_id, MIN(numero_sequence), MAX(numero_sequence) … GROUP BY seal_key_id` (UNVERIFIED de la
  spec ; `check-keys` du déploiement le dira de toute façon, à lancer en lecture seule sur la préprod avant la
  fusion).
- **R-2 — Coût sur le chemin de chaque vente.** Deux à `(1 + nb de clés retirées + 1)` recherches d'index par
  scellement, dans la transaction. Mesuré par `EXPLAIN` (étape 7), pas supposé ; la migration est une condition
  (D-2). Si Maxime refuse l'index : arrêt et réarbitrage (pas de repli silencieux).
- **R-3 — Rotation avec trousseaux différents entre conteneurs** (`A,B,A` faux positif) : le scheduler est hors
  de `up -d` (profil) ; la parade est documentaire (étape 12), pas technique. On le dit au lecteur du README ;
  un garde technique (script de rotation) est hors périmètre.
- **R-4 — Scellements dans une même transaction avant flush.** `dernierMaillon` lit la base par DQL : les
  maillons en attente de flush n'y sont pas, et notre requête non plus. Même hypothèse que l'existant (chaque
  scellement flush avant le suivant) ; à confirmer par la suite complète des dossiers Compta/Facturation/Vente
  (étapes 9 et 10), sinon signaler.
- **R-5 — Limite D-3** : identifiant inconnu au milieu de la chaîne, vu par vérification et `check-keys`, pas par le
  refus de sceller. Documentée dans le docblock et le README.
- **R-6 — Fuite d'un secret.** Journaux, messages d'anomalie, sortie de `check-keys` : identifiants seulement ;
  testé (étapes 2, 11). Le dépôt est public : aucune clé dans les tests (les clés de test existantes sont
  des chaînes de test, comme dans `SealKeyringTest`).
- **R-7 — Hors périmètre rappelé :** signature du ZIP d'archive (Issue à ouvrir), alerte persistée (#370), liaison d'un
  segment avec le maillon qui le précède.

**Revue (sujet NF525, zone sensible : base, argent, cloisonnement).** Mode adversarial :
`relecteur` après chaque étape de code (1 à 5, 8 à 11) ; `security-reviewer` sur les étapes 2, 8, 9, 10 et 11
(clés dans les journaux, injection SQL dans `SealKeyRunQuery`, cloisonnement des requêtes, contournement
du refus par un maillon forgé), avec la consigne d'essayer de produire un maillon forgé qui passe le tracker
et le refus. `/garde-fous-sdd:revue-sensible` consignée avant tout commit en zone sensible (étapes 7 à 11).

## 7. Fiches à produire

**Aucune.** Le plan n'utilise que PHP natif, Doctrine DBAL `Connection` déjà employée telle quelle
(`executeStatement`, `fetchAllAssociative` dans `CheckSealKeysCommand.php:71` et les tests existants) et le code
maison ; `CommandTester` est déjà utilisé (`Facturation/SealKeyRotationTest.php:78`). Aucune API tierce nouvelle :
la voie « recopier l'usage déjà présent » s'applique (CLAUDE.md). Seule vérification de comportement à mesurer et
non à documenter : l'`EXPLAIN` MariaDB de l'étape 7.
