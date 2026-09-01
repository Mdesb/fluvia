<?php

declare(strict_types=1);

/**
 * Garde-fou n°35 — une entité RATTACHABLE absente de la liste blanche de son module.
 *
 * ── LE DÉFAUT, TROIS FOIS LA MÊME LISTE ─────────────────────────────────────────────────────────
 *
 * `PerimetreVenteExtension` cloisonne le module Vente par une **liste blanche** : `const CHEMINS`
 * nomme, classe par classe, l'entité et le chemin vers son établissement. Une entité neuve du module
 * qui porte bien un rattachement — un `pointDeVente`, un `etablissement` — mais que personne n'ajoute
 * à cette liste n'est pas filtrée : `isset(self::CHEMINS[$resourceClass])` rend `false`, l'extension
 * rend la main sans toucher la requête, et la collection sort **tous établissements confondus**.
 *
 * Trois fois le même oubli, sur la même liste : `CardRejection` et `DailyClosure` (ajoutées le 28/08),
 * puis `OperationScellee` le 31/08 — et celle-là exposait `payloadCanonique`, le contenu canonique de
 * chaque transaction scellée. Le commentaire de l'extension portait déjà la leçon :
 *
 *   > « une liste blanche ne protège que ce qu'on a pensé à y écrire, et son oubli ne se voit pas. »
 *
 * L'écrire n'a pas suffi. Ce garde-fou la mesure.
 *
 * ── CE QU'IL COMPARE ────────────────────────────────────────────────────────────────────────────
 *
 * Pour chaque module qui possède au moins une extension de périmètre à liste blanche :
 *   { entités du module portant une relation directe vers une ANCRE de rattachement }
 *     moins { entités que ses extensions nomment (`X::class` cité, n'importe où dans l'extension) }
 *     moins { entités couvertes AUTREMENT — namespace en bloc, interface de rattachement }.
 *
 * Ce qui reste est une entité rattachable que sa liste blanche a oubliée. C'est le complément exact
 * du garde-fou n°28 (qui vérifie le NOM du champ des entités déjà nommées) : n°28 regarde ce que la
 * liste contient, n°35 ce qu'elle aurait dû contenir.
 *
 * Les ANCRES sont les quatre niveaux de rattachement réellement employés ici, désignés par leur nom
 * PLEINEMENT QUALIFIÉ (vingt noms de classes sont partagés dans ce dépôt — comparer court, c'est
 * apparier deux classes différentes le jour où la seconde naît) :
 */
const ANCRES = [
    'App\\Organisation\\Entity\\Etablissement',
    'App\\Caisse\\Entity\\PointDeVente',
    'App\\Compta\\Entity\\ProfilExploitant',
    'App\\Organisation\\Entity\\Groupe',
];

/**
 * ⚠ IL NE PROUVE PAS L'ABSENCE DE FUITE. Il compare deux déclarations. Une entité cloisonnée par un
 * provider écrit à la main, ou par une table de relations qu'aucune ancre ne trahit, lui échappe —
 * comme au n°5 et au n°28. Ce contrôle ferme une porte de plus, pas toutes.
 *
 * ⚠ ET IL CRIERA SUR DU TRAVAIL CORRECT s'il n'a pas sa liste d'exclusions : un référentiel global
 * légitimement non cloisonné, une entité rattachée par une interface qu'il ne sait pas lire. D'où la
 * LIGNE DE BASE gelée + les EXCLUSIONS motivées ci-dessous. Un garde-fou qui refuse du travail correct
 * est pire que pas de garde-fou : on finit par le contourner.
 *
 * ── CALIBRATION (à rejouer si on doute qu'il voit encore) ───────────────────────────────────────
 *
 * Le témoin qui tient n'est pas « telle liste actuelle est vide », qui expire au premier correctif —
 * c'est « le détecteur ressort les oublis d'hier ». Vérifié le 01/09, script courant contre l'arbre
 * d'AVANT chaque correctif (worktree temporaire sur le commit parent) :
 *   · parent de `db20a777` (fix OperationScellee) → ressort `OperationScellee`, elle seule ;
 *   · parent de `24b6df68` (fix « les onze entités ») → ressort `CardRejection`, `DailyClosure`,
 *     `OperationScellee` + `ParametrePmvEtablissement` + `Recurrence`, toutes réellement corrigées depuis.
 *   · arbre actuel → 0. Les trois témoins ressortent avant, aucun après.
 */
