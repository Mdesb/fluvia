<?php

declare(strict_types=1);

/**
 * Garde-fou n°10 — un `DEFAULT` posé en migration doit être déclaré au mapping (D32).
 *
 * ── UNE RÈGLE RETIRÉE, ET POURQUOI ─────────────────────────────────────────────────────────────
 *
 * Ce garde-fou a porté un temps une seconde règle : « un index nommé à la main doit être déclaré au
 * mapping ». Elle est **retirée**, mesure à l'appui. Une base bâtie par les migrations propose
 * **71 renommages** d'index ; la lecture statique, une fois ses quatre formes d'écriture corrigées,
 * en signalait **220** — dont 61 seulement parmi les 71 réels. Un cliquet à 220 aurait gelé environ
 * cent cinquante non-défauts, et le signal de résorption n'aurait plus rien voulu dire.
 *
 * La cause n'est pas un motif de plus à écrire : la question « cet index dérive-t-il ? » se décide
 * contre une **base**, pas contre un fichier — le nom que Doctrine calcule dépend de son propre
 * algorithme et du mapping résolu. `bin/verifier-derive-schema.sh` fait cette mesure-là.
 *
 * ── LE DÉFAUT ───────────────────────────────────────────────────────────────────────────────────
 *
 * Une colonne créée avec `DEFAULT 'x'` en migration, dont l'entité ne déclare pas
 * `options: ['default' => 'x']`, ressort en `CHANGE` dans `doctrine:migrations:diff` — **chez tout le
 * monde, et éternellement**. Chaque session qui génère une migration ramasse la dérive des autres et
 * la présente comme son propre travail : c'est la troisième cause structurelle de D32.
 *
 * Trouvée par `claude-F` le 25/08 en vérifiant sa propre migration sur une base repartie de zéro.
 * Elle était de quatre cas, sur deux sessions différentes.
 *
 * ── POURQUOI CE MOTIF-LÀ ET PAS LE TRI DES `DROP` ───────────────────────────────────────────────
 *
 * D32 décrit aussi des `DROP TABLE`/`DROP INDEX` intempestifs. Les trier demanderait de savoir ce que
 * la migration a elle-même créé, ce qui est ambigu et produit des faux positifs — un `DROP` légitime
 * dans un `down()` ressemble à un `DROP` fautif dans un `up()`. La comparaison des `DEFAULT`, elle,
 * est **mécanique** : la valeur est écrite dans la migration, la déclaration est dans l'entité, et
 * l'une des deux manque ou non.
 *
 * ── CE QU'IL NE REGARDE PAS, ET POURQUOI ────────────────────────────────────────────────────────
 *
 * · **Seulement `up()`.** Un `DROP`/`DEFAULT` dans `down()` annule le `up()` de la même migration :
 *   c'est sa raison d'être. J'ai failli signaler `DROP TABLE messenger_messages` comme un défaut
 *   avant de voir qu'il était dans le `down()` de la migration qui crée la table.
 * · **Une colonne sans propriété correspondante** est ignorée elle aussi, et c'est un angle mort
 *   assumé : si la propriété n'existe pas, la colonne n'est pas mappée du tout et le diff
 *   proposerait de la SUPPRIMER — un autre défaut, plus gros, qui n'est pas celui-ci. Le compte
 *   des ignorés est affiché à chaque exécution pour que l'angle mort soit visible et non taisible.
 *   (Découvert en testant : mon premier essai injectait une colonne sans propriété et le
 *   garde-fou se taisait. L'essai était irréaliste, mais le silence méritait d'être écrit.)
 * · **Les tables qu'aucune entité ne mappe** sont ignorées : `messenger_messages` et consorts n'ont
 *   pas de mapping à comparer, et `schema_filter` les exclut déjà du diff (vérifié à l'exécution le
 *   25/08, avec témoin).
 *
 * ── DEUX PIÈGES D'ANALYSE, PAYÉS COMPTANT ───────────────────────────────────────────────────────
 *
 * Mes deux premières versions de la mesure étaient présentables et fausses :
 *
 * 1. Chercher le bloc d'attributs par un motif qui s'arrête au premier `]` : les attributs PHP en
 *    contiennent (`options: ['default' => 1]`). 167 faux positifs sur 171.
 * 2. Lire l'instruction `ALTER TABLE` avec un motif qui s'arrête au premier apostrophe : tout
 *    `DEFAULT 'chaîne'` devient invisible. Faux négatif — un garde-fou qui rassure.
 *
 * D'où : on extrait l'argument **complet** de chaque `addSql`, et la fenêtre de lecture du mapping est
 * bornée au **point-virgule précédent** — le bloc d'attributs de *cette* propriété, jamais celui de sa
 * voisine, sans quoi le `options` du voisin déclare conforme ce qui ne l'est pas.
 *
 * Usage :
 *   php bin/garde-fou-defauts-mapping.php
 *   php bin/garde-fou-defauts-mapping.php --nettoyer
 *   php bin/garde-fou-defauts-mapping.php --contre=origin/main
 */

