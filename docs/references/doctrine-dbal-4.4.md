# Doctrine DBAL 4.4 — fiche de référence

- Version du projet : 4.4.4 (source : app/composer.lock, ligne 1609)
- Vérifiée le : 2026-10-07 · Revoir avant le : 2027-01-05
- Sources :
  - [officielle] https://www.doctrine-project.org/projects/doctrine-dbal/en/4.4/reference/transactions.html (lu le 2026-10-07)
  - [officielle] https://raw.githubusercontent.com/doctrine/dbal/4.4.4/UPGRADE.md (tag 4.4.4, lu le 2026-10-07)
  - [officielle] code source au tag 4.4.4 : `src/Connection.php`, `src/LockMode.php`, `src/Exception/{UniqueConstraintViolationException,ConstraintViolationException,RetryableException,DeadlockException,LockWaitTimeoutException}.php`, `src/Driver/API/MySQL/ExceptionConverter.php` (https://raw.githubusercontent.com/doctrine/dbal/4.4.4/…, lus le 2026-10-07)
- Questions couvertes : `transactional()` imbriquée (points de sauvegarde ou compteur) ; sort de la transaction externe quand l'interne lève ; `executeStatement()` dans `transactional()` ; `UniqueConstraintViolationException`, `RetryableException`.

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