const RACINE = __DIR__ . '/../app/src';
const LIGNE_DE_BASE = __DIR__ . '/liste-blanche-cloisonnement.ligne-de-base.json';

/** @return list<string> */
function fichiersPhp(string $racine): array
{
    $trouves = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));
    /** @var SplFileInfo $e */
    foreach ($it as $e) {
        if ($e->isFile() && $e->getExtension() === 'php') {
            $trouves[] = $e->getPathname();
        }
    }
    sort($trouves);

    return $trouves;
}

/**
 * Les `use` d'un fichier, indexés par nom court, plus la classe du fichier elle-même.
 *
 * @return array<string, string>
 */
function imports(string $contenu, string $namespace, string $classe): array
{
    preg_match_all('/^use\s+([^;]+);/m', $contenu, $m);
    $imports = [];
    foreach ($m[1] as $import) {
        $import = trim($import);
        // `use A\B\C as D;` — la clé est l'alias, pas le dernier segment.
        if (preg_match('/^(.+)\s+as\s+(\w+)$/i', $import, $alias)) {
            $imports[$alias[2]] = trim($alias[1]);

            continue;
        }
        $imports[substr($import, strrpos($import, '\\') + 1)] = $import;
    }
    $imports[$classe] = $namespace . '\\' . $classe;

    return $imports;
}

/** Le module d'un FQCN `App\<Module>\...` — le premier segment après `App\`. */
function module(string $fqcn): string
{
    $p = explode('\\', $fqcn);

    return $p[1] ?? '';
}

if (!is_dir(RACINE)) {
    fwrite(STDERR, "Liste blanche : IGNORÉ — app/src absent. Ce contrôle ne dit RIEN ici.\n");
    exit(0);
}

$fichiers = fichiersPhp(RACINE);

// ── 1. Les entités qui portent une relation DIRECTE vers une ancre ──────────────────────────────
/** @var array<string, list<string>> $rattachees FQCN entité => ancres portées */
$rattachees = [];
/** @var array<string, list<string>> $interfaces FQCN entité => interfaces implémentées */
$interfaces = [];
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

    // ⚠ SEULE UNE ENTITÉ EXPOSÉE PEUT FUIR PAR COLLECTION. C'est la prémisse du n°5 : une entité
    // interne jamais servie par l'API ne rend aucune collection, donc sa liste blanche manquante ne
    // fuit rien. L'ancre en début de ligne évite de compter un `#[ApiResource]` cité en commentaire
    // (ce qui faisait signaler `DestinataireRapport`, dont le docblock dit qu'elle n'est PAS exposée).
    if (!preg_match('/^\s*#\[ApiResource/m', $contenu)) {
        continue;
    }

    $namespace = trim($ns[1]);
    $fqcn = $namespace . '\\' . $cl[1];
    $imports = imports($contenu, $namespace, $cl[1]);

    // Interfaces implémentées, résolues en FQCN (pour la couverture « par interface »).
    if (preg_match('/\bclass\s+\w+[^{]*\bimplements\s+([^{]+)\{/s', $contenu, $impl)) {
        foreach (preg_split('/\s*,\s*/', trim($impl[1])) as $nom) {
            $nom = trim($nom);
            if ($nom !== '') {
                $interfaces[$fqcn][] = $imports[$nom] ?? ($namespace . '\\' . $nom);
            }
        }
    }

    // Toute relation `targetEntity: X::class` suivie de sa propriété — même moteur que le n°28.
    if (preg_match_all(
        '/targetEntity:\s*(\w+)::class[\s\S]{0,400}?private\s+\??\\?[\w\\\\]*\s+\$(\w+)/',
        $contenu,
        $rels,
        PREG_SET_ORDER
    )) {
        foreach ($rels as $rel) {
            // Un nom non importé désigne la classe du même espace de noms (règle PHP, cf. n°28).
            $cibleFqcn = $imports[$rel[1]] ?? ($namespace . '\\' . $rel[1]);
            if (in_array($cibleFqcn, ANCRES, true)) {
                $rattachees[$fqcn][] = $cibleFqcn;
            }
        }
    }
}

