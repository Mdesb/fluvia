# MariaDB 11.4 — fiche de référence

- Version du projet : 11.4 (image `mariadb:11.4`, infra/compose.preprod.yaml ligne 247 ; hors verrou, correctif exact non figé)
- Vérifiée le : 2026-10-07, complétée le 2026-11-18 (mesures sur le conteneur `cloud1-db`, `SELECT VERSION()` = `11.4.13-MariaDB-ubu2404`) · Revoir avant le : 2027-01-05
- Sources :
  - [officielle] https://mariadb.com/docs/server/mariadb-quickstart-guides/mariadb-indexes-guide (redirigée depuis mariadb.com/kb/en/getting-started-with-indexes/, lu le 2026-10-07)
  - [officielle] https://mariadb.com/docs/server/server-usage/storage-engines/innodb/innodb-system-variables (lu le 2026-10-07)
  - [officielle] https://mariadb.com/docs/server/server-usage/storage-engines/innodb/innodb-lock-modes (lu le 2026-10-07)
  - [officielle] https://mariadb.com/docs/server/server-management/install-and-upgrade-mariadb/migrating-to-mariadb/migrating-to-mariadb-from-sql-server/mariadb-transactions-and-isolation-levels-for-sql-server-users (lu le 2026-10-07)
  - [officielle] https://mariadb.com/docs/server/reference/sql-statements/data-definition/create/generated-columns (lu le 2026-11-18)
  - [officielle] https://mariadb.com/docs/server/reference/sql-statements/data-manipulation/inserting-loading-data/insert-ignore (lu le 2026-11-18)
  - [officielle] https://mariadb.com/docs/server/server-usage/storage-engines/innodb/innodb-lock-modes (relu le 2026-11-18)
  - [mesure] base jetable `app_testfiche` créée puis supprimée sur `cloud1-db` (image `mariadb:11.4`, 11.4.13), deux sessions `mariadb` concurrentes, le 2026-11-18. Variables du conteneur : `innodb_lock_wait_timeout` = 50, `innodb_rollback_on_timeout` = 0, `transaction_isolation` = REPEATABLE-READ, `sql_mode` = STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION.
- Questions couvertes : UNIQUE sur colonne nullable ; deux INSERT concurrents sur la même clé unique ; défaut de `innodb_lock_wait_timeout` ; (2026-11-18) clé unique sur colonne générée VIRTUAL/PERSISTENT en concurrence, INSERT enfant contre parent verrouillé, `SET SESSION innodb_lock_wait_timeout`, `INSERT IGNORE` sur clé primaire composée.
- Limite : ces pages sont la documentation courante du serveur, pas une page propre à 11.4 ; aucune n'indique de différence pour 11.4.

## À utiliser

