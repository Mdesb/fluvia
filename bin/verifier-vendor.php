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
