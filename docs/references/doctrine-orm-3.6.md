# Doctrine ORM 3.6 — fiche de référence

- Version du projet : 3.6.8 (source : app/composer.lock, ligne 2489)
- Vérifiée le : 2026-10-07 · Revoir avant le : 2027-01-05
- Sources :
  - [officielle] https://www.doctrine-project.org/projects/doctrine-orm/en/3.6/reference/transactions-and-concurrency.html (lu le 2026-10-07)
  - [officielle] https://www.doctrine-project.org/projects/doctrine-orm/en/3.6/reference/working-with-objects.html (lu le 2026-10-07)
  - [officielle] https://www.doctrine-project.org/projects/doctrine-orm/en/3.6/reference/attributes-reference.html (lu le 2026-10-07)
  - [officielle] https://raw.githubusercontent.com/doctrine/orm/3.6.8/UPGRADE.md (tag 3.6.8, lu le 2026-10-07)
  - [officielle] code source au tag 3.6.8 : `src/EntityManager.php`, `src/UnitOfWork.php` (lus le 2026-10-07)
- Questions couvertes : `lock(..., PESSIMISTIC_WRITE)` et transaction ; `refresh()` et collections ; EM fermé après exception de `flush()` ; état géré après rollback ; `#[ORM\UniqueConstraint]` sur colonne nullable.

## À utiliser

### `EntityManager::lock()` avec `LockMode::PESSIMISTIC_WRITE` — transaction EXIGÉE
- Signature 3.6.8 : `lock(object $entity, LockMode|int $lockMode, DateTimeInterface|int|null $lockVersion = null): void` ; `LockMode` = enum `Doctrine\DBAL\LockMode`. [officielle] EntityManager.php@3.6.8
- Sans transaction active sur la connexion → `TransactionRequiredException` (aussi pour `NONE` et `PESSIMISTIC_READ`). Entité non gérée → `ORMInvalidArgumentException` (entityNotManaged). [officielle] UnitOfWork.php@3.6.8 ; la doc : l'ORM lève une exception si on demande un verrou pessimiste hors transaction.
- Même règle pour `find($class, $id, LockMode::PESSIMISTIC_WRITE)` (contrôle `checkLockRequirements`). [officielle] transactions-and-concurrency + EntityManager.php
- `OPTIMISTIC` exige une entité versionnée (`#[ORM\Version]`, entier ou datetime) sinon `OptimisticLockException`.

### `refresh()` et les collections
- Signature 3.6.8 : `refresh(object $object, LockMode|int|null $lockMode = null): void` (EM fermé → erreur). [officielle] EntityManager.php@3.6.8
- Les entités ASSOCIÉES ne sont rafraîchies que si l'association porte `cascade: ['refresh']` (filtre `isCascadeRefresh()`). [officielle] UnitOfWork.php@3.6.8
- La collection to-many de l'entité rafraîchie est remplacée par une NOUVELLE `PersistentCollection` non initialisée (chargée à l'accès, ou tout de suite si `fetch: EAGER`) : les éléments ajoutés en mémoire et non flushés sont perdus. [officielle] UnitOfWork::createEntity@3.6.8 (lecture du code ; voir « À confirmer »)
- La page working-with-objects ne traite pas `refresh()`.

### EntityManager FERMÉ après une exception de `flush()`
- `flush()` → `UnitOfWork::commit()` : `beginTransaction()` puis, en cas d'échec, bloc `finally` : `$this->em->close()`, `rollBack()` si une transaction est active, puis remise à zéro interne. [officielle] UnitOfWork.php@3.6.8
- `close()` = `clear()` + drapeau fermé ; ensuite `flush()`, `refresh()`… lèvent une erreur (`errorIfClosed`). `isOpen()` renvoie `false`. [officielle] EntityManager.php@3.6.8
- Démarcation explicite : après un rollback, appeler `close()` et repartir d'un NOUVEL EntityManager. [officielle] transactions-and-concurrency

