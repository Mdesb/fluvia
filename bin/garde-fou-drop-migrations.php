<?php

declare(strict_types=1);

/**
 * Garde-fou n°13 — un `DROP` dans `up()` doit être voulu, et le dire (D32).
 *
 * ── LE DÉFAUT QU'IL PRÉVIENT ────────────────────────────────────────────────────────────────────
 *
 * `doctrine:migrations:diff` compare les métadonnées à la base **entière**. Il ramasse donc la dérive
 * laissée par les autres sessions et la présente comme le travail de l'auteur : `DROP TABLE
 * messenger_messages`, suppression du `FULLTEXT` du module Support, une quinzaine de renommages
 * d'index. Le brouillon de `claude-D` contenait 104 instructions dont 6 à elle.
 *
 * **Ce garde-fou est PRÉVENTIF, et il faut le dire.** Mesure du 26/08 : sur les 75 migrations du
 * dépôt, **onze** `DROP` figurent dans un `up()`, tous antérieurs au 17/08 et manifestement
 * délibérés — des consolidations de tables `sport_*`, `padel_*`, `patin_*`. **Aucun `DROP` de dérive
 * n'a jamais été commité.** Ce cliquet ne répare rien : il empêche le douzième de passer inaperçu.
 *
 * ── SEULEMENT `up()`, ET C'EST ESSENTIEL ────────────────────────────────────────────────────────
 *
 * Un `DROP` dans `down()` annule le `up()` de la même migration — c'est sa raison d'être. J'ai failli
 * signaler `DROP TABLE messenger_messages` comme un défaut avant de voir qu'il se trouvait dans le
 * `down()` de la migration qui **crée** cette table. Un garde-fou qui confond les deux transforme
 * chaque migration correcte en faute.
 *
 * ── L'ÉCHAPPATOIRE, ET POURQUOI ELLE EST PRÉFÉRABLE ─────────────────────────────────────────────
 *
 * Un `DROP` volontaire se déclare dans la migration elle-même :
 *
 *     @drop-voulu : <pourquoi cet objet disparaît, et ce qui le remplace>
 *
 * Elle est **greppable, datée et attribuable**, là où une détection assouplie ouvrirait un trou pour
 * tout le monde et sans trace. C'est la même porte que `@cloisonnement-verifie` dans le n°1, et pour
 * la même raison.
 *
 *     grep -rn "@drop-voulu" app/migrations
 *
 * Usage :
 *   php bin/garde-fou-drop-migrations.php
 *   php bin/garde-fou-drop-migrations.php --nettoyer
 *   php bin/garde-fou-drop-migrations.php --contre=origin/main
 */

const RACINE_MIGRATIONS = 'app/migrations';
const LIGNE_DE_BASE = 'bin/drop-migrations.ligne-de-base.json';

const MOTIF_SQL = '/addSql\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/s';
const MOTIF_ANNOTATION = '/@drop-voulu\s*:\s*\S/';

/** Les mots-clés qui suivent `DROP` sans désigner un objet supprimé. */
const NON_OBJETS = ['INDEX', 'FOREIGN', 'PRIMARY', 'CONSTRAINT', 'COLUMN', 'IF'];

/**
 * @return array<string, array{migration: string, type: string, objet: string}>
 */