// ── 2. Ce que chaque module ÉNUMÈRE, et comment il filtre ───────────────────────────────────────
/** @var array<string, true> $nommees FQCN entité citée par une extension */
$nommees = [];
/** @var array<string, true> $modulesAvecExtension module => a au moins une extension de périmètre */
$modulesAvecExtension = [];
/** @var array<string, true> $modulesBloc module filtré EN BLOC par préfixe de namespace */
$modulesBloc = [];
/** @var array<string, true> $interfacesFiltrees interface FQCN filtrée par une extension */
$interfacesFiltrees = [];
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

    if (!preg_match('/namespace\s+([^;]+);/', $contenu, $ns) || !preg_match('/\bclass\s+(\w+)/', $contenu, $cl)) {
        continue;
    }
    $namespace = trim($ns[1]);
    $moduleExtension = module($namespace . '\\' . $cl[1]);
    $modulesAvecExtension[$moduleExtension] = true;
    $imports = imports($contenu, $namespace, $cl[1]);

    // Toute entité que l'extension NOMME (`X::class`, n'importe où : carte, in_array, ===).
    if (preg_match_all('/(\w+)::class/', $contenu, $cites)) {
        foreach ($cites[1] as $court) {
            $fqcn = $imports[$court] ?? null;
            if ($fqcn !== null) {
                $nommees[$fqcn] = true;
            }
        }
    }

    // Filtrage EN BLOC par préfixe de namespace. `str_starts_with($resourceClass, …)` dans une
    // extension signifie toujours « si la ressource appartient à MON module, je la filtre » : le
    // préfixe est le namespace du module de l'extension, qu'il soit écrit en littéral
    // (`'App\\X\\'`) ou porté par une constante (`self::NAMESPACE_MODULE`, MarketingScopeExtension).
    // On crédite donc le module de l'extension dès qu'elle teste le resourceClass par préfixe —
    // se fier au seul littéral laissait passer les extensions à constante.
    if (preg_match('/str_starts_with\s*\(\s*\$\w+/', $contenu)) {
        $modulesBloc[$moduleExtension] = true;
    }
    // Cas rare : une extension bloque un AUTRE module par littéral explicite.
    if (preg_match_all('/str_starts_with\(\s*\$?\w+\s*,\s*[\'"]([\w\\\\]+)[\'"]/', $contenu, $prefixes)) {
        foreach ($prefixes[1] as $prefixe) {
            $modulesBloc[module(str_replace('\\\\', '\\', $prefixe) . '\\X')] = true;
        }
    }

    // Filtrage par INTERFACE : `instanceof XInterface` / `is_a(..., XInterface::class)`.
    if (preg_match_all('/(\w*Interface)::class|instanceof\s+(\w*Interface)\b/', $contenu, $ifaces, PREG_SET_ORDER)) {
        foreach ($ifaces as $if) {
            $court = $if[1] !== '' ? $if[1] : ($if[2] ?? '');
            if ($court !== '') {
                $interfacesFiltrees[$imports[$court] ?? $court] = true;
            }
        }
    }
}

// ── 3. Le reste : rattachée, module gardé par liste, mais ni nommée ni couverte autrement ───────
$oublis = [];
foreach ($rattachees as $fqcn => $ancres) {
    $mod = module($fqcn);

    // Le module ne se cloisonne pas par liste blanche du tout → hors sujet pour CE garde-fou
    // (le n°5 tient l'entité ingardable ; ici on ne parle que des listes incomplètes).
    if (!isset($modulesAvecExtension[$mod])) {
        continue;
    }
    // Module filtré en bloc : toutes ses entités sont couvertes sans être nommées.
    if (isset($modulesBloc[$mod])) {
        continue;
    }
    // Déjà nommée par une extension : la liste la connaît.
    if (isset($nommees[$fqcn])) {
        continue;
    }
    // Couverte par une interface de rattachement qu'une extension filtre.
    $couverteInterface = false;
    foreach ($interfaces[$fqcn] ?? [] as $if) {
        if (isset($interfacesFiltrees[$if])) {
            $couverteInterface = true;
            break;
        }
    }
    if ($couverteInterface) {
        continue;
    }

    $oublis[$fqcn] = array_values(array_unique($ancres));
}

