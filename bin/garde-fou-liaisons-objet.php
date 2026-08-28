<?php

declare(strict_types=1);

/**
 * Garde-fou n°16 — lier un OBJET à un paramètre de requête sans dire son type (D58).
 *
 * ── LE DÉFAUT QU'IL PRÉVIENT ────────────────────────────────────────────────────────────────────
 *
 * Deux bugs le 28/08, même idiome, deux modules, aucun signalé par quoi que ce soit :
 *
 *   - le **solde de fidélité** ne bougeait jamais. `e.establishment = :etab` était lié à l'entité ;
 *     Doctrine passait son identifiant sans son type `uuid`, la requête ne trouvait aucune écriture,
 *     et le solde affichait les points gagnés sans jamais soustraire les dépenses ;
 *   - la **file d'attente Smart Flow** donnait le rang 1 à tout le monde. `COALESCE(MAX(rank), 0)`
 *     filtré de la même façon rendait 0 à chaque inscription. Le durcissement de cohérence qui avait
 *     ajouté ce filtre était juste dans son intention ; sa liaison de paramètre l'annulait.
 *
 * Mesuré, pas supposé — trois requêtes identiques sur la même donnée, le 28/08 :
 *
 *     parEtab = 0     parClient = 1     lesDeux = 0
 *
 * ── POURQUOI RIEN NE LE VOIT ────────────────────────────────────────────────────────────────────
 *
 * Aucune exception, aucun avertissement, aucune ligne de journal. La requête est valide ; elle compte
 * **zéro**. Et zéro se lit exactement comme un résultat.
 *
 *   > **Une comparaison mal typée ne produit pas d'erreur, elle produit un vide.**
 *
 * C'est la même famille que D58 sur les références libres, d'un cran plus profond : le piège ne vaut
 * pas que pour les colonnes `uuid` nues, il vaut aussi quand on passe l'ENTITÉ, parce que son
 * identifiant est lui-même d'un type personnalisé.
 *
 * ── LA RÈGLE ────────────────────────────────────────────────────────────────────────────────────
 *
 * Un paramètre lié à une variable **typée par une classe** doit porter son type Doctrine, ou être
 * réduit à son identifiant :
 *
 *     ->andWhere('IDENTITY(e.establishment) = :etab')
 *     ->setParameter('etab', $etablissement->getId(), 'uuid')
 *
 * Les dates et les énumérations rétro-typées sont hors sujet : Doctrine les convertit seul, et les
 * exiger typées produirait du bruit sans rien protéger.
 *
 * ── L'ÉCHAPPATOIRE ──────────────────────────────────────────────────────────────────────────────
 *
 *     @liaison-verifiee : <pourquoi cette liaison sans type est correcte>
 *
 * Greppable, datée, attribuable — la même porte que `@cloisonnement-verifie` et `@drop-voulu`.
 *
 *     grep -rn "@liaison-verifiee" app/src
 *
 * Usage :
 *   php bin/garde-fou-liaisons-objet.php
 *   php bin/garde-fou-liaisons-objet.php --nettoyer
 *   php bin/garde-fou-liaisons-objet.php --contre=origin/main
 */

const RACINE_SRC = 'app/src';
const LIGNE_DE_BASE = 'bin/liaisons-objet.ligne-de-base.json';

/** `->setParameter('nom', $variable)` — exactement deux arguments, valeur = variable nue. */
const MOTIF_LIAISON = "/setParameter\(\s*'([A-Za-z_][A-Za-z0-9_]*)'\s*,\s*(\\\$[A-Za-z_][A-Za-z0-9_]*)\s*\)/";

/** Une variable typée par une classe, dans une signature de méthode. */
const MOTIF_TYPEE = '/[(,]\s*\??\\\\?([A-Z][A-Za-z0-9_\\\\]*)\s+(\$[A-Za-z_][A-Za-z0-9_]*)/';

/** Une variable qui reçoit le résultat d'une résolution d'entité. */
const MOTIF_RESOLUE = '/(\$[A-Za-z_][A-Za-z0-9_]*)\s*=\s*[^;\n]*(?:->find\(|->findOneBy\(|etablissementActif\(\))/';

const MOTIF_ANNOTATION = '/@liaison-verifiee\s*:\s*\S/';

/**
 * Ce que Doctrine sait lier sans aide : les dates, les scalaires, et les énumérations rétro-typées
 * dont la colonne déclare `enumType`.
 */
const INOFFENSIFS = [
    'DateTimeImmutable', 'DateTime', 'DateTimeInterface', 'DateInterval',
    'string', 'int', 'float', 'bool', 'array', 'iterable', 'mixed', 'self', 'static',
];

