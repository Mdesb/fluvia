<?php

declare(strict_types=1);

/**
 * Garde-fou n°57 — une classe PHP citée qu'aucun fichier ne déclare.
 *
 * ── CE QU'IL EMPÊCHE ──────────────────────────────────────────────────────────────────────────
 *
 * Supprimer ou renommer une classe en laissant des fichiers qui la citent. PHP ne dit rien : un
 * `use` vers une classe absente ne coûte rien tant que la ligne ne s'exécute pas, et un ATTRIBUT
 * vers une classe absente ne coûte jamais rien — Symfony filtre les attributs par type, l'inconnu
 * disparaît. Le 07/10, en naissant, il a trouvé `#[Assert\Positive]` sur `Reservation::$quantity`
 * sans l'import d'`Assert` : la contrainte n'avait jamais été appliquée, sans une erreur.
 *
 * ── ⚠ CE N'EST PAS LE N°37, ET C'EST LA CONFUSION QUI L'A FAIT NAÎTRE (#30) ─────────────────
 *
 * « Classes fantômes » (n°37) parle de classes CSS. En écrivant le n°55, on l'a cru couvrir ce
 * cas-ci : `TradeFallback.php` retiré, toujours cité, n°37 vert. Il ne lit que le JSX et le CSS.
 *
 * ── CE QU'IL LIT ──────────────────────────────────────────────────────────────────────────────
 *
 * Le PHP de `app/` (src, tests, config, migrations, bin, public, racine) par le tokeniseur, donc
 * jamais un commentaire. Chaque nom qualifié est résolu comme PHP le fait : `use` (alias et
 * groupes compris), sinon l'espace de noms courant. Une chaîne qui n'est QU'UN nom `App\…` compte
 * aussi. Et les noms `App\…` des YAML de `app/config/`, hors commentaires.
 *
 * Un nom `App\…` est juste s'il est un dossier PSR-4 (un espace de noms), ou une classe, interface,
 * trait ou énumération DÉCLARÉE dans le fichier que le PSR-4 de `app/composer.json` lui assigne.
 * Une classe renommée dans un fichier qui garde l'ancien nom est donc vue.
 *
 * ── ⚠ CE QU'IL NE VOIT PAS ────────────────────────────────────────────────────────────────────
 *
 * - Un nom court résolu dans l'espace de noms courant (`new Voisine()`, même dossier, sans `use`).
 * - En YAML, un nom entre guillemets doubles (`"App\\Vente\\X"`) : aucun aujourd'hui (07/10).
 * - En pre-commit, l'arbre de travail et non l'index : un fichier créé mais pas ajouté le rend vert.
 *   La CI et le pre-receive lisent l'arbre poussé.
 * - Une FONCTION ou une constante d'espace de noms appelée qualifiée serait signalée à tort : il
 *   n'y en a aucune (07/10). `use function` et `use const` sont ignorés.
 * Il SOUS-DÉTECTE, comme le n°37 : un faux positif s'apprend à sauter, un angle mort écrit se lit.
 */