### Index UNIQUE sur colonne nullable
- Une contrainte `UNIQUE` admet PLUSIEURS valeurs `NULL` (en SQL, NULL n'est jamais égal à NULL) ; la page montre deux lignes avec NULL acceptées. [officielle] mariadb-indexes-guide
- La page ne lie pas ce comportement à un moteur ni à une version précise.

### Verrous lors du contrôle de doublon
- Le contrôle de doublon sur un index unique pose un verrou « next-key » (intervalle + enregistrement) À TOUS les niveaux d'isolation — c'est l'exception à la règle. [officielle] innodb-lock-modes
- Les verrous d'intervalle (gap locks) sont désactivés en READ COMMITTED / READ UNCOMMITTED (sauf ce contrôle de doublon). [officielle] innodb-lock-modes
- Niveau d'isolation par défaut : `REPEATABLE READ` (variable `transaction_isolation`). [officielle] page « transactions and isolation levels »

### `innodb_lock_wait_timeout`
- Défaut : **50** secondes ; portée globale ET session ; dynamique ; plage 0 à 100 000 000 (0 = pas d'attente). [officielle] innodb-system-variables
- Au dépassement : erreur **1205** (« Lock wait timeout exceeded ») ; seule l'INSTRUCTION est annulée, pas la transaction — sauf si `innodb_rollback_on_timeout` est activé (défaut : OFF). [officielle] innodb-system-variables
- Ne s'applique pas aux interblocages : détectés immédiatement (`innodb_deadlock_detect` activé par défaut), la transaction choisie est annulée sans attendre. [officielle] innodb-system-variables
- Côté DBAL : 1205 → `LockWaitTimeoutException`, 1213 → `DeadlockException`, 1062 → `UniqueConstraintViolationException` (voir `doctrine-dbal-4.4.md`).

### Clé UNIQUE sur colonne générée (VIRTUAL ou PERSISTENT) — ajout du 2026-11-18
- « Defining indexes on both VIRTUAL and PERSISTENT generated columns is supported. » ; VIRTUAL est le défaut, sa valeur n'est pas stockée ; PERSISTENT (alias STORED) est stockée. InnoDB prend en charge les colonnes générées. [officielle] generated-columns
- Limites documentées : pas de colonne générée en clé primaire ; pas de fonction non déterministe dans l'expression d'une colonne PERSISTENT ou VIRTUAL indexée ; un `ALTER TABLE` d'une table ayant une colonne VIRTUAL indexée exige `ALGORITHM=COPY` ; avant 11.8 l'optimiseur ne reconnaît pas l'expression dans un `WHERE` (il faut nommer la colonne). [officielle] generated-columns
- **Mesuré le 2026-11-18 sur mariadb:11.4 (11.4.13)**, `UNIQUE` sur `k VARCHAR(40) AS (CONCAT(a,'-',b)) VIRTUAL`, isolation REPEATABLE-READ :
  - T1 `BEGIN; INSERT (1,1)` non validé ; T2 `INSERT (1,1)` **attend** ; T1 `COMMIT` → T2 échoue en **1062** (`Duplicate entry '1-1' for key 'uk_v'`) dès le commit (~3 s d'attente observées, durée du sommeil de T1).
  - Même scénario, T1 `ROLLBACK` → T2 **réussit** (attente ~3 s puis `1 row affected`).
  - T2 avec `innodb_lock_wait_timeout = 2` et T1 qui tient 5 s → T2 échoue en **1205** après ~2,1 s.
  - Colonne **PERSISTENT** au lieu de VIRTUAL : comportement identique (attente puis 1062).
  - Si l'expression vaut NULL (`CONCAT` avec un opérande NULL), la clé générée vaut NULL et **deux lignes « en double » sont acceptées** (NULL n'est pas comparé sous UNIQUE). [mesure]
- La doc ne décrit pas ce scénario de concurrence : la mesure ci-dessus en tient lieu. Elle n'a porté que sur des colonnes générées (VIRTUAL et PERSISTENT) ; une colonne ordinaire n'a pas été mesurée ce jour-là.

### INSERT d'une ligne enfant dont la FK vise une ligne verrouillée — ajout du 2026-11-18
- Doc : un verrou S n'est pas accordé si un verrou X est tenu ; il l'est si un S est tenu (« An S lock is not granted if an X or IX lock is held. It is granted if an S or IS lock is held. »). [officielle] innodb-lock-modes. La doc lue **ne dit pas** quel verrou le contrôle de clé étrangère pose sur la ligne parente : non trouvé dans la doc officielle.
- **Mesuré le 2026-11-18 sur mariadb:11.4 (11.4.13)**, `chi.par_id` → `par.id` (InnoDB) :
  - T1 `SELECT … FROM par WHERE id=1 FOR UPDATE` (non validé) ; T2 (`innodb_lock_wait_timeout = 2`) `INSERT INTO chi(par_id) VALUES (1)` → **attend puis 1205** après ~2,1 s.
  - Même verrou, T1 valide après ~3 s, T2 au délai par défaut → T2 **réussit** après l'attente.
  - T1 `… LOCK IN SHARE MODE` (verrou partagé) → T2 insère **sans attendre** (~0,1 s). Comportement compatible avec un verrou PARTAGÉ posé par le contrôle de FK sur la ligne parente (déduction de la mesure).
  - T1 `UPDATE par SET label=… WHERE id=4` (colonne hors clé) → T2 insérant un enfant de 4 **attend puis 1205** : un simple UPDATE du parent bloque aussi.
  - T1 verrouille `par.id = 1`, T2 insère un enfant de `par.id = 2` → **aucune attente** (le verrou porte sur la ligne référencée, pas sur la table). [mesure]

### `SET SESSION innodb_lock_wait_timeout` — ajout du 2026-11-18
- Doc : portée globale et session, dynamique, plage 0 à 100 000 000 (secondes), défaut 50 (voir plus haut). [officielle] innodb-system-variables
- **Mesuré le 2026-11-18 sur mariadb:11.4 (11.4.13)** :
  - `SET SESSION … = 0` accepté : l'instruction en attente échoue **immédiatement** en 1205 (~0,1 s). Minimum effectif = 0 ; une valeur négative (`-5`) est **tronquée à 0** avec l'avertissement 1292 (pas d'erreur). Une valeur décimale (`1.5`) est refusée : erreur 1232.
  - La valeur de session ne touche pas les autres sessions (une nouvelle session lit 50) ; `SET GLOBAL` est refusé à l'utilisateur `app` (1227, privilège SUPER) — seul `SET SESSION` est utilisable par l'application.
  - Elle peut être changée **au milieu d'une transaction ouverte** et vaut pour les instructions suivantes.
  - Variante par instruction : `SET STATEMENT innodb_lock_wait_timeout=1 FOR INSERT …` → 1205 après ~1,1 s, sans changer la session. [mesure]
- Côté DBAL, la variable étant de session, elle doit être posée sur la connexion qui exécute l'instruction (une seconde `Connection` a sa propre session : voir `doctrine-dbal-4.4.md`).

### `INSERT IGNORE` sur clé primaire composée — ajout du 2026-11-18
- Doc : « all errors are converted to warnings, which will not stop inserts of additional rows » ; « Invalid values are changed to the closest valid value and inserted, with a warning produced. » ; les doublons de clé produisent des avertissements (sauf `OLD_MODE=NO_DUP_KEY_WARNINGS_WITH_IGNORE`). La page ne parle pas du mode strict. [officielle] insert-ignore
- **Mesuré le 2026-11-18 sur mariadb:11.4 (11.4.13)**, `PRIMARY KEY (a CHAR(36), b VARCHAR(20))`, `sql_mode` strict du conteneur :
  - Doublon sur (a,b) : ligne ignorée, avertissement **1062**, `Records: 2 Duplicates: 1` ; les autres lignes du même INSERT multi-lignes sont insérées. La ligne existante n'est PAS modifiée.
  - Doublon en attente d'un autre `INSERT` non validé : `INSERT IGNORE` **attend** comme un INSERT ordinaire, puis 0 ligne + avertissement 1062 au commit de l'autre.
  - **Conversions silencieuses malgré le mode strict** (là où un INSERT ordinaire échoue — vérifié : 1406 sans IGNORE) : chaîne trop longue **tronquée** (1265) ; `'abc'` dans un INT → **0** (1366) ; NULL dans un NOT NULL → **''** (1048).
  - **Violation de FK** : ligne ignorée, avertissement **1452**, `0 rows affected` — l'erreur de FK disparaît aussi.
  - La comparaison suit la collation : `('u6','K1')` et `('u6','k1 ')` sont des doublons sous `utf8mb4_uca1400_ai_ci` (insensible à la casse, espaces finaux ignorés). [mesure]
- Côté DBAL : `executeStatement()` renvoie le nombre de lignes réellement insérées ; les avertissements ne lèvent aucune exception (seul `SHOW WARNINGS` les montre).

## Pièges connus
- `INSERT IGNORE` avale TOUTES les erreurs, pas seulement le doublon : troncature, conversion de type, NULL forcé, violation de FK deviennent des avertissements invisibles côté DBAL. Ne l'employer que sur des valeurs déjà validées, et tester le nombre de lignes renvoyé. [officielle] insert-ignore + [mesure]
- Une clé UNIQUE générée dont l'expression peut valoir NULL ne dédoublonne pas ces lignes. [mesure]
- `innodb_lock_wait_timeout = 0` n'est pas « pas de délai » au sens d'attente infinie : c'est l'échec immédiat. [mesure]
- Après une erreur 1205, la transaction reste OUVERTE avec ses écritures précédentes (défaut OFF de `innodb_rollback_on_timeout`) : c'est à l'application d'annuler. [officielle] innodb-system-variables
- Une colonne nullable sous UNIQUE n'empêche pas plusieurs lignes « sans valeur » : pour interdire les doublons de NULL, il faut une autre conception (non traité ici).

## À VÉRIFIER
- ~~Deux INSERT concurrents sur la même clé unique~~ : **tranché par mesure le 2026-11-18 pour une clé UNIQUE sur colonne générée** (voir « Clé UNIQUE sur colonne générée ») — attente, puis 1062 au commit, succès au rollback, 1205 au délai ; et pour une clé primaire composée sous `INSERT IGNORE` (attente puis avertissement 1062). Toujours aucune page MariaDB qui le décrive.
- Clause `where` (index partiel) proposée par l'ORM : support par MariaDB non vérifié.

## À confirmer par le plan
- Le correctif exact de l'image (`11.4.x`) n'est pas figé par l'étiquette `mariadb:11.4` : mesures du 2026-11-18 faites sur 11.4.13 (pile de test) ; la préproduction peut tourner sur un autre correctif.
- Valeur effective de `innodb_lock_wait_timeout` / `innodb_rollback_on_timeout` : mesurée sur la pile de test (50 / OFF, 2026-11-18) ; en préproduction, non mesurée.

## Écarts entre sources
- innodb-system-variables : le délai vaut pour un verrou d'enregistrement « (ou de table) » InnoDB. La page « transactions and isolation levels » dit qu'il vaut pour les verrous de ligne, ni de métadonnées ni de table. La page de référence des variables l'emporte ; l'écart ne change rien pour des verrous de ligne.
