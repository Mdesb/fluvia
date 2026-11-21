# Doctrine DBAL 4.4 — fiche de référence

- Version du projet : 4.4.4 (source : app/composer.lock, ligne 1609)
- Vérifiée le : 2026-10-07, complétée le 2026-11-18 (seconde connexion) · Revoir avant le : 2027-01-05
- Sources :
  - [officielle] https://www.doctrine-project.org/projects/doctrine-dbal/en/4.4/reference/transactions.html (lu le 2026-10-07)
  - [officielle] https://raw.githubusercontent.com/doctrine/dbal/4.4.4/UPGRADE.md (tag 4.4.4, lu le 2026-10-07)
  - [officielle] code source au tag 4.4.4 : `src/Connection.php`, `src/LockMode.php`, `src/Exception/{UniqueConstraintViolationException,ConstraintViolationException,RetryableException,DeadlockException,LockWaitTimeoutException}.php`, `src/Driver/API/MySQL/ExceptionConverter.php` (https://raw.githubusercontent.com/doctrine/dbal/4.4.4/…, lus le 2026-10-07)
  - [officielle] https://raw.githubusercontent.com/doctrine/dbal/4.4.4/src/DriverManager.php et `src/Connection.php` (tag 4.4.4, lus le 2026-11-18)
  - [officielle] https://www.doctrine-project.org/projects/doctrine-dbal/en/4.4/reference/configuration.html (lu le 2026-11-18)
  - [officielle] https://raw.githubusercontent.com/doctrine/DoctrineBundle/3.3.1/src/ConnectionFactory.php (doctrine/doctrine-bundle 3.3.1, app/composer.lock ; lu le 2026-11-18)
  - [projet] app/config/packages/doctrine.yaml (`when@test` : `dbname_suffix: '_test%env(default::TEST_TOKEN)%'`), lu le 2026-11-18
- Questions couvertes : `transactional()` imbriquée (points de sauvegarde ou compteur) ; sort de la transaction externe quand l'interne lève ; `executeStatement()` dans `transactional()` ; `UniqueConstraintViolationException`, `RetryableException` ; seconde `Connection` indépendante (2026-11-18).

## À utiliser

### Transactions imbriquées : TOUJOURS des points de sauvegarde (SAVEPOINT)
- En 4.x l'imbrication est émulée par des points de sauvegarde SQL ; il n'existe qu'une seule vraie transaction en base. [officielle] transactions.html
- Code 4.4.4 : `beginTransaction()` incrémente le niveau ; niveau 1 → vraie transaction, niveau > 1 → `createSavepoint()`. `commit()` au niveau > 1 → libère le point de sauvegarde ; `rollBack()` au niveau > 1 → retour au point de sauvegarde seulement. [officielle] Connection.php@4.4.4
- Le compteur de niveau existe (`getTransactionNestingLevel()`, `isTransactionActive()` = niveau > 0), mais il pilote les points de sauvegarde, il ne les remplace pas.

### `Connection::transactional(Closure $func): mixed` (4.4.4)
- La closure reçoit la `Connection` elle-même ; la valeur renvoyée par la closure est renvoyée par `transactional()`. [officielle] Connection.php@4.4.4
- Exception dans la closure → `rollBack()` (du niveau courant : point de sauvegarde si imbriqué, vraie transaction sinon), puis l'exception est relancée telle quelle. Exception : `ConnectionLost` est relancée SANS tentative de rollback.
- Exception levée par le `commit()` lui-même → rollback, SAUF si c'est `TransactionRolledBack`, `UniqueConstraintViolationException`, `ForeignKeyConstraintViolationException`, `DeadlockException` ou `ConnectionLost` (pas de rollback supplémentaire) ; l'exception est relancée dans tous les cas.

### Transaction externe quand l'interne lève
- L'interne annule seulement SON point de sauvegarde, puis relance l'exception.
  - Si l'appelant externe CAPTURE l'exception : la transaction externe continue et peut être validée (les écritures internes sont annulées, les externes gardées). [officielle] transactions.html (exemple imbriqué)
  - Si l'exception remonte jusqu'au `transactional()` externe : celui-ci fait le vrai `rollBack()` et relance.
- PAS de marquage « rollback-only » automatique en 4.4 : `isRollbackOnly` n'est mis à `true` que par un appel explicite à `setRollbackOnly()` ; un `commit()` alors lève `CommitFailedRollbackOnly` ; le vrai `rollBack()` (niveau 1) remet le drapeau à `false`. `setRollbackOnly()`/`isRollbackOnly()` hors transaction → `NoActiveTransaction`. [officielle] Connection.php@4.4.4 (la page transactions.html n'en parle pas)