$app = \dirname(__DIR__) . '/app';
if (!is_file("$app/composer.json")) {
    echo "✓ Classes PHP disparues : pas d'app/composer.json ici, rien à vérifier.\n";
    exit(0);
}
$composer = json_decode((string) file_get_contents("$app/composer.json"), true);
if (!\is_array($composer)) {
    echo "✗ Classes PHP disparues : app/composer.json illisible — sans PSR-4, rien ne se résout.\n";
    exit(1);
}
$prefixes = ($composer['autoload']['psr-4'] ?? []) + ($composer['autoload-dev']['psr-4'] ?? []);
// Le préfixe le plus long l'emporte : `App\Tests\` vit dans tests/, pas dans src/Tests/.
uksort($prefixes, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

/** Les chemins (sans `.php`) que le PSR-4 assigne à un nom ; vide hors des préfixes du projet. */
$chemins = static function (string $nom) use ($prefixes, $app): array {
    foreach ($prefixes as $prefixe => $dossiers) {
        if (str_starts_with($nom . '\\', $prefixe)) {
            $reste = str_replace('\\', '/', substr($nom, \strlen($prefixe)));

            return array_map(static fn (string $d): string => "$app/" . rtrim($d, '/') . "/$reste", (array) $dossiers);
        }
    }

    return [];
};

/** [citations [[nom, ligne]], déclarations [nom]] d'un source PHP, noms résolus comme PHP les résout. */
$lirePhp = static function (string $code): array {
    $t = array_values(array_filter(token_get_all($code), static fn ($x): bool => !\is_array($x)
        || !\in_array($x[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)));
    $est = static fn ($x, int ...$ids): bool => \is_array($x) && \in_array($x[0], $ids, true);
    $ns = '';
    $alias = [];
    $cites = [];
    $declares = [];
    $profondeur = 0;
    for ($i = 0, $n = \count($t); $i < $n; ++$i) {
        $x = $t[$i];
        if ($x === '{' || $est($x, \T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES)) {
            ++$profondeur;
        } elseif ($x === '}') {
            --$profondeur;
        } elseif ($est($x, \T_NAMESPACE) && $est($t[$i + 1] ?? null, \T_STRING, \T_NAME_QUALIFIED)) {
            $ns = $t[++$i][1];
            $alias = [];
        } elseif ($est($x, \T_USE) && $profondeur === 0 && ($t[$i + 1] ?? '(') !== '(') {
            // Un import : `use A\B [as C], D;` ou `use A\{B [as C], D\E};`. Pas un trait, pas une closure.
            $ignorer = $est($t[$i + 1], \T_FUNCTION, \T_CONST);
            $groupe = '';
            for (++$i; $i < $n && $t[$i] !== ';'; ++$i) {
                $y = $t[$i];
                if ($y === '}') {
                    $groupe = '';
                } elseif (!$ignorer && $est($y, \T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED)) {
                    if ($est($t[$i + 1] ?? null, \T_NS_SEPARATOR) && ($t[$i + 2] ?? null) === '{') {
                        $groupe = ltrim($y[1], '\\') . '\\';
                        $i += 2;
                        continue;
                    }
                    $nom = $groupe . ltrim($y[1], '\\');
                    $court = $est($t[$i + 1] ?? null, \T_AS) ? $t[$i += 2][1] : substr((string) strrchr("\\$nom", '\\'), 1);
                    $alias[strtolower($court)] = $nom;
                    $cites[] = [$nom, $y[2]];
                }
            }
        } elseif ($est($x, \T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM)) {
            if (!$est($t[$i - 1] ?? null, \T_DOUBLE_COLON) && $est($t[$i + 1] ?? null, \T_STRING)) {
                $declares[] = ltrim("$ns\\" . $t[$i + 1][1], '\\');
            }
        } elseif ($est($x, \T_NAME_FULLY_QUALIFIED)) {
            $cites[] = [ltrim($x[1], '\\'), $x[2]];
        } elseif ($est($x, \T_NAME_QUALIFIED)) {
            $tete = strtolower((string) strstr($x[1], '\\', true));
            $cites[] = [isset($alias[$tete]) ? $alias[$tete] . strstr($x[1], '\\') : ltrim("$ns\\$x[1]", '\\'), $x[2]];
        } elseif ($est($x, \T_CONSTANT_ENCAPSED_STRING)
            && preg_match('/^\\\\?(App(?:\\\\[A-Z]\w*)+)$/', str_replace('\\\\', '\\', substr($x[1], 1, -1)), $m)) {
            $cites[] = [$m[1], $x[2]];
        }
    }

    return [array_values(array_filter($cites, static fn (array $c): bool => str_starts_with($c[0], 'App\\'))), $declares];
};
$lireYaml = static function (string $yaml): array {
    $noms = [];
    foreach (explode("\n", $yaml) as $i => $ligne) {
        preg_match_all('/(?<![\w\\\\])App(?:\\\\[A-Z]\w*)+/', preg_replace('/(^|\s)#.*$/', '', $ligne) ?? '', $m);
        foreach ($m[0] as $nom) {
            $noms[] = [$nom, $i + 1];
        }
    }

    return [$noms, []];
};

// ── LES TÉMOINS, AVANT TOUTE MESURE ───────────────────────────────────────────────────────────
$cites = static fn (array $lu): array => array_column($lu[0], 0);
$temoins = [
    ['voit un use', $cites($lirePhp('<?php use App\Disparu\Classe;')), ['App\Disparu\Classe']],
    ['voit un ::class absolu', $cites($lirePhp('<?php $x = \App\Disparu\Classe::class;')), ['App\Disparu\Classe']],
    ['résout un alias', $cites($lirePhp('<?php namespace App\Vente; use App\Offre as O; $x = O\Disparue::class;')), ['App\Offre', 'App\Offre\Disparue']],
    ['résout un attribut sans import', $cites($lirePhp('<?php namespace App\Vente; class X { #[Assert\Positive] public int $q; }')), ['App\Vente\Assert\Positive']],
    ['voit un use groupé', $cites($lirePhp('<?php use App\Offre\{Entity\Disparue, Service\Absent as A};')), ['App\Offre\Entity\Disparue', 'App\Offre\Service\Absent']],
    ['voit une chaîne qui n\'est qu\'un nom', $cites($lirePhp("<?php \$s = 'App\\Disparu\\Classe';")), ['App\Disparu\Classe']],
    ['lit une déclaration', $lirePhp('<?php namespace App\X; final class Y {} $a = new class {}; $b = Y::class;')[1], ['App\X\Y']],
    ['voit une clé de service YAML', $cites($lireYaml("    App\\Disparu\\Port: '@App\\Disparu\\Adaptateur'")), ['App\Disparu\Port', 'App\Disparu\Adaptateur']],
    ['ÉPARGNE un commentaire', $cites($lirePhp("<?php // App\\Disparu\\Classe\n/** @see \\App\\Disparu\\Classe */")), []],
    ['ÉPARGNE une phrase qui cite un nom', $cites($lirePhp("<?php \$s = 'voir App\\Disparu\\Classe';")), []],
    ['ÉPARGNE un use function, un trait et une closure', $cites($lirePhp('<?php namespace X; use function App\f; class C { use T; function g() { return function () use ($a) {}; } }')), []],
    ['ÉPARGNE un commentaire et une regex YAML', $cites($lireYaml("  # App\\Disparu\\Classe\n  motif: 'App\\d+'")), []],
];
foreach ($temoins as [$nom, $obtenu, $attendu]) {
    if ($obtenu !== $attendu) {
        echo "✗ Classes PHP disparues : le détecteur lui-même est faux.\n";
        echo "  témoin « {$nom} » : attendu " . json_encode($attendu) . ', obtenu ' . json_encode($obtenu) . "\n";
        echo "  Tant qu'il échoue, sa réponse sur le dépôt ne vaut rien — ni son vert, ni son rouge.\n";
        exit(1);
    }
}

// ── LA MESURE ─────────────────────────────────────────────────────────────────────────────────
$fichiers = glob("$app/*.php") ?: [];
foreach (['src', 'tests', 'config', 'migrations', 'bin', 'public'] as $sous) {
    if (is_dir("$app/$sous")) {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$app/$sous", \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->getExtension() === 'php' || ($sous === 'config' && $f->getExtension() === 'yaml')) {
                $fichiers[] = $f->getPathname();
            }
        }
    }
}
$citations = [];
$declarees = [];
$parFamille = [];
foreach ($fichiers as $f) {
    $source = @file_get_contents($f);
    if ($source === false) {
        echo "✗ Classes PHP disparues : « {$f} » est illisible. Un fichier sauté rend un vert qui ne vaut rien.\n";
        exit(1);
    }
    $yaml = str_ends_with($f, '.yaml');
    $famille = preg_replace('#^' . preg_quote($app, '#') . '/(\w+)/.*$#', '$1', $f) . ($yaml ? '.yaml' : '');
    $parFamille[$famille] = ($parFamille[$famille] ?? 0) + 1;
    [$cites, $declares] = $yaml ? $lireYaml($source) : $lirePhp($source);
    foreach ($cites as [$nom, $ligne]) {
        $citations[$nom][] = substr($f, \strlen(\dirname($app)) + 1) . ":$ligne";
    }
    foreach ($declares as $nom) {
        if (\in_array(substr($f, 0, -4), $chemins($nom), true)) {
            $declarees[$nom] = true;
        }
    }
}
// La panne de #30 était une collecte qui ne lisait pas ce qu'on croyait : chaque famille doit avoir été lue.
foreach (['src', 'tests', 'config.yaml', 'migrations'] as $famille) {
    if (($parFamille[$famille] ?? 0) === 0) {
        echo "✗ Classes PHP disparues : aucun fichier lu dans « {$famille} ». C'est la mesure qui est cassée.\n";
        exit(1);
    }
}

