<?php

declare(strict_types=1);

/**
 * QUELS CHEMINS LE FRONTAL APPELLE-T-IL QUI NE CORRESPONDENT À AUCUNE ROUTE ?
 *
 * ── LA CLASSE DE DÉFAUT, ET ELLE EST SILENCIEUSE PAR CONSTRUCTION ───────────────────────────────
 *
 * Une route qui n'existe pas rend un 404. Le `catch` de l'écran le transforme en liste vide. L'écran
 * affiche « aucune donnée ». **Personne ne le voit jamais.**
 *
 * Le cas fondateur est réel, relevé par `allaccess-b8` : le frontal demandait
 * `/api/abonnement_fitness`, la route s'écrit `abonnement_fitnesses`. L'écran Sport affichait
 * « aucun abonnement » depuis toujours — un vide qui ressemblait à une absence de données.
 *
 * ⚠ Éprouvé sur ce défaut-là, dans l'historique, et non sur un témoin fabriqué : la chaîne fautive
 * reprise telle quelle est bien signalée.
 *
 * ── LA TABLE DE ROUTES VIENT DE L'ARBRE EXAMINÉ, PAS D'UN SERVEUR ───────────────────────────────
 *
 * `debug:router` est la seule source qui dise ce que Symfony sert vraiment — un `grep` sur les
 * entités manque `routePrefix` et les chemins implicites, deux mécanismes qui ne laissent aucune
 * trace textuelle.
 *
 * Mais la faire produire par un conteneur qui TOURNE serait pire qu'un grep : `opcache.validate_
 * timestamps=0` fait servir à PHP le code d'avant tant que FPM n'a pas redémarré. Le 31/08, ça m'a
 * fait déclarer trois hypothèses réfutées alors qu'elles mesuraient toutes du code périmé.
 *
 * On l'exécute donc ICI, dans le processus qui contrôle, sur l'arbre qu'il contrôle. Vérifié : ça
 * fonctionne sans réseau et sans base.
 *
 * ── CE QUE CE CONTRÔLE NE VOIT PAS, ET IL FAUT LE SAVOIR ────────────────────────────────────────
 *
 * 1. Les chemins **concaténés** ne sont lus que jusqu'au premier morceau littéral. Quand ce morceau
 *    se termine par `/`, on le traite comme un PRÉFIXE : on vérifie qu'au moins une route commence
 *    ainsi. ⚠ Un chemin dont le préfixe existe mais dont la fin est fausse passe donc — c'est la
 *    plus forte affirmation qu'on puisse faire sur ce qu'on ne voit qu'à moitié.
 *
 * 2. Seuls les appels passant par `request(` sont vus. Un `fetch` écrit à la main lui échappe.
 *
 * ⚠ Dire ce qu'un contrôle ne couvre pas fait partie du contrôle : un vert dont on ignore la portée
 * se lit comme une garantie qu'il ne donne pas.
 */

$racine = \dirname(__DIR__);
$app = $racine . '/app';
$front = $racine . '/frontend/src';

