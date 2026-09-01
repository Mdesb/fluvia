#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Garde-fou n°35 — une entité RATTACHABLE exposée que l'extension de son module n'énumère pas.
 *
 * **Pourquoi celui-ci, alors que le n°5 existe déjà.** Le n°5 (couverture de périmètre) refuse une
 * entité exposée que RIEN ne peut cloisonner. Mais il gèle en ligne de base tout ce qu'il trouve, et
 * sa base mélange deux choses que rien ne distingue :
 *   - un vrai référentiel global (TVA légale, pays…) : légitimement lisible partout ;
 *   - une entité qui PORTE un rattachement (`etablissement`, `pointDeVente`, `profilExploitant`,
 *     `groupe`) mais que la liste blanche de son module a OUBLIÉE : un IDOR latent.
 *
 * `OperationScellee` était le second cas, gelée dans la base du n°5 comme si c'était le premier. Sa
 * collection — `payloadCanonique`, le contenu canonique de chaque transaction — se lisait d'un client
 * à l'autre. Mesuré : sur l'arbre `db20a777^`, le n°5 la listait bien, mais sous `[ligne de base]`,
 * donc sans faire échouer personne. Elle a fui trois jours après que la leçon eut été écrite dans
 * `PerimetreVenteExtension`, et deux fois avant elle (`CardRejection`, `DailyClosure`).
 *
 * **Le discriminant, c'est le rattachement.** Une entité qui porte une relation ManyToOne vers un des
 * quatre ancrages n'est pas un référentiel : elle appartient à un établissement, donc elle DOIT être
 * filtrée. Si elle est exposée et qu'aucune extension ne la nomme, ce n'est pas de la dette — c'est un
 * oubli de liste blanche. Ce garde-fou n'a donc pas à absorber une grande dette : sa base est petite,
 * et chaque entrée y est soit une exclusion nommée (cloisonnée autrement), soit un cas à corriger.
 *
 * **Ce qui compte comme couverture** (mêmes mécanismes réellement employés ici) :
 *   1. une extension `*Extension.php` NOMME la classe (`X::class`) — y compris depuis un autre module
 *      (les entités `Caisse` sont nommées par `PerimetreVenteExtension`) ;
 *   2. l'entité implémente une INTERFACE qu'une extension sait filtrer (`RattachementNiveauInterface`,
 *      filtrée par `PerimetreReportingExtension`) ;
 *   3. l'entité vit dans un NAMESPACE qu'une extension filtre en bloc (`MarketingScopeExtension`
 *      couvre `App\Marketing\Entity\` par `str_starts_with`, sans nommer chaque classe) ;
 *   4. une exclusion EXPLICITE et DATÉE dans la ligne de base, avec sa raison écrite — pour ce qui est
 *      cloisonné par un moyen qu'un lecteur de source ne voit pas (un State Provider dédié, une table
 *      de relations, un `EXISTS` monté ailleurs), ou pour un rattachement porté sans exposition réelle
 *      de collection tierce.
 *
 * Usage :
 *   php bin/garde-fou-entite-rattachable-hors-liste.php
 *   php bin/garde-fou-entite-rattachable-hors-liste.php --liste
 *   php bin/garde-fou-entite-rattachable-hors-liste.php --nettoyer
 *   php bin/garde-fou-entite-rattachable-hors-liste.php --contre=origin/main
 */

const RACINE_SRC = 'app/src';
const LIGNE_DE_BASE = 'bin/entite-rattachable.ligne-de-base.json';

/**
 * Les attributs doivent être en DÉBUT DE LIGNE — sans l'ancre, un `#[ApiResource]` cité dans un
 * docblock compterait comme une exposition. Leçon reprise mot pour mot du n°5.
 */
const MOTIF_ENTITE = '/^\s*#\[ORM\\\\Entity/m';
const MOTIF_EXPOSEE = '/^\s*#\[ApiResource/m';
const MOTIF_CLASSE = '/(?:final\s+)?class\s+(\w+)/';
const MOTIF_INTERFACE = '/\b(\w+Interface)\b/';

/**
 * Les quatre ancrages de rattachement du dépôt. Une entité qui pointe vers l'un d'eux appartient à un
 * établissement (directement ou par un saut) : elle est cloisonnable, donc elle DOIT l'être.
 *
 * @var list<string>
 */
const ANCRAGES = ['Etablissement', 'PointDeVente', 'ProfilExploitant', 'Groupe'];

/**
 * L'entité porte-t-elle une relation de rattachement vers un des ancrages ?
 *
 * Deux formes lues, les seules employées ici : l'association Doctrine (`targetEntity: X::class`) et le
 * type de la propriété (`private ?X $champ`). La seconde rattrape une association déclarée sur une
 * autre ligne que l'attribut. On EXCLUT l'entité qui EST elle-même un ancrage (elle ne se rattache pas
 * à elle-même : `PointDeVente` porte `etablissement`, mais c'est `Etablissement` via l'axe groupe qui
 * la cloisonne, pas une auto-référence).
 */
function porteUnRattachement(string $source, string $classe): bool
{
    if (in_array($classe, ANCRAGES, true)) {
        return false;
    }

    $alternance = implode('|', ANCRAGES);

    // #[ORM\ManyToOne(targetEntity: Etablissement::class)] — la forme canonique.
    if (preg_match('/targetEntity:\s*(?:' . $alternance . ')::class/', $source) === 1) {
        return true;
    }

    // Repli : propriété typée `?Etablissement $x` / `PointDeVente $x`, quand l'association n'est pas
    // sur la même ligne que le nom du type.
    if (preg_match('/(?:private|protected|public)\s+(?:readonly\s+)?\??(?:' . $alternance . ')\s+\$/', $source) === 1) {
        return true;
    }

    return false;
}

/**
 * Tout ce que les extensions savent filtrer : les classes qu'elles nomment (`X::class`) et les
 * interfaces sur lesquelles elles s'appuient. Portée GLOBALE : un module peut nommer l'entité d'un
 * autre (`PerimetreVenteExtension` nomme les entités `Caisse`).
 *
 * @return array<string, true>
 */
function couverturesDesExtensions(string $racine): array
{
    $couvertes = [];
    $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));

    /** @var SplFileInfo $fichier */
    foreach ($iterateur as $fichier) {
        if (!$fichier->isFile() || !str_ends_with($fichier->getFilename(), 'Extension.php')) {
            continue;
        }

        $source = (string) file_get_contents($fichier->getPathname());

        // Un module peut être couvert par PRÉFIXE de namespace (`MarketingScopeExtension`) plutôt que
        // classe par classe. Ne pas le lire ferait passer pour non énumérée une entité qui l'est.
        foreach (prefixesDuFichier($source) as $prefixe) {
            $couvertes['@' . $prefixe] = true;
        }

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
 * Les préfixes de namespace cités en littéral par une extension.
 *
 * Lecture sans expression régulière : les antislashs d'un littéral PHP en rendent une illisible, et
 * une regex fausse ici rendrait « couvert » sans que rien ne le signale — exactement le défaut qu'on
 * corrige. Repris du n°5.
 *
 * @return list<string>
 */
function prefixesDuFichier(string $source): array
{
    $prefixes = [];
    $position = 0;

    while (($debut = strpos($source, "'App\\\\", $position)) !== false) {
        $fin = strpos($source, "'", $debut + 1);
        if ($fin === false) {
            break;
        }

        $prefixes[] = str_replace('\\\\', '\\', substr($source, $debut + 1, $fin - $debut - 1));
        $position = $fin + 1;
    }

    return $prefixes;
}

/**
 * L'entité vit-elle dans un namespace qu'une extension filtre en bloc ?
 *
 * @param array<string, true> $couvertes
 */
function namespaceCouvert(string $source, array $couvertes): bool
{
    if (preg_match('/^namespace\s+([^;]+);/m', $source, $ns) !== 1) {
        return false;
    }

    foreach (array_keys($couvertes) as $cle) {
        if (str_starts_with($cle, '@') && str_starts_with($ns[1] . '\\', substr($cle, 1))) {
            return true;
        }
    }

    return false;
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

/**
 * @param array<string, mixed> $exclusions chemin => raison
 *
 * @return list<string> chemins des entités rattachables exposées et non énumérées, triés
 */
function violations(string $racine, array $exclusions): array
{
    if (!is_dir($racine)) {
        fwrite(STDERR, sprintf("Répertoire source introuvable : %s\nLance ce script depuis la racine du dépôt.\n", $racine));
        exit(2);
    }

    $couvertes = couverturesDesExtensions($racine);
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
        if (!porteUnRattachement($source, $classe[1])) {
            continue;
        }
        if (isset($couvertes[$classe[1]])
            || implementeUneInterfaceCouverte($source, $couvertes)
            || namespaceCouvert($source, $couvertes)) {
            continue;
        }

        $chemin = str_replace('\\', '/', substr($fichier->getPathname(), strlen($racine) + 1));
        if (isset($exclusions[$chemin])) {
            continue;
        }

        $resultat[] = $chemin;
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

    $donnees['exclusions'] = $donnees['exclusions'] ?? [];

    return $donnees;
}

// ------------------------------------------------------------------- main

$options = array_slice($argv, 1);
$base = lireLigneDeBase(LIGNE_DE_BASE);
$entrees = $base['entrees'];
$exclusions = $base['exclusions'];
$plafond = (int) $base['scelle']['plafond'];
$actuelles = violations(RACINE_SRC, $exclusions);

if (in_array('--liste', $options, true)) {
    echo sprintf("Entités rattachables exposées et non énumérées : %d\n", count($actuelles));
    foreach ($actuelles as $v) {
        echo sprintf("  [%-13s] %s\n", isset($entrees[$v]) ? 'ligne de base' : 'NOUVELLE', $v);
    }
    echo sprintf("\nExclusions nommées : %d\n", count($exclusions));
    foreach ($exclusions as $chemin => $raison) {
        echo sprintf("  - %s\n      %s\n", $chemin, $raison);
    }
    exit(0);
}

if (in_array('--nettoyer', $options, true)) {
    $corrigees = array_values(array_diff(array_keys($entrees), $actuelles));

    if ($corrigees === []) {
        echo "Rien à nettoyer : toutes les entrées sont encore rattachables et non énumérées.\n";
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

    echo sprintf("%d entité(s) désormais énumérée(s), retirée(s) :\n", count($corrigees));
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
    echo "\n"
        . "  Un cliquet ne monte pas. « --nettoyer » ne sert qu'à RÉSORBER un stock qui a déjà baissé,\n"
        . "  pas à absorber une hausse : il recalcule le plafond, et le contrôle contre la référence le\n"
        . "  refuserait aussitôt.\n"
        . "\n"
        . "  ⚠ La cause la plus fréquente n'est pas une faute : ta branche est EN RETARD sur la\n"
        . "  référence, et un plafond a baissé entre-temps. Commence par :\n"
        . "\n"
        . "      git fetch origin && git merge --no-edit origin/main\n";
}

if ($plafondReference !== null && $plafond > $plafondReference) {
    $echec = true;
    echo sprintf("\n=== ÉCHEC — plafond relevé : %d sur la référence, %d proposé ===\n", $plafondReference, $plafond);
    echo "\n"
        . "  Un cliquet ne monte pas. Si la hausse est délibérée, le plafond de référence se change sur\n"
        . "  « main », pas ici, et avec l'accord de l'intégrateur.\n";
}

if ($nouvelles !== []) {
    $echec = true;
    echo "\n=== ÉCHEC — entité rattachable exposée, absente de la liste blanche de son module ===\n\n";
    foreach ($nouvelles as $v) {
        echo '  - ' . $v . "\n";
    }
    echo <<<'TXT'

Cette entité porte une relation de rattachement (`etablissement`, `pointDeVente`,
`profilExploitant` ou `groupe`) ET elle est exposée par `#[ApiResource]`, mais aucune
extension de périmètre ne la nomme. Sa collection se lit donc d'un établissement à l'autre —
c'est le défaut exact de `OperationScellee` (le contenu canonique de chaque transaction).

L'issue n'est PAS de la geler ici : elle est rattachable, donc elle doit être filtrée.
  - ajoute-la à l'extension `Perimetre*` de son module, avec le chemin de jointure vers
    l'établissement — `PerimetreVenteExtension` le fait pour `MouvementCaisse` via
    `sess.etablissement`, et pour `OperationScellee` via un `EXISTS` sur le point de vente ;
  - ou, si elle est cloisonnée par un moyen qu'un lecteur de source ne voit pas (State Provider
    dédié, table de relations), déclare-la dans « exclusions » de la ligne de base AVEC SA RAISON
    ÉCRITE. Une exclusion sans raison est refusée en revue.

TXT;
}

if ($corrigees !== []) {
    echo sprintf("\nBonne nouvelle : %d entité(s) sont désormais énumérées.\n", count($corrigees));
    foreach ($corrigees as $c) {
        echo '  - ' . $c . "\n";
    }
    echo "\n  Retire-les : php bin/garde-fou-entite-rattachable-hors-liste.php --nettoyer\n";
}

if ($echec) {
    echo "\n";
    exit(1);
}

echo sprintf(
    "Entités rattachables hors liste : OK — aucune nouvelle. Dette gelée : %d, plafond %d. Exclusions nommées : %d.\n",
    count($entrees),
    $plafond,
    count($exclusions),
);
exit(0);
