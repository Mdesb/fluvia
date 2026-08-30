<?php

declare(strict_types=1);

/**
 * Garde-fou n°30 — un filtre déclaré hors de `mapping.paths` est MUET, et la collection sort entière.
 *
 * ── LE DÉFAUT, TROUVÉ PAR `allaccess-c2` EN SUIVANT UNE PISTE QUI MENAIT AILLEURS ──────────────
 *
 * `AttributeFilterPass` ne parcourt que les dossiers listés dans `api_platform.mapping.paths`. Un
 * `#[ApiFilter]` déclaré ailleurs est **accepté en paramètre, documenté, et sans aucun effet**.
 *
 *     GET /api/bank_statement_lines?statementImport=<uuid inexistant>
 *     avant : 1 ligne      après : 0
 *
 * Neuf filtres de `Finance` étaient dans ce cas — factures fournisseurs, notes de frais, lignes de
 * relevé bancaire. La collection sortait **entière** à qui passait un filtre.
 *
 * ⚠ ET LE TEST EXISTANT NE POUVAIT PAS LE VOIR. Il filtrait puis lisait `member[0]`, sur une fixture
 * d'une seule ligne : un filtre inerte lui rendait la même. Il passait dans les deux cas — la famille
 * du zéro qui ne mesure rien, sous une autre forme.
 *
 * ── LA DIFFÉRENCE ENTRE UNE CONSIGNE ET UN CONTRÔLE ────────────────────────────────────────────
 *
 * L'en-tête d'`api_platform.yaml` dit la règle depuis le 29/08, en toutes lettres : « un dossier
 * absent ne casse pas la ressource, **il rend ses filtres muets** ». Elle y est écrite deux fois, et
 * quatre dossiers y échappaient quand même.
 *
 * Une consigne protège ceux qui l'ont lue au bon moment. Un contrôle protège les autres.
 *
 * ── ⚠ LE PRÉDICAT NE COMPTE QUE LES VRAIES DÉCLARATIONS ───────────────────────────────────────
 *
 * Une première mesure signalait six dossiers, dont `Platform/Filter` et
 * `Platform/DependencyInjection/Compiler` — mes propres fichiers, où `#[ApiFilter]` apparaît dans un
 * **docbloc** qui explique le mécanisme. Un garde-fou qui crie sur la documentation de ce qu'il
 * surveille serait désactivé le jour même.
 *
 * On exige donc l'attribut en début de ligne, hors commentaire.
 */

const RACINE = __DIR__ . '/../app';
const CONF = RACINE . '/config/packages/api_platform.yaml';

/** Une vraie déclaration : `#[ApiFilter` en début de ligne, jamais précédé d'une étoile de docbloc. */
const MOTIF_DECLARATION = '/^\s*#\[ApiFilter\b/m';

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

$src = RACINE . '/src';

if (!is_dir($src) || !is_file(CONF)) {
    fwrite(STDERR, "Filtres muets : IGNORÉ — app/src ou la configuration absente. Ce contrôle ne dit RIEN ici.\n");
    exit(0);
}

// ── Les dossiers que la configuration déclare ───────────────────────────────────────────────────
$conf = (string) file_get_contents(CONF);
preg_match_all('#%kernel\.project_dir%/src/([A-Za-z0-9_/]+)#', $conf, $trouves);
$declares = array_flip($trouves[1]);

// ── Les dossiers qui portent une vraie déclaration de filtre ────────────────────────────────────
$porteurs = [];
$fichiersLus = 0;

foreach (fichiersPhp($src) as $chemin) {
    ++$fichiersLus;
    $contenu = (string) file_get_contents($chemin);

    $n = preg_match_all(MOTIF_DECLARATION, $contenu);
    if ($n === 0) {
        continue;
    }

    $dossier = str_replace('\\', '/', substr(dirname($chemin), strlen($src) + 1));
    if ($dossier === '') {
        // Un fichier directement dans `src/` : hors convention, et sans dossier à déclarer.
        continue;
    }

    $porteurs[$dossier] = ($porteurs[$dossier] ?? 0) + $n;
}

// ⚠ Un instrument qui ne lit rien rend zéro, ce qui ressemble à une victoire. Ce dépôt déclare des
// centaines de filtres : refuser de conclure plutôt qu'annoncer un succès.
if ($fichiersLus === 0 || $porteurs === []) {
    fwrite(STDERR, sprintf(
        "Filtres muets : ÉCHEC — instrument muet (%d fichier(s) lus, %d dossier(s) porteurs).\n",
        $fichiersLus,
        count($porteurs)
    ));
    exit(2);
}

$muets = [];
foreach ($porteurs as $dossier => $combien) {
    if (!isset($declares[$dossier])) {
        $muets[$dossier] = $combien;
    }
}

if ($muets !== []) {
    ksort($muets);
    $total = array_sum($muets);

    fwrite(STDERR, sprintf(
        "Filtres muets : %d filtre(s) déclaré(s) dans %d dossier(s) hors de `mapping.paths`.\n\n",
        $total,
        count($muets)
    ));
    foreach ($muets as $dossier => $combien) {
        fwrite(STDERR, sprintf("    %-46s %d filtre(s)\n", $dossier, $combien));
    }
    fwrite(STDERR, "\n⚠ `AttributeFilterPass` ne parcourt QUE les dossiers listés. Un filtre déclaré\n");
    fwrite(STDERR, "ailleurs est accepté en paramètre, documenté, et SANS AUCUN EFFET : la collection\n");
    fwrite(STDERR, "sort entière à qui passe un filtre.\n\n");
    fwrite(STDERR, "Mesuré le 30/08 sur `bank_statement_lines` : 1 ligne avec un identifiant inexistant\n");
    fwrite(STDERR, "en paramètre, 0 après correction.\n\n");
    fwrite(STDERR, "Remède : ajouter le dossier à `mapping.paths` dans app/config/packages/api_platform.yaml.\n");
    exit(1);
}

printf(
    "Filtres muets : OK — %d dossier(s) portent une déclaration, tous dans `mapping.paths` (%d déclaré(s)).\n",
    count($porteurs),
    count($declares)
);
exit(0);
