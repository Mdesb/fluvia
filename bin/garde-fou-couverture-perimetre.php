#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Garde-fou n°5 — couverture de périmètre en LECTURE.
 *
 * Refuse qu'une entité soit exposée par l'API sans qu'aucun mécanisme ne puisse la cloisonner.
 *
 * **Pourquoi il a fallu celui-ci.** Les quatre autres surveillent les *écritures* : un Processor qui
 * résout une entité depuis le corps de la requête sans vérifier le périmètre. Ils n'ont donc jamais
 * pu voir le cas trouvé le 23/08 — `GET /ecritures-comptables` renvoyait le grand livre de tous les
 * établissements. Il n'y avait aucun Processor fautif : c'est une **lecture**, servie par le provider
 * Doctrine standard, et le module `Compta` n'avait tout simplement aucune extension de périmètre.
 *
 * Un Processor mal gardé expose un enregistrement à la fois. Une extension manquante expose une
 * **collection entière**. C'est le plus rentable des deux à surveiller, et c'était l'angle mort.
 *
 * **Ce qui compte comme cloisonnable** — les deux mécanismes réellement employés ici :
 *   1. l'entité porte un champ `etablissement` (rattachement direct) ;
 *   2. une extension `Perimetre*` la nomme — rattachement indirect **déclaré**, comme
 *      `LigneCommandeAchat` filtrée via `commandeAchat`, ou `MouvementCaisse` via `sess.etablissement`.
 *
 * Rien d'autre ne filtre. Une entité hors de ces deux cas n'est pas mal gardée : elle est
 * **ingardable**, et le correctif n'est pas une ligne dans un Processor mais une extension.
 *
 * Usage :
 *   php bin/garde-fou-couverture-perimetre.php
 *   php bin/garde-fou-couverture-perimetre.php --liste
 *   php bin/garde-fou-couverture-perimetre.php --nettoyer
 *   php bin/garde-fou-couverture-perimetre.php --contre=origin/main
 */

const RACINE_SRC = 'app/src';
const LIGNE_DE_BASE = 'bin/couverture-perimetre.ligne-de-base.json';

/**
 * Les attributs doivent être en **début de ligne**. Sans cette ancre, un `#[ApiResource]` cité dans
 * un commentaire compte comme une exposition : c'est ce qui a fait signaler `DestinataireRapport`,
 * dont le docblock dit précisément qu'elle **n'est pas** exposée en ressource propre.
 */
const MOTIF_ENTITE = '/^\s*#\[ORM\\\\Entity/m';
const MOTIF_EXPOSEE = '/^\s*#\[ApiResource/m';
const MOTIF_CLASSE = '/(?:final\s+)?class\s+(\w+)/';

/**
 * Une extension peut filtrer sur une **interface** plutôt que sur des classes nommées —
 * `PerimetreReportingExtension` le fait via `RattachementNiveauInterface`, ce qui couvre d'un coup
 * toutes les entités Reporting sans qu'aucune n'apparaisse en `X::class`. Ne chercher que les
 * `::class` faisait passer sept entités correctement cloisonnées pour des trous.
 */
const MOTIF_INTERFACE = '/\b(\w+Interface)\b/';

/** Rattachement direct : une propriété `$etablissement`, ou une relation vers `Etablissement`. */
const MOTIF_ETABLISSEMENT = '/(?:private|protected|public)[^;\n]*\$etablissement\b|targetEntity:\s*Etablissement::class/';

// ------------------------------------------------------------------ analyse

/**
 * Tout ce qu'une extension de périmètre sait filtrer : les classes qu'elle nomme (`X::class`) et les
 * **interfaces** sur lesquelles elle s'appuie.
 *
 * @return array<string, true>
 */
