# PHP 8.4 — fiche de référence

- Version du projet : 8.4 (image `php:8.4-fpm-bookworm`, docker/php/Dockerfile ligne 1 ; hors verrou, correctif exact non figé)
- Vérifiée le : 2026-10-07 · Revoir avant le : 2027-01-05
- Sources :
  - [officielle] https://www.php.net/manual/en/function.round.php (lu le 2026-10-07)
  - [officielle] https://www.php.net/manual/en/migration84.incompatible.php, migration84.new-features.php, migration84.other-changes.php (lus le 2026-10-07)
  - [officielle] https://www.php.net/manual/en/function.proc-open.php, function.proc-close.php, function.proc-get-status.php (lus le 2026-10-07)
  - [officielle] https://www.php.net/manual/en/reserved.constants.php (lu le 2026-10-07)
  - [officielle] https://www.php.net/manual/en/datetimeimmutable.settimezone.php (lu le 2026-10-07)
  - [officielle] https://www.php.net/supported-versions.php (lu le 2026-10-07)
- Questions couvertes : `round()` sur les demi-valeurs (changements 8.4) ; `proc_open` pour lancer un second processus PHP dans un test ; `DateTimeImmutable::setTimezone()`.
- Limite : le manuel php.net décrit la dernière version ; les faits ci-dessous portent une mention de version explicite (changelog ou guide de migration 8.4).

## À utiliser

### `round()`
- `round(int|float $num, int $precision = 0, int|RoundingMode $mode = RoundingMode::HalfAwayFromZero): float`. [officielle] function.round.php
- Défaut = demi-valeur ÉLOIGNÉE DE ZÉRO : `round(2.5)` → 3, `round(-2.5)` → -3 ; `PHP_ROUND_HALF_UP` a la même règle. Autres : `PHP_ROUND_HALF_DOWN` (vers zéro), `PHP_ROUND_HALF_EVEN` (pair : 2.5 → 2), `PHP_ROUND_HALF_ODD`. [officielle] function.round.php
- Changements **8.4** :
  - enum `RoundingMode` ajoutée + 4 modes accessibles seulement par l'enum : `TowardsZero`, `AwayFromZero`, `NegativeInfinity`, `PositiveInfinity`. [officielle] migration84.new-features + changelog round
  - mode invalide → `ValueError` (avant : traité silencieusement comme `PHP_ROUND_HALF_UP`). [officielle] migration84.incompatible
  - **suppression du « pré-arrondi »** : avant 8.4, une valeur comme 0.285 (en réalité 0.28499999999999998) était traitée comme le décimal 0.285 et arrondie à 0.29 ; ce pré-arrondi arrondissait mal certains nombres et a été retiré. Exemples donnés : `round(0.49999999999999994)` vaut désormais 0.0 (et non 1.0) ; `round(4503599627370495.5)` vaut désormais 4503599627370496. [officielle] migration84.other-changes
  → les résultats de `round()` sur des flottants non représentables exactement peuvent DIFFÉRER de PHP 8.3.

### Lancer un second processus PHP (`proc_open`)
- `proc_open(array|string $command, array $descriptor_spec, array &$pipes, ?string $cwd = null, ?array $env_vars = null, ?array $options = null)` → ressource ou `false`. [officielle] function.proc-open.php
- Forme TABLEAU (depuis 7.4) : exécution directe sans shell, échappement des arguments par PHP — à préférer ; forme chaîne = passage par le shell (`escapeshellarg()` obligatoire pour toute donnée). Tableau vide → `ValueError` (8.3). [officielle] function.proc-open.php
- `$env_vars = null` : l'enfant hérite de l'environnement courant ; un tableau REMPLACE tout l'environnement (penser à `PATH`). [officielle] function.proc-open.php
- Garder la valeur de retour (sinon les flux échouent). Tampons de tubes limités : écrire beaucoup sur stdin sans lire stdout/stderr → INTERBLOCAGE ; lire en non bloquant ou par `stream_select()`. [officielle] function.proc-open.php
- `proc_close()` attend la fin et renvoie le code de sortie (-1 en erreur) ; fermer les tubes AVANT pour éviter un interblocage. Depuis 8.3, le code est correct même si `proc_get_status()` a été appelé avant. [officielle] function.proc-close.php
- `proc_get_status()['exitcode']` n'a de sens que si `running` vaut `false` ; depuis 8.3 le code est mis en cache (champ `cached`). [officielle] function.proc-get-status.php
- `PHP_BINARY` : chemin du binaire PHP en cours d'exécution (utile pour relancer « le même PHP »). [officielle] reserved.constants.php

### `DateTimeImmutable::setTimezone(DateTimeZone $timezone): DateTimeImmutable`
- Renvoie un NOUVEL objet ; l'original est inchangé. L'instant reste le même, seule l'heure locale affichée change (ex. Nauru 00:00+12:00 → Chatham 01:45+13:45). [officielle] datetimeimmutable.settimezone.php
- Ignorer la valeur de retour = aucun effet (piège classique des objets immuables).

## Sécurité (confirmé sur la doc officielle)
- Branche 8.4 : support actif jusqu'au **31/12/2026**, correctifs de sécurité jusqu'au **31/12/2028**. [officielle] supported-versions.php (lu le 2026-10-07)
- `proc_open` avec donnée variable : forme tableau ou `escapeshellarg()`. [officielle] function.proc-open.php

## Pièges connus
- Comparer des montants arrondis entre PHP 8.3 et 8.4 (fixtures, valeurs attendues figées) peut échouer à cause du retrait du pré-arrondi. [officielle] migration84.other-changes
- `proc_close()` peut renvoyer -1 si PHP est compilé avec `--enable-sigchild`. [officielle] function.proc-close.php
- 8.4 : `E_STRICT` retiré (constante dépréciée) ; `exit()`/`die()` se comportent comme des fonctions (`TypeError` sur type invalide). [officielle] migration84.incompatible

## À VÉRIFIER
- Valeur de `PHP_BINARY` sous FPM (vide ou chemin de php-fpm ?) : la page ne le dit pas. Dans un test lancé en CLI, la question ne se pose pas ; ne pas l'utiliser depuis une requête web sans vérification.

## Écarts entre sources
- La page `setTimezone` (manuel courant) signale un attribut `#[NoDiscard]` sur la méthode : il n'est pas mentionné dans les guides de migration 8.4 lus → sans effet garanti en 8.4 (À VÉRIFIER si l'on compte dessus).