### `executeStatement()` dans une transaction ouverte par `transactional()`
- Signature 4.4.4 : `executeStatement(string $sql, array $params = [], array $types = []): int|string` — renvoie le nombre de lignes affectées (`int` ou chaîne numérique). [officielle] Connection.php@4.4.4
- Appelé sur la `Connection` passée à la closure, il s'exécute sur la même connexion, donc dans l'unique transaction en cours (il n'existe qu'une vraie transaction). [officielle] transactions.html + Connection.php

### Exceptions (espace de noms `Doctrine\DBAL\Exception`)
- `UniqueConstraintViolationException extends ConstraintViolationException extends ServerException` ; n'implémente AUCUNE interface (donc pas `RetryableException`). [officielle] fichiers @4.4.4
- `RetryableException` : INTERFACE marqueur (étend `Throwable`) pour les erreurs où rejouer la transaction a du sens. Implémentée par `DeadlockException` et `LockWaitTimeoutException` (toutes deux `extends ServerException`). [officielle] fichiers @4.4.4 + transactions.html
- Conversion MySQL/MariaDB (pilote `MySQL\ExceptionConverter`) :
  - 1062, 1557, 1569, 1586 → `UniqueConstraintViolationException`
  - 1205 (lock wait timeout) → `LockWaitTimeoutException`
  - 1213 (deadlock) → `DeadlockException`
  - 1216, 1217, 1451, 1452, 1701 → `ForeignKeyConstraintViolationException`
  [officielle] ExceptionConverter.php@4.4.4
- `Doctrine\DBAL\LockMode` est un `enum` : `NONE`, `OPTIMISTIC`, `PESSIMISTIC_READ`, `PESSIMISTIC_WRITE`. [officielle] LockMode.php@4.4.4

