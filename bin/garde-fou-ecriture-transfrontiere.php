<?php

declare(strict_types=1);

/**
 * Garde-fou n°8 — écriture qui traverse la frontière (D3/D8).
 *
 * **L'invariant.** Une entité que *rien ne permet de cloisonner* ne doit pas porter une relation
 * **écrivable** vers une entité qui, elle, l'est. Dans ce cas précis il n'y a de frontière ni en
 * lecture — aucune extension ne filtre le propriétaire — ni en écriture : le sérialiseur d'API
 * Platform désérialise l'IRI de la charge directement dans l'entité, sans que personne ne vérifie à
 * quel établissement appartient la cible.
 *
 * **Pourquoi les autres garde-fous ne le voient pas.** Le n°1 cherche un `find()`/`findOneBy()`
 * depuis l'entrée client **dans un Processor**. Ici il n'y a pas de Processor du tout et aucun code
 * de module ne résout quoi que ce soit : il n'y a littéralement rien à détecter par ce motif. Le n°5
 * voit bien que le propriétaire n'est pas cloisonné, mais il raisonne en **lecture** — « la
 * collection est lisible d'un établissement à l'autre » — et passe à côté de ce qu'on peut y écrire.
 *
 * **Le cas qui a fait naître la règle** : `Acces/Entity/SousReseau`, exposé en `Post`/`Patch` sous
 * `acces.gerer`, dont la `ManyToMany` vers `EspaceAcces` est dans le groupe d'écriture. Un
 * sous-réseau est un **accès fédéré** (US-L3-12) et `ValidationPassageHandler` s'en sert pour
 * autoriser un franchissement : y rattacher l'espace d'un autre établissement fédère les accès
 * par-dessus la frontière.
 *
 * **Périmètre volontairement étroit.** 74 relations écrivables du dépôt pointent vers une entité
 * cloisonnée ; la plupart sont légitimes, parce que leur propriétaire est lui-même cloisonné et qu'il
 * reste donc une frontière. Les signaler toutes ferait un contrôle qu'on ne sait pas satisfaire, donc
 * un contrôle désactivé. Celui-ci ne parle que du cas où il n'y a **aucune** frontière.
 *
 * **Couplage assumé avec le n°5.** La liste des entités non cloisonnées est lue dans la ligne de base
 * de la couverture de périmètre, plutôt que recalculée. Quand le n°5 se résorbe, l'entrée disparaît
 * d'ici aussi — c'est voulu : une entité devenue cloisonnable n'a plus ce problème.
 *
 * Usage :
 *   php bin/garde-fou-ecriture-transfrontiere.php
 *   php bin/garde-fou-ecriture-transfrontiere.php --nettoyer
 *   php bin/garde-fou-ecriture-transfrontiere.php --contre=origin/main
 */

const RACINE_SRC = 'app/src';
const BASE_COUVERTURE = 'bin/couverture-perimetre.ligne-de-base.json';
const LIGNE_DE_BASE = 'bin/ecriture-transfrontiere.ligne-de-base.json';

const MOTIF_ECRITURE = '/new (?:Post|Patch|Put)\s*\(/';
const MOTIF_DENORMALISATION = '/denormalizationContext:\s*\[\s*\'groups\'\s*=>\s*\[([^\]]*)\]/';
const MOTIF_TENANT = '/ManyToOne\(targetEntity:\s*Etablissement::class/';
/** Relation + son bloc d'attributs jusqu'aux Groups qui la qualifient. */
const MOTIF_RELATION = '/(ManyToOne|ManyToMany|OneToMany)\(targetEntity:\s*(\w+)::class[^\]]*?\][^;]*?#\[Groups\(\[([^\]]*)\]/s';

/**
 * @return array{0: array<string, string>, 1: list<string>} entités ORM (nom => source), et celles
 *                                                          qui portent un établissement
 */
function inventaire(string $racine): array
{
    $sources = [];
    $cloisonnees = [];

    $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));

    /** @var SplFileInfo $fichier */
    foreach ($iterateur as $fichier) {
        if (!$fichier->isFile() || $fichier->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($fichier->getPathname());
        // `#[ORM\\Entity` et non `ORM\\Entity` : la seconde forme attrape tout fichier qui
        // MENTIONNE une entité — processor, handler, repository — et gonflait l'inventaire de 257 à
        // 710. Elle ne produisait pas de faux positif ici, mais un contrôle qui ne sait pas dire ce
        // qu'il compte ne se relit pas.
        if (!str_contains($source, '#[ORM\\Entity')) {
            continue;
        }

        $nom = substr($fichier->getFilename(), 0, -4);
        $sources[$nom] = $source;

        if (preg_match(MOTIF_TENANT, $source) === 1) {
            $cloisonnees[] = $nom;
        }
    }

    return [$sources, $cloisonnees];
}