// ── LA TABLE DE ROUTES, ET SON TÉMOIN ───────────────────────────────────────────────────────────
//
// ⚠ UN ZÉRO SE SOUPÇONNE. Une commande absente, un noyau qui ne démarre pas, un `2>/dev/null` de
// trop : tous rendent une liste vide, et une liste vide de routes ferait déclarer TOUS les appels
// orphelins. Le seuil est donc haut et volontairement grossier — on sait qu'il y a plus de mille
// routes ; en trouver moins de cinq cents veut dire qu'on n'a pas mesuré.
$sortie = [];
$code = 0;
// ⚠ LE CONTRÔLE PORTE SES PROPRES EXIGENCES, IL N'EN IMPOSE PAS À L'APPELANT.
//
// Les crochets invoquent `php bin/garde-fou-x.php` sans APP_ENV, sans DATABASE_URL et sans le
// fichier mémoire du projet. `debug:router` a besoin des trois : sans le premier le noyau ne
// démarre pas, sans le deuxième Symfony refuse de construire le conteneur, et sans le troisième il
// meurt à 128 Mo.
//
// On aurait pu changer les trois crochets. Un contrôle qui exige d'être appelé d'une façon
// particulière finit appelé autrement — celui-ci se suffit.
//
// La base est FACTICE et rien n'est interrogé : `debug:router` lit le conteneur de services, pas la
// base. Vérifié avec le réseau coupé.
$commande = sprintf(
    'cd %s && APP_ENV=%s DATABASE_URL=%s php -d memory_limit=512M bin/console debug:router 2>&1',
    escapeshellarg($app),
        // ⚠ `prod` ET PAS `test`. L'environnement de test charge le paquet de fixtures, une dependance
    // de DEVELOPPEMENT : le controle mourait sur toute machine ou `composer install --no-dev` est
    // passe — c'est-a-dire precisement celles ou il doit tourner.
    //
    // Et `prod` est plus juste de toute facon : c'est la table REELLEMENT SERVIE. `dev` et `test`
    // ajoutent des routes de profileur que le frontal n'appelle jamais.
    // ⚠ UN ENVIRONNEMENT DEDIE, NI `prod` NI `test`, ET LES DEUX RAISONS COMPTENT.
    //
    // `prod` ne reconstruit pas : `debug:router` y lit le conteneur compile du dernier deploiement.
    // Le controle a ainsi declare SEPT appels orphelins qui ne l'etaient pas — des routes recentes,
    // absentes du cache et bien presentes dans l'arbre. Un controle qui lit un cache mesure le
    // passe, et un garde-fou qui accuse a tort finit desactive.
    //
    // `test` charge le paquet de fixtures, une dependance de DEVELOPPEMENT : le controle mourait
    // partout ou `composer install --no-dev` est passe, c'est-a-dire la ou il doit tourner.
    //
    // `inspection` n'existe dans aucune configuration : Symfony batit donc un cache NEUF a chaque
    // passage, et `config/bundles.php` n'y active ni fixtures ni profileur (declares `dev`/`test`).
    // On lit l'arbre, sans dependance de dev, sans toucher au cache servi.
    escapeshellarg('inspection'),
    escapeshellarg(getenv('DATABASE_URL') ?: 'mysql://inutilise:inutilise@base-factice:3306/inutilise?serverVersion=11.4.2-MariaDB')
);

exec($commande, $sortie, $code);

$routes = [];
foreach ($sortie as $ligne) {
    if (preg_match('#\s(/[^\s]*)\s*$#', $ligne, $m) === 1) {
        $routes[] = normaliser($m[1]);
    }
}
$routes = array_values(array_unique($routes));

if ($code !== 0 || \count($routes) < 500) {
    fwrite(STDERR, "═════════════════════════════════════════════════════════════\n");
    fwrite(STDERR, "✗ INSTRUMENT MORT : la table de routes est inexploitable.\n\n");
    fwrite(STDERR, sprintf("  code de sortie : %d\n", $code));
    fwrite(STDERR, sprintf("  routes lues    : %d (moins de 500 = on n'a pas mesuré)\n\n", \count($routes)));
    fwrite(STDERR, "  Aucun chiffre ne vaut : une table vide ferait déclarer TOUS les appels\n");
    fwrite(STDERR, "  du frontal orphelins, ce qui est le contraire d'un contrôle.\n");
    fwrite(STDERR, "═════════════════════════════════════════════════════════════\n");
    exit(1);
}

$connues = array_flip($routes);

// ── LES TÉMOINS : DES APPELS DONT ON SAIT QU'ILS ABOUTISSENT ────────────────────────────────────
$temoins = ['/me', '/api/factures', '/api/passages'];
$manquants = array_values(array_filter($temoins, static fn (string $t): bool => !isset($connues[normaliser($t)])));