### Seconde `Connection` indépendante (ajout du 2026-11-18)
- Signature 4.4.4 : `DriverManager::getConnection(#[SensitiveParameter] array $params, ?Configuration $config = null): Connection`. Corps : `$config ??= new Configuration()` ; pilote créé depuis `driver` ou `driverClass` puis enveloppé par chaque middleware de `$config->getMiddlewares()` ; `$wrapperClass = $params['wrapperClass'] ?? Connection::class` (doit hériter de `Connection`, sinon `InvalidWrapperClass`) ; `return new $wrapperClass($params, $driver, $config)`. [officielle] DriverManager.php@4.4.4
- **Aucune clé `url`** n'est lue par `DriverManager` en 4.x (ni dans le docblock ni dans le corps) : c'est DoctrineBundle qui analyse l'URL (`parseDatabaseUrl`) et retire la clé `url` avant d'appeler DBAL. Les paramètres d'une connexion déjà construite par le bundle ne contiennent donc plus `url`, mais `host`, `dbname`, `user`, etc. [officielle] DriverManager.php@4.4.4 + ConnectionFactory.php@DoctrineBundle 3.3.1
- `Connection::getParams(): array` renvoie `$this->params` tel que reçu par le constructeur ; il est marqué **`@internal`** (pas d'engagement de stabilité de DBAL). `getConfiguration(): Configuration` renvoie `$this->_config` (public, non interne). `getDriver(): Driver` est public. [officielle] Connection.php@4.4.4
- **Connexion paresseuse** : le constructeur ne fait que stocker configuration et paramètres ; la connexion physique est ouverte par `connect()` (protégée) au premier besoin, via `$this->driver->connect($this->params)`, et mémorisée dans `$_conn`. Chaque objet `Connection` a donc SA propre connexion physique, donc sa propre session serveur et sa propre transaction. [officielle] Connection.php@4.4.4 (la page configuration.html ne traite pas ce point)
- `close()` : `$_conn = null` et `transactionNestingLevel = 0` ; l'appel suivant qui a besoin de la base rouvre une connexion physique neuve (et rouvre une transaction si `autoCommit` est faux). [officielle] Connection.php@4.4.4
- Indépendance transactionnelle : une transaction ouverte sur la seconde `Connection` est validée ou annulée par SES `commit()`/`rollBack()` ; un `rollBack()` de la connexion principale ne la touche pas, puisque ce sont deux sessions MariaDB distinctes. Déduction du code ci-dessus (une transaction est portée par une session) ; pas de phrase de la doc DBAL qui le dise telle quelle → le prouver par le test d'intégration du plan (écrire par la seconde, annuler la principale, relire).
- Partage par `getConfiguration()` : passer la même `Configuration` réutilise ses middlewares (journalisation, profileur) et son `autoCommit` ; le pilote est reconstruit et ré-enveloppé à chaque `getConnection`. [officielle] DriverManager.php@4.4.4

### DoctrineBundle 3.3.1 et `dbname_suffix` (environnement de test)
- Config projet : `when@test: doctrine.dbal.dbname_suffix: '_test%env(default::TEST_TOKEN)%'` (app/config/packages/doctrine.yaml). Exemple observé le 2026-11-18 : base `app_testcloud1` pour le jeton `cloud1`. [projet]
- `ConnectionFactory::createConnection()` : si `dbname_suffix` est présent (ou `charset` absent), le bundle construit une première connexion par `DriverManager::getConnection($params, $config)`, puis `$params = $this->addDatabaseSuffix($connection->getParams())`, puis `new $wrapperClass($params, $driver, $config)`. `addDatabaseSuffix()` fait `$params['dbname'] .= $params['dbname_suffix']` et **ne retire pas** la clé `dbname_suffix`. [officielle] ConnectionFactory.php@3.3.1
- Conséquence : sur la connexion autowirée, `getParams()['dbname']` contient **déjà** le suffixe (ex. `app_testcloud1`) et `getParams()['dbname_suffix']` est encore là. `DriverManager::getConnection($main->getParams(), $main->getConfiguration())` vise donc la bonne base de test : DBAL ne relit pas `dbname_suffix` (le pilote `PDO\MySQL\Driver` 4.4.4 ne lit que `host`, `port`, `dbname`, `unix_socket`, `charset`, `user`, `password`, `driverOptions`, `persistent`). [officielle] ConnectionFactory.php@3.3.1 + code DBAL 4.4.4 `src/Driver/PDO/MySQL/Driver.php` (lu dans app/vendor, version du verrou)
- Le bundle ajoute aussi `charset = utf8mb4` et `defaultTableOptions.collation = utf8mb4_unicode_ci` quand `charset` est absent : ces valeurs sont dans `getParams()` et donc reprises par la seconde connexion. [officielle] ConnectionFactory.php@3.3.1
- Pas de `dama/doctrine-test-bundle` (connexion statique partagée) dans app/composer.lock au 2026-11-18 : rien ne fait partager une transaction de test entre les deux connexions. [projet]

## Obsolète ou retiré dans cette version — ne pas utiliser

| Ancien | Remplacé par | Depuis | Source |
|---|---|---|---|
| `setNestTransactionsWithSavepoints()` / `getNestTransactionsWithSavepoints()` | rien (savepoints toujours actifs) ; `false` lève `InvalidArgumentException` | déprécié en 4.x, retrait annoncé en 5.0 | [officielle] Connection.php@4.4.4 |
| `Connection::quoteIdentifier()` | `quoteSingleIdentifier()` sur chaque partie | 4.x (déprécié) | [officielle] Connection.php@4.4.4 |
| `Connection::query()`, `exec()`, `Result::fetch()` | remplaçants : À VÉRIFIER dans UPGRADE.md §4.0 (non relus) ; `executeStatement()` existe (ci-dessus) | retirés en 4.0 | [officielle] UPGRADE.md@4.4.4 |
| constantes `PARAM_*_ARRAY` | enum `ArrayParameterType` | 4.0 | [officielle] UPGRADE.md@4.4.4 |
| `getForUpdateSQL()`, `getReadLockSQL()`, `getWriteLockSQL()` | `QueryBuilder::forUpdate()` | 4.0 | [officielle] UPGRADE.md@4.4.4 |
| `AbstractSchemaManager::listDatabases()` (et autres `list*`) | `introspectDatabaseNames()` etc. | 4.4 | [officielle] UPGRADE.md@4.4.4 |
| expressions SQL chaîne comme défaut date/heure | `CurrentDate`, `CurrentTime`, `CurrentTimestamp` | 4.4 | [officielle] UPGRADE.md@4.4.4 |

## Pièges connus
- `getParams()` est `@internal` : son contenu peut changer sans dépréciation ; le figer par un test (la seconde connexion lit bien `SELECT DATABASE()` = base de la principale). [officielle] Connection.php@4.4.4
- Ne JAMAIS repasser `getParams()` par `ConnectionFactory::createConnection()` du bundle : `dbname_suffix` étant resté dans les paramètres, le suffixe serait ajouté deux fois (`app_testcloud1_testcloud1`). `DriverManager::getConnection()` direct n'a pas ce défaut. [officielle] ConnectionFactory.php@3.3.1 (déduction du code)
- `wrapperClass` : s'il figure dans `getParams()`, la seconde connexion est instanciée dans la même classe enveloppe. Le projet n'en déclare pas (doctrine.yaml, 2026-11-18). [officielle] DriverManager.php@4.4.4
- `persistent` : s'il était activé, le comportement de deux connexions persistantes PDO sur le même DSN dans un même processus n'est PAS vérifié ici (doc PHP, hors périmètre) ; le projet ne le déclare pas dans doctrine.yaml (l'URL n'a pas été lue : fichier de secrets).
- La seconde connexion n'est jamais fermée par le bundle : appeler `close()` après usage (sinon une session serveur de plus par requête PHP-FPM jusqu'à la fin du processus).
- Capturer l'exception d'un `transactional()` interne et continuer = la transaction externe reste valide : les écritures de l'interne sont perdues SANS erreur visible plus haut. [officielle] transactions.html
- Un doublon (`UniqueConstraintViolationException`) n'est PAS une `RetryableException` : `catch (RetryableException)` ne l'attrape pas. [officielle] fichiers @4.4.4
- `executeStatement()` peut renvoyer une chaîne numérique (`int|string`) : ne pas comparer avec `===` à un entier sans conversion. [officielle] Connection.php@4.4.4
- `ConnectionLost` dans la closure : aucun rollback tenté par DBAL. [officielle] Connection.php@4.4.4
- MariaDB : sur dépassement d'attente de verrou, seule l'instruction est annulée par InnoDB, sur interblocage toute la transaction (voir `mariadb-11.4.md`) ; l'effet d'un `rollBack()` vers un point de sauvegarde après un interblocage n'est pas documenté → voir « À confirmer ».

## À confirmer par le plan
- Comportement d'un `transactional()` imbriqué quand InnoDB a déjà annulé toute la transaction (interblocage 1213) : le retour au point de sauvegarde échoue-t-il ? Non documenté à la source → test d'intégration.
- Rejouer sur `RetryableException` : rejouer toute la transaction externe (niveau 1), jamais un seul niveau imbriqué (déduction, non écrite telle quelle dans la doc).

## Écarts entre sources
- UPGRADE.md (section 4.0) dit que `setNestTransactionsWithSavepoints()`/`getNestTransactionsWithSavepoints()` sont « supprimées » ; le code du tag 4.4.4 les contient encore, dépréciées (retrait en 5.0), n'acceptant que `true`. Le code de la version installée l'emporte : elles existent, sont inutiles, ne pas les appeler.