/** @return array<string, array{fichier: string, ligne: int, parametre: string, variable: string, type: string}> */
function liaisonsSansType(string $racine): array
{
    $trouvees = [];
    $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));

    /** @var SplFileInfo $fichier */
    foreach ($iterateur as $fichier) {
        if (!$fichier->isFile() || $fichier->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($fichier->getPathname());

        // Un fichier qui déclare avoir vérifié ses liaisons est dispensé : la déclaration EST le
        // contrôle, et elle porte un nom.
        if (preg_match(MOTIF_ANNOTATION, $source) === 1) {
            continue;
        }

        if (preg_match_all(MOTIF_LIAISON, $source, $liaisons, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
            continue;
        }

        $types = [];
        if (preg_match_all(MOTIF_TYPEE, $source, $signatures, PREG_SET_ORDER) !== 0) {
            foreach ($signatures as $signature) {
                $morceaux = explode('\\', $signature[1]);
                $classe = end($morceaux);
                if (!in_array($classe, INOFFENSIFS, true) && !enumeration($source, $classe)) {
                    $types[$signature[2]] = $classe;
                }
            }
        }
        if (preg_match_all(MOTIF_RESOLUE, $source, $resolutions, PREG_SET_ORDER) !== 0) {
            foreach ($resolutions as $resolution) {
                $types[$resolution[1]] ??= 'résolue';
            }
        }

        $relatif = substr($fichier->getPathname(), strlen($racine) + 1);

        foreach ($liaisons as $liaison) {
            $variable = $liaison[2][0];
            if (!isset($types[$variable])) {
                continue;
            }

            $ligne = substr_count(substr($source, 0, (int) $liaison[0][1]), "\n") + 1;
            $cle = str_replace('\\', '/', $relatif) . '::' . $liaison[1][0];
            $trouvees[$cle] = [
                'fichier' => str_replace('\\', '/', $relatif),
                'ligne' => $ligne,
                'parametre' => $liaison[1][0],
                'variable' => $variable,
                'type' => $types[$variable],
            ];
        }
    }

    ksort($trouvees);

    return $trouvees;
}

/**
 * Le type est-il une énumération ?
 *
 * Doctrine convertit seul une énumération rétro-typée vers une colonne `enumType`. Les exiger
 * typées produirait du bruit sans rien protéger — et un garde-fou bruyant finit ignoré.
 */
function enumeration(string $source, string $classe): bool
{
    return preg_match('/\b' . preg_quote($classe, '/') . '::[A-Z]/', $source) === 1;
}

// ------------------------------------------------------------------- main

$options = array_slice($argv, 1);
$liaisons = liaisonsSansType(RACINE_SRC);

if (in_array('--nettoyer', $options, true)) {
    $base = is_file(LIGNE_DE_BASE)
        ? json_decode((string) file_get_contents(LIGNE_DE_BASE), true, 512, JSON_THROW_ON_ERROR)
        : ['_lisez_moi' => [], 'scelle' => []];

    $base['_lisez_moi'] = [
        'Ligne de base GELEE du garde-fou n°16 — liaisons d\'objet sans type (D58).',
        '',
        'Chaque entree est un `setParameter(\'x\', $objet)` sans troisieme argument. Ce n\'est PAS',
        'une autorisation : tant que ce n\'est pas corrige, la requete peut compter zero sans rien',
        'signaler. Deux bugs du 28/08 venaient de la — solde de fidelite fige, file d\'attente sans',
        'ordre.',
        '',
        'Corriger : `IDENTITY(x.assoc) = :p` et `setParameter(\'p\', $objet->getId(), \'uuid\')`.',
        'Declarer : `@liaison-verifiee : <pourquoi c\'est correct>` dans le fichier.',
    ];
    $base['scelle'] = [
        'gelee_le' => date('Y-m-d'),
        'plafond' => count($liaisons),
        'reference' => 'claude-A, 28/08 : idiome trouve deux fois dans la meme nuit, dans deux modules.',
    ];
    $base['entrees'] = $liaisons;

    file_put_contents(
        LIGNE_DE_BASE,
        json_encode($base, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );
    echo sprintf("Ligne de base réécrite : %d liaison(s), plafond %d.\n", count($liaisons), count($liaisons));
    exit(0);
}

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
$nouveaux = array_values(array_diff(array_keys($liaisons), array_keys($base['entrees'])));

if ($nouveaux !== []) {
    $echec = true;
    echo "\n=== ÉCHEC — objet lié à un paramètre de requête sans son type (D58) ===\n\n";
    foreach ($nouveaux as $cle) {
        $l = $liaisons[$cle];
        echo sprintf("  %s:%d\n      :%s <- %s (%s)\n", $l['fichier'], $l['ligne'], $l['parametre'], $l['variable'], $l['type']);
    }
    echo "\n  Doctrine lie l'identifiant de l'objet SANS son type `uuid`. La requête reste valide et\n";
    echo "  compte ZÉRO — aucune exception, aucun avertissement. Zéro se lit comme un résultat.\n";
    echo "\n  Le 28/08, deux modules en sont morts en silence : le solde de fidélité ne bougeait\n";
    echo "  jamais, et la file d'attente Smart Flow donnait le rang 1 à tout le monde.\n";
    echo "\n  Corrige :\n\n";
    echo "        ->andWhere('IDENTITY(e.establishment) = :etab')\n";
    echo "        ->setParameter('etab', \$etablissement->getId(), 'uuid')\n";
    echo "\n  Ou déclare, si la liaison est correcte — une énumération, une date :\n\n";
    echo "        @liaison-verifiee : <pourquoi cette liaison sans type est correcte>\n\n";
    echo "  Pour auditer : grep -rn \"@liaison-verifiee\" app/src\n";
}

if (count($liaisons) > $plafond) {
    $echec = true;
    echo sprintf("\n=== ÉCHEC — la ligne de base a grossi (%d pour un plafond de %d) ===\n", count($liaisons), $plafond);
}

if ($plafondReference !== null && $plafond > $plafondReference) {
    $echec = true;
    echo sprintf("\n=== ÉCHEC — plafond relevé : %d sur la référence, %d proposé ===\n", $plafondReference, $plafond);
    echo "  Un cliquet ne remonte pas. Corrige la liaison, ou déclare-la.\n";
}

if ($echec) {
    exit(1);
}

echo sprintf(
    "Liaisons d'objet : OK — aucune nouvelle liaison sans type. Dette gelée : %d, plafond %d.\n",
    count($liaisons),
    $plafond
);
exit(0);