$options = array_slice($argv, 1);

if (!is_file(BASE_COUVERTURE)) {
    fwrite(STDERR, sprintf("Ligne de base de couverture introuvable : %s\n", BASE_COUVERTURE));
    exit(2);
}

$couverture = json_decode((string) file_get_contents(BASE_COUVERTURE), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($couverture) || !isset($couverture['entrees'])) {
    fwrite(STDERR, "Ligne de base de couverture illisible.\n");
    exit(2);
}

/** Entités que rien ne permet de cloisonner, d'après le garde-fou n°5. */
$nonCloisonnees = [];
foreach (array_keys($couverture['entrees']) as $chemin) {
    $base = basename((string) $chemin);
    $nonCloisonnees[] = substr($base, 0, -4);
}

[$sources, $cloisonnees] = inventaire(RACINE_SRC);

$constats = [];

foreach ($nonCloisonnees as $nom) {
    if (!isset($sources[$nom])) {
        continue;
    }

    $source = $sources[$nom];

    if (!str_contains($source, '#[ApiResource') || preg_match(MOTIF_ECRITURE, $source) !== 1) {
        continue; // pas d'écriture exposée : rien à traverser.
    }

    if (preg_match(MOTIF_DENORMALISATION, $source, $contexte) !== 1) {
        continue;
    }

    preg_match_all('/\'([^\']+)\'/', $contexte[1], $trouves);
    $groupesEcriture = $trouves[1];
    if ($groupesEcriture === []) {
        continue;
    }

    preg_match_all(MOTIF_RELATION, $source, $relations, PREG_SET_ORDER);

    foreach ($relations as $relation) {
        [, $type, $cible, $groupes] = $relation;

        if (!in_array($cible, $cloisonnees, true)) {
            continue;
        }

        $ecrivable = false;
        foreach ($groupesEcriture as $groupe) {
            if (str_contains($groupes, "'" . $groupe . "'")) {
                $ecrivable = true;
                break;
            }
        }

        if (!$ecrivable) {
            continue;
        }

        $constats[$nom . '::' . $cible] = ['entite' => $nom, 'relation' => $type, 'cible' => $cible];
    }
}

ksort($constats);

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
    $base['entrees'] = $constats;
    $base['scelle']['plafond'] = count($constats);
    file_put_contents(
        LIGNE_DE_BASE,
        json_encode($base, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );
    echo sprintf("Ligne de base réécrite : %d écriture(s) transfrontière, plafond %d.\n", count($constats), count($constats));
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
$nouveaux = array_values(array_diff(array_keys($constats), array_keys($base['entrees'])));

if ($nouveaux !== []) {
    $echec = true;
    echo "\n=== ÉCHEC — écriture qui traverse la frontière ===\n\n";
    foreach ($nouveaux as $cle) {
        $c = $constats[$cle];
        echo sprintf("  %s  --%s-->  %s\n", $c['entite'], $c['relation'], $c['cible']);
    }
    echo "\n  « " . $constats[$nouveaux[0]]['entite'] . " » n'est cloisonnée par rien, et sa relation est dans le groupe\n";
    echo "  d'écriture. Un appelant y rattache donc l'entité d'un AUTRE établissement, sans qu'aucun\n";
    echo "  code n'ait à résoudre quoi que ce soit : le sérialiseur désérialise l'IRI tel quel.\n\n";
    echo "  Deux issues : cloisonner le propriétaire (extension ou champ), ou retirer la relation du\n";
    echo "  groupe d'écriture et la faire poser par un Processor qui confronte la cible au périmètre.\n";
}

if (count($constats) > $plafond) {
    $echec = true;
    echo sprintf("\n=== ÉCHEC — la ligne de base a grossi (%d pour un plafond de %d) ===\n", count($constats), $plafond);
}

if ($plafondReference !== null && $plafond > $plafondReference) {
    $echec = true;
    echo sprintf("\n=== ÉCHEC — plafond relevé : %d sur la référence, %d proposé ===\n", $plafondReference, $plafond);
}

if ($echec) {
    exit(1);
}

$resorbes = count($base['entrees']) - count($constats);
echo sprintf(
    "Écriture transfrontière : OK — aucune nouvelle relation écrivable hors frontière. Gelées : %d, plafond %d.%s\n",
    count($constats),
    $plafond,
    $resorbes > 0 ? sprintf(' %d résorbée(s) — pense à --nettoyer.', $resorbes) : ''
);

exit(0);