const RACINE_MIGRATIONS = 'app/migrations';
const RACINE_SRC = 'app/src';
const LIGNE_DE_BASE = 'bin/defauts-mapping.ligne-de-base.json';

/** Argument complet d'un `addSql`, quelle que soit sa délimitation. */
const MOTIF_SQL = '/addSql\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/s';
/** Corps d'un heredoc : `addSql(<<<SQL ... SQL)` ou `addSql(<<<'SQL' ... SQL)`. */
const MOTIF_SQL_HEREDOC = '/addSql\(\s*<<<[\'"]?(\w+)[\'"]?\R(.*?)\R\s*\1/s';
const MOTIF_CREATE = '/CREATE TABLE (\w+) \((.*)\)\s*DEFAULT CHARACTER SET/s';
const MOTIF_ALTER = '/ALTER TABLE (\w+) (.*)/s';
const MOTIF_COLONNE = '/\b(\w+) [A-Z]+(?:\([\d, ]*\))? DEFAULT (\'(?:[^\']*)\'|\d+)/';
const MOTIF_TABLE_ENTITE = '/#\[ORM\\\\Table\(name:\s*\'([^\']+)\'/';

const RESERVES = ['KEY', 'INDEX', 'PRIMARY', 'UNIQUE', 'CONSTRAINT', 'TABLE'];

/** Index nommés dans une migration, sous leurs trois formes d'écriture. */
const MOTIF_INDEX_INLINE = '/\b(?:UNIQUE )?INDEX (\w+) \(/i';
const MOTIF_INDEX_CREATE = '/CREATE (?:UNIQUE |FULLTEXT )?INDEX (\w+) ON (\w+)/i';
const MOTIF_INDEX_ALTER  = '/ALTER TABLE (\w+) ADD (?:UNIQUE |FULLTEXT )?INDEX (\w+)/i';
/** MySQL cree un index portant le nom de la contrainte : `CONSTRAINT fk_x FOREIGN KEY ...`. */
const MOTIF_INDEX_CONTRAINTE = '/CONSTRAINT (\w+) FOREIGN KEY/i';
const MOTIF_INDEX_MAPPE  = '/(?:Index|UniqueConstraint)\(name:\s*\'([^\']+)\'/';

/**
 * Un nom réellement généré par Doctrine : préfixe en MAJUSCULES suivi d'hexadécimal, et rien d'autre —
 * `IDX_936C17166C1129CD`. Ceux-là ne se déclarent pas au mapping et ne dérivent jamais.
 *
 * ⚠ Le motif était `/^(IDX|UNIQ|PRIMARY|FK)_/i`, insensible à la casse. Il écartait donc
 * `idx_prenotification_mandate`, `fk_..._etab`, `uniq_scheduled_task_run_command` — **soixante et onze
 * index écrits à la main**, c'est-à-dire exactement la dérive recherchée. La mesure statique donnait 1
 * là où une base bâtie par les migrations en proposait 71 au renommage.
 *
 * Un nom lisible n'est jamais auto-généré, quelle que soit sa casse : `IDX_access_product_zone_product`
 * commence par le préfixe mais poursuit en mots, il est écrit à la main lui aussi.
 */
const MOTIF_INDEX_AUTO = '/^(IDX|UNIQ|FK)_[0-9A-F]+$/';

function camel(string $snake): string
{
    $morceaux = explode('_', $snake);
    $tete = array_shift($morceaux);

    return $tete . implode('', array_map('ucfirst', $morceaux));
}

/**
 * Tout le SQL d'une migration, quelle que soit la forme d'ecriture.
 *
 * Le depot en emploie quatre : chaine simple, chaine double, heredoc, et concatenation de fragments.
 * L'extraction initiale n'en lisait qu'une seule — la premiere chaine litterale suivant `addSql(`.
 * Consequence mesuree : sur une base batie par les migrations, Doctrine proposait **71 renommages**
 * d'index la ou ce garde-fou en voyait **19**, et les deux ensembles etaient DISJOINTS. Deux
 * populations qui ne se recoupent pas, c'est le signe qu'on ne lit pas la meme chose.
 *
 * On lit donc l'argument COMPLET de chaque `addSql`, parentheses equilibrees, puis on en tire le
 * texte. Une seule lecture plutot qu'une pile de motifs : les formes a venir sont couvertes d'avance.
 *
 * @return list<string>
 */
