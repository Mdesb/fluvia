<?php

declare(strict_types=1);

/**
 * `vendor/` correspond-il au verrou ? — un contrôle sur la validité de la mesure, pas sur le code.
 *
 * ── POURQUOI CE CONTRÔLE NE REFUSE RIEN ─────────────────────────────────────────────────────────
 *
 * Un `vendor/` en retard n'est pas un défaut du code qu'on pousse : c'est un défaut de l'arbre
 * local. Le refuser bloquerait un commit parfaitement sain, et il serait contourné le jour même.
 *
 * Mais il ne se contente pas de faire perdre du temps à celui qui le subit — **il fait mentir les
 * autres contrôles**. PHPUnit échoue sur une classe absente, un garde-fou qui lit `vendor/` conclut
 * de travers, et l'erreur qui s'affiche ne parle jamais du `vendor/` : elle parle du code testé.
 * On cherche donc dans le code, parfois une demi-heure, un défaut qui n'y est pas.
 *
 * D'où : il prévient, en tête de course, avant que les autres ne parlent. Il ne sort jamais en
 * erreur.
 *
 * ── MESURÉ LE 01/09 ─────────────────────────────────────────────────────────────────────────────
 *
 * Trois des quatre arbres de la flotte — `wt/main` compris — n'avaient pas `symfony/rate-limiter`,
 * pourtant verrouillé. Le `CartRateLimiter` explosait à l'exécution chez eux, sur une erreur qui
 * désignait le code de la session, jamais l'installation.
 *
 * ── UNE PRÉCAUTION CONTRE LA FAUSSE ALERTE ──────────────────────────────────────────────────────
 *
 * `composer install --no-dev` est une installation légitime, pas un retard. Si tout ce qui manque
 * est de dev, on le dit comme tel — un contrôle qui crie au loup finit ignoré, et on perd alors
 * aussi ses vraies alertes.
 */

$racine = dirname(__DIR__);
$verrou = $racine . '/app/composer.lock';
$installe = $racine . '/app/vendor/composer/installed.json';

// ⚠ AVANT le bloc PHP, qui comporte plusieurs sorties anticipees.
//
//   Place apres elles, ce controle ne tournait pas des que `composer.lock` manquait — un
//   controle pose la ou il ne sera pas atteint, exactement ce que ce fichier sert a debusquer.
verifierLeFrontal($racine);

if (!is_file($verrou)) {
    exit(0); // pas de verrou : rien à comparer, ce n'est pas notre affaire.
}

if (!is_file($installe)) {
    echo "⚠ Dépendances : `app/vendor/` est absent ou incomplet — aucun paquet installé.\n";
    echo "  Les contrôles qui suivent et les tests parleront d'un code qui n'est pas en cause.\n";
    echo "  Installe-les : cd app && composer install\n";
    exit(0);
}

$lu = static function (string $chemin): array {
    $donnees = json_decode((string) file_get_contents($chemin), true);

    return is_array($donnees) ? $donnees : [];
};

$verrouille = [];
$deDev = [];
$contenuVerrou = $lu($verrou);
foreach ($contenuVerrou['packages'] ?? [] as $paquet) {
    $verrouille[$paquet['name']] = (string) $paquet['version'];
}
foreach ($contenuVerrou['packages-dev'] ?? [] as $paquet) {
    $verrouille[$paquet['name']] = (string) $paquet['version'];
    $deDev[$paquet['name']] = true;
}

$contenuInstalle = $lu($installe);
$paquetsInstalles = $contenuInstalle['packages'] ?? $contenuInstalle;
$present = [];
foreach ($paquetsInstalles as $paquet) {
    if (isset($paquet['name'], $paquet['version'])) {
        $present[$paquet['name']] = (string) $paquet['version'];
    }
}

if ($verrouille === [] || $present === []) {
    exit(0); // lecture douteuse : mieux vaut se taire que d'affoler à tort.
}

$manquants = array_diff_key($verrouille, $present);

$divergents = [];
foreach ($verrouille as $nom => $version) {
    if (isset($present[$nom]) && $present[$nom] !== $version) {
        $divergents[$nom] = $version . ' attendu, ' . $present[$nom] . ' installé';
    }
}

// --- `--no-dev` est une installation légitime : ne la présente pas comme un retard.
$manquantsHorsDev = array_diff_key($manquants, $deDev);
$sansDev = $manquants !== [] && $manquantsHorsDev === [];

if ($sansDev && $divergents === []) {
    printf(
        "✓ Dépendances installées : vendor/ suit le verrou, sans les paquets de dev (%d installé(s), %d de dev absent(s)).\n",
        count($present),
        count($manquants)
    );
    exit(0);
}

if ($manquants === [] && $divergents === []) {
    printf("✓ Dépendances installées : vendor/ correspond au verrou (%d paquet(s)).\n", count($present));
    exit(0);
}

echo "⚠ Dépendances : `app/vendor/` ne correspond pas à `composer.lock`.\n";
echo "\n";
echo "  Ce n'est pas un défaut de ton code, et rien n'est refusé pour autant. Mais tant que c'est\n";
echo "  vrai, une erreur peut s'afficher en désignant un code qui n'est pas en cause.\n";
echo "\n";

if ($manquantsHorsDev !== []) {
    printf("  Verrouillé(s) mais pas installé(s) — %d :\n", count($manquantsHorsDev));
    foreach (array_slice($manquantsHorsDev, 0, 10, true) as $nom => $version) {
        printf("    %-42s %s\n", $nom, $version);
    }
    if (count($manquantsHorsDev) > 10) {
        printf("    … et %d autre(s)\n", count($manquantsHorsDev) - 10);
    }
    echo "\n";
}