### Ce qui reste géré après un rollback
- Rien : « toutes les instances gérées ou supprimées deviennent détachées », avec l'état qu'elles avaient au moment du rollback (incohérent avec la base). [officielle] transactions-and-concurrency
- Cohérent avec le code : `close()` appelle `clear()` (vide la carte d'identité). [officielle] EntityManager.php@3.6.8

### `wrapInTransaction(callable $func): mixed`
- `beginTransaction()` → closure → `flush()` → `commit()` ; en cas d'échec : `close()` de l'EM puis `rollBack()` si transaction active. Renvoie la valeur de la closure. [officielle] EntityManager.php@3.6.8
- `Connection::transactional()` (DBAL) ne fait NI flush NI fermeture de l'EM. [officielle] transactions-and-concurrency

### `#[ORM\UniqueConstraint]`
- Attribut de CLASSE ; paramètres : `fields` (propriétés) OU `columns` (colonnes), `name` facultatif, `options` (dont `where` pour une contrainte partielle). [officielle] attributes-reference
- `#[ORM\Column(nullable: …, unique: …)]` : `nullable` vaut `false` par défaut. [officielle] attributes-reference
- Colonne nullable : la doc ORM ne dit RIEN des NULL ; le comportement est celui de la base (MariaDB : plusieurs NULL admis, voir `mariadb-11.4.md`).

## Obsolète ou retiré dans cette version — ne pas utiliser

| Ancien | Remplacé par | Depuis | Source |
|---|---|---|---|
| `EntityManager::transactional()` | `wrapInTransaction()` (renvoie la valeur de la closure) | 3.0 | [officielle] UPGRADE.md@3.6.8 |
| `flush($entity)` | `flush()` sans argument (toutes les entités gérées) | 3.0 | idem |
| `clear($entityName)` | `clear()` sans argument | 3.0 | idem |
| `merge()` | aucun | 3.0 | idem |
| annotations docblock de mapping | attributs PHP (ou XML) | 3.0 | idem |
| défauts en chaîne d'expression SQL | `DefaultExpression` DBAL (`CurrentTimestamp`…) | 3.6 | idem |
| `FieldMapping::$default` | `FieldMapping::$options['default']` | 3.6 | idem |
| `nullable: true` sur une colonne de jointure de clé primaire | la retirer | 3.6 | idem |
| `WITH` pour une jointure DQL arbitraire | `ON` (garder `WITH` pour filtrer une association) | 3.6 | idem |
| proxys générés (`setProxyDir()`…) | objets paresseux natifs (`enableNativeLazyObjects(true)`, obligatoire en PHP 8.4+) | 3.5 | idem |
| `ORMSetup::createAttributeMetadataConfiguration()` | `createAttributeMetadataConfig()` | 3.5 | idem |
| propriétés `$indexes` / `$uniqueConstraints` de `#[Table]` | attributs `#[Index]` / `#[UniqueConstraint]` | 3.2 | idem |

## Pièges connus
- Après TOUTE exception de `flush()`, l'EM est fermé : il faut un nouvel EntityManager (dans Symfony, réinitialiser via le registre — À VÉRIFIER sur la doc DoctrineBundle, non lue ici).
- Dans une transaction externe (DBAL `transactional()`), le `beginTransaction()` interne de `flush()` est un point de sauvegarde : son `rollBack()` n'annule QUE ce niveau ; la transaction externe reste ouverte jusqu'à ce que l'appelant l'annule. [officielle] UnitOfWork.php@3.6.8 + DBAL 4.4 (voir `doctrine-dbal-4.4.md`)
- `lock()` hors transaction → exception immédiate, même en test.
- `refresh()` sans cascade ne relit pas les entités liées ; il remplace néanmoins la collection de l'entité.

## À confirmer par le plan
- Remplacement de la collection par `refresh()` : déduit de la lecture du code (branche to-many de `createEntity`) ; à prouver par un test (élément ajouté non flushé, puis `refresh()`).
- Contrainte unique partielle (`options: ['where' => …]`) : prise en charge par MariaDB non vérifiée → ne pas compter dessus sans test.

## Écarts entre sources
- Aucun écart constaté ; la doc ne détaille pas `refresh()`, le code du tag 3.6.8 fait foi.
