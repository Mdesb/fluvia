<?php

declare(strict_types=1);

/**
 * Garde-fou n°28 — l'extension filtre sur un nom de champ que l'entité ne porte pas.
 *
 * ── LE DÉFAUT, TROUVÉ PAR `allaccess-c2` EN APPLIQUANT UNE AUTRE RÈGLE ─────────────────────────
 *
 * D5 exige des identifiants anglais dans tout fichier neuf. `c2` a donc nommé `establishment` le
 * champ d'établissement d'une entité neuve du recouvrement. Or `PerimetreRecouvrementExtension`
 * filtre sur `IDENTITY(%s.etablissement)`, écrit en dur, en français.
 *
 * Doctrine ne lève rien : la clause porte sur un champ que l'entité ne déclare pas… et la requête
 * **échoue en erreur** dans le meilleur des cas, ou — si l'entité n'atteint jamais ce chemin parce
 * qu'elle n'est pas dans la liste de l'extension — **n'est jamais filtrée du tout**.
 *
 *   > ⚠ Une fuite de cloisonnement ne produit pas d'erreur : elle produit des lignes en trop.
 *
 * ── DEUX RÈGLES DU DÉPÔT SE CONTREDISENT SUR CE NOM PRÉCIS, ET LA SÉCURITÉ DOIT GAGNER ────────
 *
 * D5 dit « anglais ». Le cloisonnement dit « le nom que l'extension attend ». Tant que les
 * extensions historiques écrivent `etablissement` en dur, une entité neuve qui suit D5 sort du
 * périmètre. Ce garde-fou ne tranche pas le conflit : il **rend l'écart impossible à commettre
 * silencieusement**, ce qui laisse le choix ouvert (renommer l'entité, ou généraliser l'extension)
 * mais interdit de ne rien faire sans le savoir.
 *
 * ── CE QU'IL COMPARE ────────────────────────────────────────────────────────────────────────────
 *
 * Pour chaque extension de périmètre : les noms de champ sur lesquels elle filtre, et les entités
 * qu'elle nomme. Une entité nommée par l'extension qui porte une relation `Etablissement` sous un
 * autre nom que ceux filtrés est signalée.
 *
 * ⚠ IL NE PROUVE PAS L'ABSENCE DE FUITE. Il compare deux déclarations ; une extension qui construit
 * son nom de champ dynamiquement, ou un provider écrit à la main, lui échappent — c'est le défaut
 * déjà connu des « providers sur mesure ». Ce contrôle ferme une porte, pas toutes.
 */

const RACINE = __DIR__ . '/../app/src';

/** @return list<string> */
function fichiersPhp(string $racine): array
{
    $trouves = [];
    $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));
    /** @var SplFileInfo $entree */
    foreach ($iterateur as $entree) {
        if ($entree->isFile() && $entree->getExtension() === 'php') {
            $trouves[] = $entree->getPathname();
        }
    }
    sort($trouves);

    return $trouves;
}

if (!is_dir(RACINE)) {
    fwrite(STDERR, "Champ de cloisonnement : IGNORÉ — app/src absent. Ce contrôle ne dit RIEN ici.\n");
    exit(0);
}

$fichiers = fichiersPhp(RACINE);

// ── 1. Le nom de champ « établissement » que chaque entité déclare ──────────────────────────────
$champParEntite = [];
$entitesLues = 0;

foreach ($fichiers as $chemin) {
    $contenu = (string) file_get_contents($chemin);
    if (!str_contains($contenu, '#[ORM\Entity]')) {
        continue;
    }
    if (!preg_match('/namespace\s+([^;]+);/', $contenu, $ns) || !preg_match('/\bclass\s+(\w+)/', $contenu, $cl)) {
        continue;
    }
    ++$entitesLues;

    // Une relation vers Etablissement, quel que soit le nom de la propriété.
    if (preg_match_all('/targetEntity:\s*Etablissement::class[\s\S]{0,400}?private\s+\??\w+\s+\$(\w+)/', $contenu, $props)) {
        $champParEntite[trim($ns[1]) . '\\' . $cl[1]] = array_values(array_unique($props[1]));
    }
}

// ── 2. Ce que chaque extension filtre, et quelles entités elle nomme ────────────────────────────
$ecarts = [];
$extensionsLues = 0;