function classesCouvertesParExtension(string $racine): array
{
    $couvertes = [];
    $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));

    /** @var SplFileInfo $fichier */
    foreach ($iterateur as $fichier) {
        if (!$fichier->isFile() || !str_ends_with($fichier->getFilename(), 'Extension.php')) {
            continue;
        }

        $source = (string) file_get_contents($fichier->getPathname());

        if (preg_match_all('/(\w+)::class/', $source, $noms) !== false) {
            foreach ($noms[1] as $nom) {
                $couvertes[$nom] = true;
            }
        }

        if (preg_match_all(MOTIF_INTERFACE, $source, $interfaces) !== false) {
            foreach ($interfaces[1] as $nom) {
                $couvertes[$nom] = true;
            }
        }
    }

    return $couvertes;
}

/**
 * L'entité implémente-t-elle une interface qu'une extension sait filtrer ?
 *
 * @param array<string, true> $couvertes
 */
function implementeUneInterfaceCouverte(string $source, array $couvertes): bool
{
    if (preg_match('/\bclass\s+\w+[^{]*\bimplements\b([^{]+)\{/s', $source, $clause) !== 1) {
        return false;
    }

    foreach (preg_split('/\s*,\s*/', trim($clause[1])) ?: [] as $nom) {
        $court = trim(substr(strrchr('\\' . $nom, '\\') ?: '', 1));
        if ($court !== '' && isset($couvertes[$court])) {
            return true;
        }
    }

    return false;
}

/** @return list<string> chemins des entités exposées et non cloisonnables, triés */
function violations(string $racine): array
{
    if (!is_dir($racine)) {
        fwrite(STDERR, sprintf("Répertoire source introuvable : %s\nLance ce script depuis la racine du dépôt.\n", $racine));
        exit(2);
    }

    $couvertes = classesCouvertesParExtension($racine);
    $resultat = [];

    $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));

    /** @var SplFileInfo $fichier */
    foreach ($iterateur as $fichier) {
        if (!$fichier->isFile() || $fichier->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($fichier->getPathname());

        if (preg_match(MOTIF_ENTITE, $source) !== 1 || preg_match(MOTIF_EXPOSEE, $source) !== 1) {
            continue;
        }
        if (preg_match(MOTIF_CLASSE, $source, $classe) !== 1) {
            continue;
        }
        if (preg_match(MOTIF_ETABLISSEMENT, $source) === 1
            || isset($couvertes[$classe[1]])
            || implementeUneInterfaceCouverte($source, $couvertes)) {
            continue;
        }

        $resultat[] = str_replace('\\', '/', substr($fichier->getPathname(), strlen($racine) + 1));
    }

    sort($resultat);

    return $resultat;
}

// ------------------------------------------------------------- ligne de base

function lireLigneDeBase(string $chemin): array
{
    if (!is_file($chemin)) {
        fwrite(STDERR, sprintf("Ligne de base absente : %s\n", $chemin));
        exit(2);
    }

    $donnees = json_decode((string) file_get_contents($chemin), true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($donnees) || !isset($donnees['scelle']['plafond'], $donnees['entrees'])) {
        fwrite(STDERR, "Ligne de base illisible : clés « scelle.plafond » et « entrees » attendues.\n");
        exit(2);
    }

    return $donnees;
}

// ------------------------------------------------------------------- main

$options = array_slice($argv, 1);
$actuelles = violations(RACINE_SRC);
$base = lireLigneDeBase(LIGNE_DE_BASE);
$entrees = $base['entrees'];
$plafond = (int) $base['scelle']['plafond'];

if (in_array('--liste', $options, true)) {
    echo sprintf("Entités exposées non cloisonnables : %d\n", count($actuelles));
    foreach ($actuelles as $v) {
        echo sprintf("  [%-13s] %s\n", isset($entrees[$v]) ? 'ligne de base' : 'NOUVELLE', $v);
    }
    exit(0);
}