$orphelins = array_filter($citations, static fn (array $l, string $nom): bool => !isset($declarees[$nom])
    && array_filter($chemins($nom), 'is_dir') === [] && $chemins($nom) !== [], \ARRAY_FILTER_USE_BOTH);
if ($orphelins !== []) {
    ksort($orphelins);
    echo '✗ Classes PHP disparues : ' . \count($orphelins) . " nom(s) cité(s) qu'aucun fichier ne déclare.\n";
    foreach ($orphelins as $nom => $lieux) {
        echo "  · {$nom}\n      " . implode(', ', \array_slice($lieux, 0, 4)) . (\count($lieux) > 4 ? ' …(' . \count($lieux) . ')' : '') . "\n";
    }
    echo "\n  PHP ne dira rien avant d'exécuter la ligne, et jamais pour un attribut. Remettez la classe, ou\n";
    echo "  retirez-la de ces fichiers ; un attribut sans `use` se résout dans l'espace de noms du fichier.\n";
    exit(1);
}

echo '✓ Classes PHP disparues : ' . array_sum(array_map('count', $citations)) . ' citations App\… dans ' . \count($fichiers) . " fichiers, toutes déclarées.\n";
echo '  (' . \count($temoins) . " témoins passés, dont 4 qui prouvent ce que le détecteur épargne.)\n";
exit(0);