if ($manquants !== []) {
    fwrite(STDERR, "═════════════════════════════════════════════════════════════\n");
    fwrite(STDERR, "✗ INSTRUMENT MORT : un témoin connu-vrai ne ressort pas.\n\n");
    foreach ($manquants as $t) {
        fwrite(STDERR, sprintf("    %s\n", $t));
    }
    fwrite(STDERR, "\n  La normalisation ou la lecture des routes a changé. Aucun chiffre ne vaut.\n");
    fwrite(STDERR, "═════════════════════════════════════════════════════════════\n");
    exit(1);
}

// ── LES APPELS DU FRONTAL ───────────────────────────────────────────────────────────────────────
$orphelins = [];
$vus = [];
$total = [];

foreach (fichiers($front) as $fichier) {
    $lignes = file($fichier, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lignes as $i => $ligne) {
        if (preg_match_all('#request\(\s*[`\'"](/[^`\'"]+)[`\'"]#', $ligne, $ms) === 0) {
            continue;
        }

        foreach ($ms[1] as $chemin) {
            $n = normaliser($chemin);
            $total[$n] = true;

            if (isset($connues[$n]) || isset($vus[$n])) {
                continue;
            }

            // Chemin coupé par une concaténation : on ne juge que ce qu'on voit, en préfixe.
            if (str_ends_with($chemin, '/') && prefixeConnu($n, $routes)) {
                continue;
            }

            $vus[$n] = true;
            $orphelins[] = [substr($fichier, \strlen($front) + 1), $i + 1, $chemin];
        }
    }
}

printf("routes servies par l'arbre : %d\n", \count($routes));
printf("appels distincts du frontal : %d\n", \count($total));
printf("  sans route correspondante : %d\n", \count($orphelins));

if ($orphelins === []) {
    exit(0);
}

fwrite(STDERR, "\n═════════════════════════════════════════════════════════════\n");
fwrite(STDERR, "✗ Le frontal appelle des chemins qu'aucune route ne sert :\n\n");
foreach ($orphelins as [$fichier, $ligne, $chemin]) {
    fwrite(STDERR, sprintf("    %-52s %s:%d\n", $chemin, $fichier, $ligne));
}
fwrite(STDERR, "\n  Un 404 avalé par un `catch` s'affiche comme « aucune donnée » : ce défaut\n");
fwrite(STDERR, "  ne se voit jamais à l'écran. Vérifie l'orthographe du chemin — le cas\n");
fwrite(STDERR, "  fondateur était `abonnement_fitness` au lieu de `abonnement_fitnesses`.\n");
fwrite(STDERR, "═════════════════════════════════════════════════════════════\n");

exit(1);

/** Neutralise le format optionnel d'API Platform et tout segment variable. */
function normaliser(string $chemin): string
{
    $chemin = trim($chemin);
    $chemin = str_replace('.{_format}', '', $chemin);
    $chemin = preg_replace('#\$\{[^}]*\}#', '*', $chemin) ?? $chemin;
    $chemin = preg_replace('#\{[^}]*\}#', '*', $chemin) ?? $chemin;
    $chemin = preg_replace('#//+#', '/', $chemin) ?? $chemin;
    $chemin = rtrim($chemin, '/');

    $segments = array_map(
        static fn (string $s): string => $s !== '' && preg_match('#^[A-Za-z0-9_.\-]+$#', $s) !== 1 ? '*' : $s,
        explode('/', $chemin)
    );

    return implode('/', $segments);
}

/** @param list<string> $routes */
function prefixeConnu(string $prefixe, array $routes): bool
{
    foreach ($routes as $route) {
        if (str_starts_with($route, $prefixe . '/')) {
            return true;
        }
    }

    return false;
}

/** @return list<string> */
function fichiers(string $racine): array
{
    if (!is_dir($racine)) {
        return [];
    }

    $trouves = [];
    $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));

    foreach ($iterateur as $fichier) {
        $chemin = $fichier->getPathname();
        if (str_contains($chemin, '/node_modules/') || str_contains($chemin, '/dist/')) {
            continue;
        }
        if (preg_match('#\.(js|jsx)$#', $chemin) === 1) {
            $trouves[] = $chemin;
        }
    }

    sort($trouves);

    return $trouves;
}
