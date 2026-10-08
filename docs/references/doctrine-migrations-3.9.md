# Doctrine Migrations 3.9 — fiche de référence

- Version du projet : 3.9.7 (source : app/composer.lock, ligne 2386)
- Vérifiée le : 2026-10-07 · Revoir avant le : 2027-01-05
- Sources :
  - [officielle] https://www.doctrine-project.org/projects/doctrine-migrations/en/3.9/reference/configuration.html (lu le 2026-10-07)
  - [officielle] https://www.doctrine-project.org/projects/doctrine-migrations/en/3.9/reference/managing-migrations.html (lu le 2026-10-07)
  - [officielle] https://raw.githubusercontent.com/doctrine/migrations/3.9.7/UPGRADE.md (tag 3.9.7, lu le 2026-10-07)
  - [officielle] code au tag 3.9.7 : `src/Metadata/Storage/TableMetadataStorage.php`, `src/Tools/Console/Command/ExecuteCommand.php` (lus le 2026-10-07)
- Questions couvertes : table de suivi (nom, colonnes par défaut) ; `migrations:status` ; `migrations:execute --up/--down`.

## À utiliser

### Table de suivi — valeurs par défaut (`table_storage`)
| Option | Défaut | Colonne créée (code 3.9.7) |
|---|---|---|
| `table_name` | `doctrine_migration_versions` | — |
| `version_column_name` | `version` | type DBAL `string`, longueur `version_column_length`, NOT NULL, CLÉ PRIMAIRE |
| `version_column_length` | `191` | — |
| `executed_at_column_name` | `executed_at` | type DBAL `datetime`, NULL admis |
| `execution_time_column_name` | `execution_time` | type DBAL `integer`, NULL admis, en MILLISECONDES (durée × 1000, arrondie) |
[officielle] configuration.html + TableMetadataStorage.php@3.9.7
- La valeur de `version` est le nom complet (FQCN) de la classe de migration. [officielle] UPGRADE.md@3.9.7

### Réglages de comportement (défauts)
- `transactional: true` — chaque migration dans une transaction. [officielle] configuration.html
- `all_or_nothing: false` — plusieurs migrations ne sont PAS regroupées dans une seule transaction par défaut. [officielle] configuration.html
- `check_database_platform: true` — contrôle de plateforme ajouté au code généré. [officielle] configuration.html

### `migrations:status`
- Affiche la configuration, la version courante et la dernière, et la liste des migrations avec leur état ; `--show-versions` détaille. [officielle] managing-migrations.html

### `migrations:execute` (alias `execute`)
- Argument `versions` (obligatoire, tableau) : FQCN entre apostrophes, ex. `'App\Migrations\Version20260101000000'`. [officielle] managing-migrations.html + ExecuteCommand.php@3.9.7
- Options : `--up`, `--down`, `--dry-run`, `--write-sql[=chemin]` (défaut : dossier courant), `--query-time`. [officielle] ExecuteCommand.php@3.9.7
- Sans `--up` ni `--down` : direction **up**. [officielle] ExecuteCommand.php@3.9.7
- Demande une confirmation sauf `--no-interaction`. [officielle] managing-migrations.html

### Autres commandes utiles
- `migrations:migrate` : alias de cible `first`, `prev`, `next`, `latest` ; `--write-sql` écrit le SQL au lieu de l'exécuter. [officielle] managing-migrations.html
- `migrations:version --add | --delete` : modifie la table de suivi SANS exécuter la migration ; supprimer une version puis lancer `migrate` la rejoue. [officielle] managing-migrations.html
- `migrations:sync-metadata-storage` : met la table de suivi au format attendu (`migrate` et `execute` le font aussi automatiquement). [officielle] UPGRADE.md@3.9.7

## Obsolète ou retiré dans cette version — ne pas utiliser

| Ancien | Remplacé par | Depuis | Source |
|---|---|---|---|
| `--all-or-nothing=<valeur>` (avec une valeur) | `--all-or-nothing` sans valeur | 3.6 | [officielle] UPGRADE.md@3.9.7 |
| configuration plate de la v2 | sections `table_storage` et `migrations_paths` | 3.x | [officielle] UPGRADE.md@3.9.7 |

## Pièges connus
- `transactional: true` ne protège pas les instructions DDL sous MariaDB/MySQL (validation implicite) — À VÉRIFIER : non lu sur la doc 3.9 ni sur la doc MariaDB.
- `migrations:version --delete` + `migrate` = la migration est REJOUÉE. [officielle] managing-migrations.html
- `execution_time` est en millisecondes, pas en secondes. [officielle] code 3.9.7

## À confirmer par le plan
- Dans l'application Symfony, les commandes passent normalement par le bundle (préfixe `doctrine:migrations:…`) : la version de `doctrine/doctrine-migrations-bundle` et le préfixe ne sont pas fournis ici → À VÉRIFIER dans `app/composer.lock`.
- Si la configuration du projet surcharge `table_storage`, les noms ci-dessus ne s'appliquent pas : lire `config/packages/doctrine_migrations.yaml`.