if ($divergents !== []) {
    printf("  Installé(s) dans une autre version — %d :\n", count($divergents));
    foreach (array_slice($divergents, 0, 6, true) as $nom => $ecart) {
        printf("    %-42s %s\n", $nom, $ecart);
    }
    if (count($divergents) > 6) {
        printf("    … et %d autre(s)\n", count($divergents) - 6);
    }
    echo "\n";
}

echo "  cd app && composer install\n";

exit(0);

/**
 * ── LE FRONTAL : UN CONTROLE DONT LA DEPENDANCE N'EST PAS DECLAREE NE TIENT QUE PAR ACCIDENT ────
 *
 * Les scripts de `frontend/scripts/` sont des CONTROLES. Quand l'un d'eux ne peut pas charger son
 * paquet, il ne casse rien — il s'eteint. Et s'il l'annonce correctement (« NON EXECUTE »), encore
 * faut-il que quelqu'un lise cette ligne au milieu de trente autres.
 *
 * Deux defauts distincts, et le premier est le plus grave :
 *
 *   · paquet IMPORTE mais NON DECLARE dans package.json — il n'est present que parce qu'un paquet
 *     de developpement le tire transitivement. `npm ci --omit=dev` le fait disparaitre, et le
 *     controle avec lui. C'est le cas de `@babel/parser`, mesure le 01/09.
 *   · paquet declare mais absent de `node_modules/` — installation simplement en retard.
 */
function verifierLeFrontal(string $racine): void
{
    $dossier = $racine . '/frontend';
    $manifeste = $dossier . '/package.json';
    $scripts = glob($dossier . '/scripts/*.mjs') ?: [];

    if (!is_file($manifeste) || $scripts === []) {
        return;
    }

    $paquet = json_decode((string) file_get_contents($manifeste), true);
    $declares = array_merge(
        array_keys($paquet['dependencies'] ?? []),
        array_keys($paquet['devDependencies'] ?? []),
    );

    $importes = [];
    foreach ($scripts as $script) {
        $texte = (string) file_get_contents($script);
        // ⚠ Deux resserrements, appris en produisant du bruit : `verifier-droits.mjs`
        //   manipule des chaines contenant « from » et « import », et un motif large les
        //   ramassait. Un analyseur qui trebuche sur du code qui parle d analyse.
        //
        //   1. l import statique doit etre en DEBUT DE LIGNE ; le dynamique, une forme complete ;
        //   2. le nom doit ressembler a un paquet npm. Cette seule regle elimine deja tout le
        //      bruit, et elle protege des formes que je n ai pas prevues.
        $motif = '/^\s*import\s[^\n]*?from\s*[\'"]([^\'"]+)[\'"]'
            . '|^\s*import\s*[\'"]([^\'"]+)[\'"]'
            . '|\bimport\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/m';
        preg_match_all($motif, $texte, $trouves, PREG_SET_ORDER);
        foreach ($trouves as $trouve) {
            $specificateur = '';
            foreach (array_slice($trouve, 1) as $capture) {
                if ($capture !== '') {
                    $specificateur = $capture;
                    break;
                }
            }
            if ($specificateur === ''
                || str_starts_with($specificateur, 'node:')
                || str_starts_with($specificateur, '.')
                || preg_match('#^@?[a-z0-9][\w.-]*(?:/[\w.-]+)*$#', $specificateur) !== 1) {
                continue;
            }
            // `@scope/nom/sous-chemin` → `@scope/nom` ; `nom/sous-chemin` → `nom`
            $morceaux = explode('/', $specificateur);
            $nom = str_starts_with($specificateur, '@')
                ? implode('/', array_slice($morceaux, 0, 2))
                : $morceaux[0];
            $importes[$nom][basename($script)] = true;
        }
    }

    if ($importes === []) {
        return;
    }

    $nonDeclares = [];
    $nonInstalles = [];
    foreach ($importes as $nom => $par) {
        if (!in_array($nom, $declares, true)) {
            $nonDeclares[$nom] = array_keys($par);
        } elseif (!is_dir($dossier . '/node_modules/' . $nom)) {
            $nonInstalles[$nom] = array_keys($par);
        }
    }

    if ($nonDeclares === [] && $nonInstalles === []) {
        printf(
            "✓ Dépendances du frontal : %d paquet(s) importé(s) par %d script(s) de contrôle, tous déclarés et installés.\n",
            count($importes),
            count($scripts)
        );

        return;
    }

    echo "⚠ Dépendances du frontal : un contrôle ne pourra pas s'exécuter.\n";
    echo "\n";

    if ($nonDeclares !== []) {
        echo "  IMPORTÉ mais NON DÉCLARÉ dans frontend/package.json — il n'est là que parce qu'un\n";
        echo "  paquet de développement le tire transitivement. `npm ci --omit=dev` l'efface, et le\n";
        echo "  contrôle s'éteint sans que rien ne soit cassé ni signalé :\n";
        foreach ($nonDeclares as $nom => $par) {
            printf("    %-28s importé par %s\n", $nom, implode(', ', $par));
        }
        echo "\n";
        echo "  Une dépendance non déclarée n'est pas une dépendance, c'est un accident qui dure.\n";
        echo "\n";
    }

    if ($nonInstalles !== []) {
        echo "  Déclaré mais absent de frontend/node_modules/ :\n";
        foreach ($nonInstalles as $nom => $par) {
            printf("    %-28s importé par %s\n", $nom, implode(', ', $par));
        }
        echo "\n";
        echo "    cd frontend && npm install\n";
    }
}
