# MariaDB 11.4 — fiche de référence

- Version du projet : 11.4 (image `mariadb:11.4`, infra/compose.preprod.yaml ligne 247 ; hors verrou, correctif exact non figé)
- Vérifiée le : 2026-10-07 · Revoir avant le : 2027-01-05
- Sources :
  - [officielle] https://mariadb.com/docs/server/mariadb-quickstart-guides/mariadb-indexes-guide (redirigée depuis mariadb.com/kb/en/getting-started-with-indexes/, lu le 2026-10-07)
  - [officielle] https://mariadb.com/docs/server/server-usage/storage-engines/innodb/innodb-system-variables (lu le 2026-10-07)
  - [officielle] https://mariadb.com/docs/server/server-usage/storage-engines/innodb/innodb-lock-modes (lu le 2026-10-07)
  - [officielle] https://mariadb.com/docs/server/server-management/install-and-upgrade-mariadb/migrating-to-mariadb/migrating-to-mariadb-from-sql-server/mariadb-transactions-and-isolation-levels-for-sql-server-users (lu le 2026-10-07)
- Questions couvertes : UNIQUE sur colonne nullable ; deux INSERT concurrents sur la même clé unique ; défaut de `innodb_lock_wait_timeout`.
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

## Pièges connus
- Après une erreur 1205, la transaction reste OUVERTE avec ses écritures précédentes (défaut OFF de `innodb_rollback_on_timeout`) : c'est à l'application d'annuler. [officielle] innodb-system-variables
- Une colonne nullable sous UNIQUE n'empêche pas plusieurs lignes « sans valeur » : pour interdire les doublons de NULL, il faut une autre conception (non traité ici).

## À VÉRIFIER
- **Deux INSERT concurrents sur la même clé unique** : le second attend-il le COMMIT/ROLLBACK du premier, puis échoue en doublon (1062) après un commit, ou réussit après un rollback ? AUCUNE page MariaDB lue ne décrit ce scénario (innodb-lock-modes et la page isolation : « non traité »). Seul fait vérifié : le contrôle de doublon prend un verrou next-key à tout niveau d'isolation. Ne pas compléter de mémoire, ni à partir de la documentation d'un autre produit.
  → le plan doit le prouver par un test à deux connexions (INSERT dans T1 sans commit, INSERT même clé dans T2, observer attente puis 1062 / succès / 1205 après 50 s).
- Clause `where` (index partiel) proposée par l'ORM : support par MariaDB non vérifié.

## À confirmer par le plan
- Le correctif exact de l'image (`11.4.x`) n'est pas figé par l'étiquette `mariadb:11.4` : relever `SELECT VERSION()` si un comportement fin en dépend.
- Valeur effective de `innodb_lock_wait_timeout` / `innodb_rollback_on_timeout` dans la configuration du conteneur (surcharge éventuelle) : `SHOW VARIABLES` en préproduction.

## Écarts entre sources
- innodb-system-variables : le délai vaut pour un verrou d'enregistrement « (ou de table) » InnoDB. La page « transactions and isolation levels » dit qu'il vaut pour les verrous de ligne, ni de métadonnées ni de table. La page de référence des variables l'emporte ; l'écart ne change rien pour des verrous de ligne.