if (in_array('--nettoyer', $options, true)) {
    $corrigees = array_values(array_diff(array_keys($entrees), $actuelles));

    if ($corrigees === []) {
        echo "Rien à nettoyer : toutes les entrées sont encore exposées sans cloisonnement.\n";
        exit(0);
    }

    foreach ($corrigees as $c) {
        unset($entrees[$c]);
    }
    $base['entrees'] = $entrees;
    $base['scelle']['plafond'] = count($entrees);
    file_put_contents(
        LIGNE_DE_BASE,
        json_encode($base, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );

    echo sprintf("%d entité(s) désormais cloisonnée(s), retirée(s) :\n", count($corrigees));
    foreach ($corrigees as $c) {
        echo '  - ' . $c . "\n";
    }
    echo sprintf("\nPlafond abaissé à %d. Commite %s.\n", count($entrees), LIGNE_DE_BASE);
    exit(0);
}

// Cliquet opposable : le plafond ne remonte pas par rapport à la branche cible.
$plafondReference = null;
foreach ($options as $option) {
    if (!str_starts_with($option, '--contre=')) {
        continue;
    }

    $ref = substr($option, strlen('--contre='));
    exec(sprintf('git rev-parse --verify %s 2>/dev/null', escapeshellarg($ref)), $rien, $code);

    if ($code !== 0) {
        fwrite(STDERR, sprintf(
            "\n=== ERREUR — référence « %s » non résoluble ===\n"
            . "  Le cliquet a été demandé et ne peut pas s'appliquer. Refus plutôt qu'un vert vide de sens.\n\n",
            $ref
        ));
        exit(2);
    }

    $brut = shell_exec(sprintf('git show %s:%s 2>/dev/null', escapeshellarg($ref), escapeshellarg(LIGNE_DE_BASE)));
    if (is_string($brut) && trim($brut) !== '') {
        $donneesRef = json_decode($brut, true);
        if (is_array($donneesRef) && isset($donneesRef['scelle']['plafond'])) {
            $plafondReference = (int) $donneesRef['scelle']['plafond'];
        }
    }
    break;
}

$nouvelles = array_values(array_diff($actuelles, array_keys($entrees)));
$corrigees = array_values(array_diff(array_keys($entrees), $actuelles));
$echec = false;

if (count($entrees) > $plafond) {
    $echec = true;
    echo sprintf("\n=== ÉCHEC — la ligne de base a grossi (%d pour un plafond de %d) ===\n", count($entrees), $plafond);
}

if ($plafondReference !== null && $plafond > $plafondReference) {
    $echec = true;
    echo sprintf("\n=== ÉCHEC — plafond relevé : %d sur la référence, %d proposé ===\n", $plafondReference, $plafond);
}

if ($nouvelles !== []) {
    $echec = true;
    echo "\n=== ÉCHEC — entité exposée sans cloisonnement possible ===\n\n";
    foreach ($nouvelles as $v) {
        echo '  - ' . $v . "\n";
    }
    echo <<<'TXT'

Cette entité est exposée par `#[ApiResource]` mais rien ne peut la filtrer : ni champ
`etablissement`, ni extension `Perimetre*` qui la nomme. Sa collection est donc lisible
d'un établissement à l'autre.

Deux façons de la rendre cloisonnable :
  - lui donner un champ `etablissement` (+ migration), quand le rattachement est direct ;
  - ou l'ajouter à l'extension `Perimetre*` de son module, avec le chemin de jointure —
    `PerimetreVenteExtension` le fait pour `MouvementCaisse` via `sess.etablissement`.

Si l'entité est globale à dessein (référentiel partagé, donnée de groupe), ce n'est pas à la
ligne de base de l'absorber : dis-le dans MESSAGES.md et documente-le dans l'entité.

TXT;
}

if ($corrigees !== []) {
    echo sprintf("\nBonne nouvelle : %d entité(s) sont désormais cloisonnées.\n", count($corrigees));
    foreach ($corrigees as $c) {
        echo '  - ' . $c . "\n";
    }
    echo "\n  Retire-les : php bin/garde-fou-couverture-perimetre.php --nettoyer\n";
}

if ($echec) {
    echo "\n";
    exit(1);
}

echo sprintf(
    "Couverture de périmètre : OK — aucune nouvelle entité exposée sans cloisonnement. Dette gelée : %d, plafond %d.\n",
    count($entrees),
    $plafond
);
exit(0);