foreach ($fichiers as $chemin) {
    if (!str_contains(basename($chemin), 'Extension')) {
        continue;
    }
    $contenu = (string) file_get_contents($chemin);
    if (!str_contains($contenu, 'QueryCollectionExtension') && !str_contains($contenu, 'QueryItemExtension')) {
        continue;
    }
    ++$extensionsLues;

    // Les champs sur lesquels elle filtre — uniquement ceux qui parlent d'établissement.
    $champs = [];
    if (preg_match_all('/IDENTITY\(\s*(?:%s|\$\w+|\w+)\s*\.\s*(\w+)\s*\)/', $contenu, $m)) {
        $champs = array_merge($champs, $m[1]);
    }
    $champs = array_values(array_unique(array_filter($champs, static function (string $c): bool {
        $bas = strtolower($c);

        return str_contains($bas, 'etablissement') || str_contains($bas, 'establishment');
    })));

    if ($champs === []) {
        continue;
    }

    // Les entités qu'elle nomme, résolues via ses imports.
    preg_match_all('/^use\s+([^;]+);/m', $contenu, $uses);
    $imports = [];
    foreach ($uses[1] as $import) {
        $import = trim($import);
        $imports[substr($import, strrpos($import, '\\') + 1)] = $import;
    }

    // ⚠ LA CARTE PAR CLASSE FAIT AUTORITE, ET ELLE EXISTE DEJA DANS LE DEPOT.
    //
    // `ResidualScopeExtension` porte `LegalDocument::class => 'establishment'` : elle gere les deux
    // orthographes explicitement, classe par classe. Une premiere version de ce controle ne lisait
    // que les `IDENTITY()` du fichier et signalait ces entites a tort — un garde-fou qui refuse du
    // travail correct est pire que pas de garde-fou, parce qu'on finit par le contourner.
    $carte = [];
    if (preg_match_all("/(\\w+)::class\\s*=>\\s*['\"](\\w+)['\"]/", $contenu, $paires, PREG_SET_ORDER)) {
        foreach ($paires as $paire) {
            $carte[$paire[1]] = $paire[2];
        }
    }

    // ⚠ ON NE RETIENT QUE LES ENTITES QUE L'EXTENSION DECLARE CLOISONNER DIRECTEMENT.
    //
    // Une premiere version examinait toute classe NOMMEE dans le fichier, et signalait trois entites
    // a tort : `Client` (cloisonne par groupe), `OptionProduit` et `Region` (par d'autres chemins).
    // Un garde-fou qui refuse du travail correct est pire que pas de garde-fou : on finit par le
    // contourner, et il ne protege plus rien.
    //
    // Le bon predicat est ecrit dans le code, pas devine : `Classe::class => []` dans un tableau de
    // constante dit « cette entite porte elle-meme le champ ». Une chaine NON vide designe une autre
    // entite au bout d'une jointure — hors de portee, et signale comme tel plutot que conclu a tort.
    $directes = [];
    if (preg_match_all('/(\w+)::class\s*=>\s*\[\s*\]/', $contenu, $vides)) {
        $directes = array_values(array_unique($vides[1]));
    }

    foreach ($directes as $court) {
        $complet = $imports[$court] ?? null;
        if ($complet === null || !isset($champParEntite[$complet])) {
            continue;
        }

        $declares = $champParEntite[$complet];

        // La carte, si elle designe cette classe, remplace la liste globale du fichier.
        $attendus = isset($carte[$court]) ? [$carte[$court]] : $champs;

        // ⚠ LE PLURIEL EST UNE COLLECTION JOINTE, PAS UN ECART. `Produit` declare `etablissements`
        // (ManyToMany) la ou l'extension filtre `etablissement` : elle joint la collection. Exiger
        // le singulier demanderait un renommage qui casserait le mapping.
        $acceptes = $attendus;
        foreach ($attendus as $attendu) {
            $acceptes[] = $attendu . 's';
        }

        if (array_intersect($declares, $acceptes) !== []) {
            continue;
        }

        $ecarts[] = [
            'extension' => substr($chemin, strlen(RACINE) + 1),
            'entite' => $complet,
            'filtre' => $champs,
            'declare' => $declares,
        ];
    }
}

// ⚠ Un instrument qui ne lit rien rend zéro écart, ce qui ressemble à une victoire. On refuse de
// conclure d'un compteur vide — c'est la leçon des quatre faux zéros du 29/08.
if ($entitesLues === 0 || $extensionsLues === 0) {
    fwrite(STDERR, sprintf(
        "Champ de cloisonnement : ÉCHEC — instrument muet (%d entité(s), %d extension(s) lues).\n",
        $entitesLues,
        $extensionsLues
    ));
    exit(2);
}

if ($ecarts !== []) {
    fwrite(STDERR, sprintf("Champ de cloisonnement : %d écart(s).\n\n", count($ecarts)));
    foreach ($ecarts as $e) {
        fwrite(STDERR, sprintf(
            "    %s\n      nommée par : %s\n      l'extension filtre sur : %s\n      l'entité déclare      : %s\n\n",
            $e['entite'],
            $e['extension'],
            implode(', ', $e['filtre']),
            implode(', ', $e['declare'])
        ));
    }
    fwrite(STDERR, "⚠ L'extension filtre sur un champ que l'entité ne porte pas. Selon le chemin, la\n");
    fwrite(STDERR, "requête échoue — ou, pire, l'entité n'est jamais filtrée : une fuite de cloisonnement\n");
    fwrite(STDERR, "ne produit pas d'erreur, elle produit des lignes en trop.\n\n");
    fwrite(STDERR, "Deux issues, et c'est la sécurité qui tranche, pas D5 :\n");
    fwrite(STDERR, "  · nommer le champ de l'entité comme l'extension l'attend ;\n");
    fwrite(STDERR, "  · ou généraliser l'extension pour accepter les deux noms.\n");
    exit(1);
}

printf(
    "Champ de cloisonnement : OK — %d extension(s) et %d entité(s) lues, aucun écart de nom.\n",
    $extensionsLues,
    $entitesLues
);
exit(0);
