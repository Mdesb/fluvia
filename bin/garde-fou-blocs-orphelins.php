<?php

declare(strict_types=1);

/*
 * GARDE-FOU — un bloc éditable qui n'est rendu nulle part.
 *
 * ── CE QU'IL EXISTE POUR ATTRAPER ──────────────────────────────────────────────────────────────
 *
 * Le 05/09, la refonte de la page d'accueil a remplacé deux blocs par des données : les quatre
 * cartes de modules sont devenues les rubriques du catalogue, et la liste des métiers vient
 * désormais de `MetierCatalog`. Les deux blocs sont restés DÉCLARÉS dans `SiteBlocks`.
 *
 * Conséquence : ils continuaient d'apparaître dans l'écran « Site vitrine », avec leur libellé et
 * leur aide, parfaitement modifiables. Maxime pouvait y passer un quart d'heure à soigner un texte
 * que plus aucune page ne lit. Rien ne l'aurait prévenu — ni erreur, ni page cassée, ni test rouge :
 * l'écran d'administration fait exactement ce qu'on lui a dit de faire.
 *
 * C'est la forme la plus coûteuse d'un défaut d'affichage : elle ne coûte rien à la machine et tout
 * à la personne.
 *
 * ── CE QU'IL VÉRIFIE ───────────────────────────────────────────────────────────────────────────
 *
 * Chaque clé déclarée dans `SiteBlocks` est-elle citée par au moins un gabarit ? La citation suffit
 * — on ne cherche pas à prouver que le bloc s'affiche vraiment (une condition Twig peut le masquer,
 * et c'est légitime : la bande de preuve ne s'affiche que si elle a du contenu). On vérifie
 * seulement qu'un gabarit le CONNAÎT.
 *
 * ⚠ LE SENS DE L'ERREUR EST CHOISI. Un bloc cité mais jamais affiché passe ; un bloc que personne
 * ne cite est refusé. Le premier est une décision de mise en page, le second est du travail qu'on
 * demande à quelqu'un pour rien.
 */

$racine = \dirname(__DIR__);
$declarations = $racine.'/app/src/Website/Service/SiteBlocks.php';
$gabarits = $racine.'/app/templates/website';

if (!is_file($declarations)) {
    fwrite(STDERR, "Blocs orphelins : `SiteBlocks.php` introuvable — rien à vérifier.\n");
    exit(1);
}

$source = (string) file_get_contents($declarations);

/*
 * Les clés déclarées. On lit `self::bloc('...')` plutôt que la liste rendue par le service : ce
 * contrôle doit tomber même si le service refuse de démarrer.
 */
preg_match_all("/self::bloc\(\s*'([a-z0-9._]+)'/", $source, $trouve);
$cles = array_values(array_unique($trouve[1]));

if ([] === $cles) {
    fwrite(STDERR, "Blocs orphelins : aucune clé lue dans SiteBlocks — le motif de lecture a dû changer.\n");
    exit(1);
}

// Les clés construites à la volée (pages de module, de métier) ne sont pas des littéraux : elles
// passent par `cleDeBloc()`. On les reconnaît à leur préfixe et on ne les exige pas ici.
$dynamiques = [];
foreach (['module.', 'metier.'] as $prefixe) {
    if (str_contains($source, "'".$prefixe)) {
        $dynamiques[] = $prefixe;
    }
}

$textes = '';
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($gabarits));
foreach ($rii as $fichier) {
    if ($fichier->isFile() && str_ends_with($fichier->getFilename(), '.twig')) {
        $textes .= (string) file_get_contents($fichier->getPathname());
    }
}

$orphelins = [];
foreach ($cles as $cle) {
    foreach ($dynamiques as $prefixe) {
        if (str_starts_with($cle, $prefixe)) {
            continue 2;
        }
    }

    if (!str_contains($textes, $cle)) {
        $orphelins[] = $cle;
    }
}

if ([] !== $orphelins) {
    fwrite(STDERR, sprintf(
        "✗ Blocs orphelins : %d bloc(s) éditable(s) qu'aucun gabarit ne lit.\n\n",
        \count($orphelins),
    ));

    foreach ($orphelins as $cle) {
        fwrite(STDERR, sprintf("  - %s\n", $cle));
    }

    fwrite(STDERR,
        "\nCes blocs apparaissent dans l'écran « Site vitrine », avec leur libellé et leur aide, et\n"
        ."ils sont modifiables. Le temps qu'on y passe est perdu : aucune page ne les affiche, et\n"
        ."rien ne le dit — ni erreur, ni page cassée, ni test rouge.\n\n"
        ."Soit un gabarit les rend, soit ils sortent de `SiteBlocks`. Les laisser déclarés est la\n"
        ."seule option qui coûte du travail à quelqu'un pour rien.\n",
    );

    exit(1);
}

printf(
    "✓ Blocs éditables : %d déclaré(s), tous cités par un gabarit.%s\n",
    \count($cles),
    [] === $dynamiques ? '' : sprintf(' (%d préfixe(s) construit(s) à la volée, hors de portée par construction.)', \count($dynamiques)),
);