function sqlDeLaMigration(string $source): array
{
    $requetes = [];
    $decalage = 0;

    while (($debut = strpos($source, 'addSql(', $decalage)) !== false) {
        $i = $debut + strlen('addSql(');
        $profondeur = 1;
        $longueur = strlen($source);

        while ($i < $longueur && $profondeur > 0) {
            $c = $source[$i];
            if ($c === '(') {
                ++$profondeur;
            } elseif ($c === ')') {
                --$profondeur;
            }
            ++$i;
        }

        $argument = substr($source, $debut + strlen('addSql('), $i - $debut - strlen('addSql(') - 1);
        $decalage = $i;

        // Heredoc : le corps est tout ce qui separe la ligne d'ouverture du marqueur de fermeture.
        if (preg_match('/^\s*<<<[\'"]?(\w+)[\'"]?\R(.*?)\R\s*\1/s', $argument, $h) === 1) {
            $requetes[] = $h[2];
            continue;
        }

        // Chaines et concatenations : on recolle tous les fragments litteraux de l'argument. Un
        // fragment interpole (`{$var}`) laisse un trou, ce qui est sans effet ici — on ne cherche que
        // des noms de tables, de colonnes et d'index, jamais des valeurs.
        preg_match_all('/\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"/s', $argument, $f, PREG_SET_ORDER);
        $morceaux = '';
        foreach ($f as $fragment) {
            $morceaux .= ($fragment[1] ?? '') !== '' ? $fragment[1] : ($fragment[2] ?? '');
        }
        if (trim($morceaux) !== '') {
            $requetes[] = $morceaux;
        }
    }

    return $requetes;
}

/**
 * @return array<string, array{table: string, colonne: string, valeur: string, migration: string}>
 */
function defautsDesMigrations(string $racine): array
{
    $defauts = [];

    foreach (glob($racine . '/*.php') ?: [] as $chemin) {
        $source = (string) file_get_contents($chemin);

        // Seulement `up()` : un DEFAULT dans `down()` annule le `up()` de la même migration.
        $up = explode('public function down(', $source)[0];

        $requetes = sqlDeLaMigration($up);
        if ($requetes === []) {
            continue;
        }

        foreach ($requetes as $requete) {
            $sql = $requete;
            if ($sql === '') {
                continue;
            }

            if (preg_match(MOTIF_CREATE, $sql, $m) === 1) {
                [$table, $corps] = [$m[1], $m[2]];
            } elseif (preg_match(MOTIF_ALTER, $sql, $m) === 1) {
                [$table, $corps] = [$m[1], $m[2]];
            } else {
                continue;
            }

            preg_match_all(MOTIF_COLONNE, $corps, $colonnes, PREG_SET_ORDER);
            foreach ($colonnes as $colonne) {
                if (in_array(strtoupper($colonne[1]), RESERVES, true)) {
                    continue;
                }
                $cle = $table . '.' . $colonne[1];
                $defauts[$cle] = [
                    'table' => $table,
                    'colonne' => $colonne[1],
                    'valeur' => $colonne[2],
                    'migration' => basename($chemin),
                ];
            }
        }
    }

    ksort($defauts);

    return $defauts;
}

/**
 * @return array<string, array{chemin: string, source: string}>
 */
function entitesParTable(string $racine): array
{
    $tables = [];
    $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));

    /** @var SplFileInfo $fichier */
    foreach ($iterateur as $fichier) {
        if (!$fichier->isFile() || $fichier->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($fichier->getPathname());
        if (preg_match(MOTIF_TABLE_ENTITE, $source, $m) === 1) {
            $tables[$m[1]] = [
                'chemin' => str_replace('\\', '/', substr($fichier->getPathname(), strlen($racine) + 1)),
                'source' => $source,
            ];
        }
    }

    return $tables;
}

/**
 * Index nommés à la main dans les migrations, et non générés par Doctrine.
 *
 * @return array<string, array{table: string, migration: string}>
 */