function dropsDansUp(string $racine): array
{
    $drops = [];

    foreach (glob($racine . '/*.php') ?: [] as $chemin) {
        $source = (string) file_get_contents($chemin);
        $migration = basename($chemin);

        // Une migration qui déclare son intention est dispensée : la déclaration est le contrôle.
        if (preg_match(MOTIF_ANNOTATION, $source) === 1) {
            continue;
        }

        // ⚠ `up()` seulement. Un DROP dans `down()` annule le `up()` de la même migration.
        $up = explode('public function down(', $source)[0];

        if (preg_match_all(MOTIF_SQL, $up, $requetes, PREG_SET_ORDER) === 0) {
            continue;
        }

        // Ce que la migration crée elle-même : le supprimer ensuite est son affaire.
        $creees = [];
        foreach ($requetes as $requete) {
            $sql = $requete[1] !== '' ? $requete[1] : ($requete[2] ?? '');
            preg_match_all('/CREATE TABLE (\w+)/i', $sql, $m);
            foreach ($m[1] as $table) {
                $creees[strtolower($table)] = true;
            }
        }

        foreach ($requetes as $requete) {
            $sql = $requete[1] !== '' ? $requete[1] : ($requete[2] ?? '');
            if ($sql === '') {
                continue;
            }

            preg_match_all('/DROP TABLE (?:IF EXISTS )?(\w+)/i', $sql, $m);
            foreach ($m[1] as $table) {
                if (isset($creees[strtolower($table)])) {
                    continue;
                }
                $drops[$migration . '::TABLE::' . $table] = ['migration' => $migration, 'type' => 'TABLE', 'objet' => $table];
            }

            preg_match_all('/DROP INDEX (\w+)/i', $sql, $m);
            foreach ($m[1] as $index) {
                $drops[$migration . '::INDEX::' . $index] = ['migration' => $migration, 'type' => 'INDEX', 'objet' => $index];
            }

            preg_match_all('/ALTER TABLE \w+ DROP (?:COLUMN )?(\w+)/i', $sql, $m);
            foreach ($m[1] as $colonne) {
                if (in_array(strtoupper($colonne), NON_OBJETS, true)) {
                    continue;
                }
                $drops[$migration . '::COLONNE::' . $colonne] = ['migration' => $migration, 'type' => 'COLONNE', 'objet' => $colonne];
            }
        }
    }

    ksort($drops);

    return $drops;
}

$options = array_slice($argv, 1);
$drops = dropsDansUp(RACINE_MIGRATIONS);

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
    $base['entrees'] = $drops;
    $base['scelle']['plafond'] = count($drops);
    file_put_contents(
        LIGNE_DE_BASE,
        json_encode($base, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );
    echo sprintf("Ligne de base réécrite : %d suppression(s), plafond %d.\n", count($drops), count($drops));
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

$echec = false;
$nouveaux = array_values(array_diff(array_keys($drops), array_keys($base['entrees'])));

if ($nouveaux !== []) {
    $echec = true;
    echo "\n=== ÉCHEC — suppression non justifiée dans un up() de migration ===\n\n";
    foreach ($nouveaux as $cle) {
        $d = $drops[$cle];
        echo sprintf("  DROP %-8s %-34s %s\n", $d['type'], $d['objet'], $d['migration']);
    }
    echo "\n  `migrations:diff` compare les métadonnées à la base ENTIÈRE : il ramasse la dérive laissée\n";
    echo "  par les autres sessions et te la présente comme ton travail. Le brouillon de claude-D\n";
    echo "  contenait 104 instructions dont 6 à elle (D32).\n";
    echo "\n  Relis ce que tu supprimes. Deux cas, deux issues :\n\n";
    echo "  · ce n'est PAS ton lot — retire la ligne. Une migration ne contient que ce que son lot a\n";
    echo "    introduit.\n\n";
    echo "  · c'est voulu — dis-le dans la migration elle-même, avec la raison :\n\n";
    echo "        @drop-voulu : <pourquoi cet objet disparaît, et ce qui le remplace>\n\n";
    echo "  L'annotation est greppable, datée et attribuable ; un assouplissement de la détection ne\n";
    echo "  le serait pas. Pour auditer : grep -rn \"@drop-voulu\" app/migrations\n";
}

if (count($drops) > $plafond) {
    $echec = true;
    echo sprintf("\n=== ÉCHEC — la ligne de base a grossi (%d pour un plafond de %d) ===\n", count($drops), $plafond);
}

if ($plafondReference !== null && $plafond > $plafondReference) {
    $echec = true;
    echo sprintf("\n=== ÉCHEC — plafond relevé : %d sur la référence, %d proposé ===\n", $plafondReference, $plafond);
    echo "\n"
        . "  Un cliquet ne monte pas — c'est exactement ce qui lui donne sa valeur.\n"
        . "\n"
        . "  ⚠ La cause la plus fréquente n'est pas une faute : ta branche est simplement EN\n"
        . "  RETARD sur la référence, et un plafond a baissé entre-temps. Commence par ça :\n"
        . "\n"
        . "      git fetch origin && git merge --no-edit origin/main\n";
}

if ($echec) {
    exit(1);
}

$resorbes = count($base['entrees']) - count($drops);
echo sprintf(
    "Suppressions en migration : OK — aucune suppression nouvelle non justifiée. Gelées : %d, plafond %d.%s\n",
    count($drops),
    $plafond,
    $resorbes > 0 ? sprintf(' %d résorbée(s) — pense à --nettoyer.', $resorbes) : ''
);

exit(0);