// ── 4. Ligne de base + exclusions ───────────────────────────────────────────────────────────────
$base = ['gelees' => [], 'exclusions' => []];
if (is_file(LIGNE_DE_BASE)) {
    $decode = json_decode((string) file_get_contents(LIGNE_DE_BASE), true);
    if (is_array($decode)) {
        $base['gelees'] = $decode['gelees'] ?? [];
        $base['exclusions'] = array_keys($decode['exclusions'] ?? []);
    }
}

// Les options de ligne de commande.
$argv = $_SERVER['argv'] ?? [];
if (in_array('--liste', $argv, true)) {
    foreach ($oublis as $fqcn => $ancres) {
        printf("%s  (ancre: %s)\n", $fqcn, implode(', ', array_map('module', $ancres)));
    }
    exit(0);
}

// Instrument muet : on refuse de conclure d'un compteur vide (leçon des faux zéros du 29/08).
if ($entitesLues === 0 || $extensionsLues === 0) {
    fwrite(STDERR, sprintf(
        "Liste blanche : ÉCHEC — instrument muet (%d entité(s), %d extension(s) lues).\n",
        $entitesLues,
        $extensionsLues
    ));
    exit(2);
}

$exclus = array_flip($base['exclusions']);
$gelees = array_flip($base['gelees']);

$nouveaux = [];
foreach ($oublis as $fqcn => $ancres) {
    if (isset($exclus[$fqcn]) || isset($gelees[$fqcn])) {
        continue;
    }
    $nouveaux[$fqcn] = $ancres;
}

if (in_array('--geler', $argv, true)) {
    $sortie = [
        '_lisez_moi' => [
            'Ligne de base GELEE du garde-fou n°35 (liste blanche de cloisonnement).',
            'gelees   : entites rattachables absentes de la liste de leur module, TOLEREES le temps',
            '           de les ajouter a la liste blanche. Ce compteur ne doit que DECROITRE.',
            'exclusions: entites legitimement hors liste (referentiel global, cloisonnement autre),',
            '           chacune avec sa RAISON ECRITE — sans quoi le garde-fou crie sur du travail sain.',
        ],
        'gelees' => array_values(array_unique(array_merge(array_keys($gelees), array_keys($nouveaux)))),
        'exclusions' => $base['exclusions'] === [] ? new stdClass() : array_fill_keys($base['exclusions'], 'raison à écrire'),
    ];
    sort($sortie['gelees']);
    file_put_contents(LIGNE_DE_BASE, json_encode($sortie, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    printf("Ligne de base écrite : %d entrée(s) gelée(s).\n", count($sortie['gelees']));
    exit(0);
}

if ($nouveaux !== []) {
    fwrite(STDERR, sprintf("Liste blanche : %d entité(s) rattachable(s) NON cloisonnée(s) par leur liste.\n\n", count($nouveaux)));
    foreach ($nouveaux as $fqcn => $ancres) {
        fwrite(STDERR, sprintf("    %s\n      porte : %s, mais son module ne la nomme pas\n\n", $fqcn, implode(' + ', array_map('module', $ancres))));
    }
    fwrite(STDERR, "⚠ Sa liste blanche l'ignore → sa collection sort tous établissements confondus, sans erreur.\n\n");
    fwrite(STDERR, "Trois issues :\n");
    fwrite(STDERR, "  · l'ajouter à la liste (`X::class => '{root}.etablissement'`) — le correctif normal ;\n");
    fwrite(STDERR, "  · si elle est cloisonnée AUTREMENT (interface, table de relations), l'exclure AVEC sa raison ;\n");
    fwrite(STDERR, "  · en dette temporaire : `php bin/garde-fou-liste-blanche-cloisonnement.php --geler`.\n");
    exit(1);
}

printf(
    "Liste blanche : OK — %d entité(s) et %d extension(s) lues ; %d rattachable(s), %d gelée(s), %d exclue(s).\n",
    $entitesLues,
    $extensionsLues,
    count($oublis),
    count($base['gelees']),
    count($base['exclusions'])
);
exit(0);