function indexNommes(string $racine): array
{
    $index = [];

    foreach (glob($racine . '/*.php') ?: [] as $chemin) {
        $up = explode('public function down(', (string) file_get_contents($chemin))[0];

        $requetes = sqlDeLaMigration($up);
        if ($requetes === []) {
            continue;
        }

        foreach ($requetes as $requete) {
            $sql = $requete;
            if ($sql === '') {
                continue;
            }

            preg_match_all(MOTIF_INDEX_CREATE, $sql, $m, PREG_SET_ORDER);
            foreach ($m as $t) {
                $index[$t[1]] = ['table' => $t[2], 'migration' => basename($chemin)];
            }

            preg_match_all(MOTIF_INDEX_ALTER, $sql, $m, PREG_SET_ORDER);
            foreach ($m as $t) {
                $index[$t[2]] = ['table' => $t[1], 'migration' => basename($chemin)];
            }

            // MySQL indexe toute contrainte de cle etrangere sous le nom de la contrainte : c'est un
            // index nomme a la main comme un autre, et le diff propose de le renommer.
            if (preg_match('/(?:CREATE TABLE|ALTER TABLE) (\w+)/i', $sql, $mt) === 1) {
                preg_match_all(MOTIF_INDEX_CONTRAINTE, $sql, $m, PREG_SET_ORDER);
                foreach ($m as $t) {
                    $index[$t[1]] ??= ['table' => $mt[1], 'migration' => basename($chemin)];
                }
            }

            if (preg_match('/(?:CREATE TABLE|ALTER TABLE) (\w+)/i', $sql, $mt) === 1) {
                preg_match_all(MOTIF_INDEX_INLINE, $sql, $m, PREG_SET_ORDER);
                foreach ($m as $t) {
                    $index[$t[1]] ??= ['table' => $mt[1], 'migration' => basename($chemin)];
                }
            }
        }
    }

    return array_filter(
        $index,
        static fn (string $nom): bool => preg_match(MOTIF_INDEX_AUTO, $nom) !== 1,
        ARRAY_FILTER_USE_KEY
    );
}

/** @return list<string> noms d'index déclarés quelque part dans le mapping */
function indexDeclares(string $racine): array
{
    $noms = [];
    $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));

    /** @var SplFileInfo $fichier */
    foreach ($iterateur as $fichier) {
        if (!$fichier->isFile() || $fichier->getExtension() !== 'php') {
            continue;
        }
        preg_match_all(MOTIF_INDEX_MAPPE, (string) file_get_contents($fichier->getPathname()), $m);
        foreach ($m[1] as $nom) {
            $noms[] = $nom;
        }
    }

    return array_values(array_unique($noms));
}

$options = array_slice($argv, 1);

$defauts = defautsDesMigrations(RACINE_MIGRATIONS);
$tables = entitesParTable(RACINE_SRC);

$ecarts = [];
$ignores = 0;

foreach ($defauts as $cle => $defaut) {
    if (!isset($tables[$defaut['table']])) {
        ++$ignores;                     // aucune entité ne mappe cette table : rien à comparer.
        continue;
    }

    $source = $tables[$defaut['table']]['source'];
    $propriete = camel($defaut['colonne']);

    $position = null;
    if (preg_match('/private\s+[^;=]*\$' . preg_quote($propriete, '/') . '\b/', $source, $m, PREG_OFFSET_CAPTURE) === 1) {
        $position = $m[0][1];
    } elseif (preg_match('/name:\s*\'' . preg_quote($defaut['colonne'], '/') . '\'/', $source, $m, PREG_OFFSET_CAPTURE) === 1) {
        $position = $m[0][1];
    }

    if ($position === null) {
        ++$ignores;                     // propriété introuvable : on ne conclut pas sur ce qu'on ne lit pas.
        continue;
    }

    // ⚠ Bornée au point-virgule précédent. Sans cette borne, le `options: ['default' => …]` de la
    // propriété VOISINE déclare conforme une propriété qui ne l'est pas.
    $bornePrecedente = strrpos(substr($source, 0, $position), ';');
    $fenetre = $bornePrecedente === false ? substr($source, 0, $position) : substr($source, $bornePrecedente + 1, $position - $bornePrecedente - 1);

    if (str_contains($fenetre, "'default'") || str_contains($fenetre, '"default"')) {
        continue;
    }

    $ecarts[$cle] = [
        'table' => $defaut['table'],
        'colonne' => $defaut['colonne'],
        'valeur' => $defaut['valeur'],
        'migration' => $defaut['migration'],
        'entite' => $tables[$defaut['table']]['chemin'],
    ];
}

ksort($ecarts);

if (!is_file(LIGNE_DE_BASE)) {
    fwrite(STDERR, sprintf("Ligne de base absente : %s\nCrée-la : php %s --nettoyer\n", LIGNE_DE_BASE, $argv[0]));
    exit(2);
}

$base = json_decode((string) file_get_contents(LIGNE_DE_BASE), true, 512, JSON_THROW_ON_ERROR);

if (!is_array($base) || !isset($base['scelle']['plafond'], $base['entrees'])) {
    fwrite(STDERR, "Ligne de base illisible : clés « scelle.plafond » et « entrees » attendues.\n");
    exit(2);
}

$plafond = (int) $base['scelle']['plafond'];

if (in_array('--nettoyer', $options, true)) {
    $base['entrees'] = $ecarts;
    $base['scelle']['plafond'] = count($ecarts);
    file_put_contents(
        LIGNE_DE_BASE,
        json_encode($base, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );
    echo sprintf("Ligne de base réécrite : %d écart(s), plafond %d.\n", count($ecarts), count($ecarts));
    exit(0);
}

$plafondReference = null;
foreach ($options as $option) {
    if (!str_starts_with($option, '--contre=')) {
        continue;
    }

    $sortie = [];
    $code = 0;
    exec(sprintf(
        'git show %s:%s 2>/dev/null',
        escapeshellarg(substr($option, strlen('--contre='))),
        escapeshellarg(LIGNE_DE_BASE)
    ), $sortie, $code);

    if ($code === 0 && $sortie !== []) {
        $reference = json_decode(implode("\n", $sortie), true);
        if (is_array($reference) && isset($reference['scelle']['plafond'])) {
            $plafondReference = (int) $reference['scelle']['plafond'];
        }
    }
}

const AIDE_DEFAUT = <<<'TXT'

  Cette colonne porte un `DEFAULT` en base que le mapping ORM ne déclare pas. Conséquence : elle
  ressort en `CHANGE` dans `doctrine:migrations:diff` — chez TOUT LE MONDE, et à chaque génération.
  Chaque session ramassera ta dérive et la présentera comme son propre travail (D32).

  Le correctif tient en un argument, sur la propriété concernée :

      #[ORM\Column(length: 24, options: ['default' => 'restored_with_reschedule'])]

  La valeur doit être **exactement** celle de la migration — une valeur différente déplace le
  problème au lieu de le résoudre.

  Si la colonne est délibérément sans défaut au mapping, alors c'est la MIGRATION qui a tort : le
  `DEFAULT` n'y a rien à faire.
TXT;

const AIDE_INDEX = <<<'TXT'

  Cet index porte un nom choisi à la main dans une migration, et le mapping ORM ne le connaît pas.
  Doctrine le voit donc comme « à supprimer » et propose `DROP INDEX` — chez TOUT LE MONDE, à chaque
  génération. C'est la troisième cause structurelle de D32.

  Déclare-le sur l'entité :

      #[ORM\Index(name: 'idx_ma_table_ma_colonne', fields: ['maColonne'])]
      #[ORM\UniqueConstraint(name: 'uniq_ma_table_ma_cle', fields: ['maCle'])]

  ⚠ Une exception existe et une seule : un index `FULLTEXT` n'est PAS exprimable en mapping ORM. Si
  c'est ton cas, il ne peut qu'être gelé dans la ligne de base — pas déclaré.
TXT;

$echec = false;
$nouveaux = array_values(array_diff(array_keys($ecarts), array_keys($base['entrees'])));

if ($nouveaux !== []) {
    $echec = true;
    echo "\n=== ÉCHEC — DEFAULT posé en migration, absent du mapping ===\n\n";
    foreach ($nouveaux as $cle) {
        $e = $ecarts[$cle];
        echo sprintf("  %s.%s  DEFAULT %s\n", $e['table'], $e['colonne'], $e['valeur']);
        echo sprintf("      migration : %s\n", $e['migration']);
        echo sprintf("      entité    : %s\n", $e['entite']);
    }
    echo AIDE_DEFAUT . "\n";
}


if (count($ecarts) > $plafond) {
    $echec = true;
    echo sprintf("\n=== ÉCHEC — la ligne de base a grossi (%d pour un plafond de %d) ===\n", count($ecarts), $plafond);
}

if ($plafondReference !== null && $plafond > $plafondReference) {
    $echec = true;
    echo sprintf("\n=== ÉCHEC — plafond relevé : %d sur la référence, %d proposé ===\n", $plafondReference, $plafond);
}

if ($echec) {
    exit(1);
}

$resorbes = count($base['entrees']) - count($ecarts);
echo sprintf(
    "Défauts au mapping : OK — aucun DEFAULT non déclaré au mapping. Gelés : %d, plafond %d. (%d DEFAULT lus, %d ignorés faute de mapping.)%s\n",
    count($ecarts),
    $plafond,
    count($defauts),
    $ignores,
    $resorbes > 0 ? sprintf(' %d résorbé(s) — pense à --nettoyer.', $resorbes) : ''
);

exit(0);
