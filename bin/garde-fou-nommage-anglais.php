#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Garde-fou n°2 — nommage anglais (D5).
 *
 * Refuse un identifiant français dans un fichier **ajouté** par rapport à une révision de référence.
 *
 * **Pourquoi seulement les fichiers ajoutés.** L'existant de la billetterie est en français
 * (`Etablissement`, `Facturation`, `SessionCaisse`…) et le reste jusqu'au retrofit D5. Un contrôle
 * qui le viserait échouerait des centaines de fois au premier lancement : il serait désinstallé avant
 * d'avoir servi. D5 dit « tout nouveau code est en anglais dès maintenant » — c'est exactement ce
 * périmètre-là qui est contrôlé, ni plus, ni moins.
 *
 * **Ce qui est contrôlé, et ce qui ne l'est pas.** Seules les déclarations qui créent du vocabulaire
 * durable : noms de classes, cas d'énumération, noms de tables et de colonnes, codes de permission.
 * Ce sont celles qui coûtent cher à changer plus tard — une colonne renommée, c'est une migration ;
 * une permission renommée, c'est une matrice de droits à reprendre.
 *
 * Les **propriétés et méthodes sont hors périmètre en v1**, délibérément : un fichier neuf qui
 * consomme une classe historique nomme naturellement ses propriétés d'après elle
 * (`private SessionCaisse $sessionCaisse`), et les signaler reviendrait à punir l'interopérabilité
 * avec le legacy. Elles rentreront dans le périmètre quand le retrofit D5 commencera, c'est-à-dire
 * quand la friction disparaîtra.
 *
 * Usage :
 *   php bin/garde-fou-nommage-anglais.php                    # contre origin/main
 *   php bin/garde-fou-nommage-anglais.php --contre=origin/main
 *   php bin/garde-fou-nommage-anglais.php --fichiers=a.php,b.php   # contrôle ciblé, hors git
 */

const LEXIQUE = 'bin/nommage.lexique-francais.txt';
const REFERENCE_DEFAUT = 'origin/main';

/** Répertoires dont les fichiers ajoutés sont contrôlés. */
const SURVEILLES = ['app/src/', 'app/migrations/'];

/**
 * Déclarations qui créent du vocabulaire durable. L'ordre des groupes de capture importe :
 * le premier groupe non vide est l'identifiant.
 */
const DECLARATIONS = [
    'classe'     => '/\b(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/',
    'cas d\'enum' => '/\bcase\s+(\w+)\s*(?:=|;)/',
    'table'      => '/#\[ORM\\\\Table\s*\([^)]*name:\s*[\'"](\w+)[\'"]/',
    'colonne'    => '/#\[ORM\\\\Column\s*\([^)]*name:\s*[\'"](\w+)[\'"]/',
    'permission' => '/is_granted\s*\(\s*[\'"]PERM[\'"]\s*,\s*[\'"]([\w.]+)[\'"]/',
];

// ------------------------------------------------------------------ utilitaires

/** @return list<string> */
function chargerLexique(string $chemin): array
{
    if (!is_file($chemin)) {
        fwrite(STDERR, sprintf("Lexique introuvable : %s\n", $chemin));
        exit(2);
    }

    $jetons = [];
    foreach (file($chemin, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $ligne) {
        $ligne = trim($ligne);
        if ($ligne !== '' && !str_starts_with($ligne, '#')) {
            $jetons[] = mb_strtolower($ligne);
        }
    }

    return $jetons;
}

/**
 * Découpe un identifiant en jetons : camelCase, PascalCase, snake_case, et points des permissions.
 *
 * @return list<string>
 */
function decouper(string $identifiant): array
{
    $espace = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $identifiant) ?? $identifiant;
    $espace = str_replace(['_', '.', '-'], ' ', $espace);

    $jetons = [];
    foreach (preg_split('/\s+/', $espace) ?: [] as $morceau) {
        if (mb_strlen($morceau) > 2) {
            $jetons[] = mb_strtolower($morceau);
        }
    }

    return $jetons;
}

function ligneDe(string $source, int $decalage): int
{
    return substr_count(substr($source, 0, $decalage), "\n") + 1;
}

/** @return list<string> chemins ajoutés par rapport à la référence */
function fichiersAjoutes(string $ref): array
{
    exec(sprintf('git rev-parse --verify %s 2>/dev/null', escapeshellarg($ref)), $rien, $code);

    if ($code !== 0) {
        fwrite(STDERR, sprintf(
            "\n=== ERREUR — référence « %s » non résoluble ===\n"
            . "  Le garde-fou ne peut pas savoir ce qui est nouveau, donc il ne peut rien contrôler.\n"
            . "  Refus de rendre un vert qui ne veut rien dire.\n\n"
            . "  Causes habituelles : `git` absent, révision inconnue (un `git fetch` manque ?), ou\n"
            . "  dépôt hors de portée — un worktree monté sans son répertoire `.git`, typiquement.\n\n",
            $ref
        ));
        exit(2);
    }

    $sortie = [];
    exec(
        sprintf('git diff --diff-filter=A --name-only %s...HEAD 2>/dev/null', escapeshellarg($ref)),
        $sortie
    );

    return array_values(array_filter($sortie, static function (string $chemin): bool {
        if (!str_ends_with($chemin, '.php')) {
            return false;
        }
        foreach (SURVEILLES as $prefixe) {
            if (str_starts_with($chemin, $prefixe)) {
                return true;
            }
        }

        return false;
    }));
}

// ------------------------------------------------------------------- contrôle

/**
 * @param list<string> $lexique
 * @return list<array{fichier: string, ligne: int, genre: string, identifiant: string, jetons: list<string>}>
 */
function analyser(string $fichier, array $lexique): array
{
    $source = @file_get_contents($fichier);
    if ($source === false) {
        return [];
    }

    $trouvailles = [];

    foreach (DECLARATIONS as $genre => $motif) {
        if (preg_match_all($motif, $source, $correspondances, PREG_OFFSET_CAPTURE) === false) {
            continue;
        }

        foreach ($correspondances[1] as $capture) {
            [$identifiant, $decalage] = $capture;

            $fautifs = array_values(array_intersect(decouper($identifiant), $lexique));

            // Un accent dans un identifiant technique ne laisse aucun doute.
            if (preg_match('/[À-ÿ]/u', $identifiant) === 1) {
                $fautifs[] = '(accent)';
            }

            if ($fautifs !== []) {
                $trouvailles[] = [
                    'fichier' => $fichier,
                    'ligne' => ligneDe($source, (int) $decalage),
                    'genre' => $genre,
                    'identifiant' => $identifiant,
                    'jetons' => array_values(array_unique($fautifs)),
                ];
            }
        }
    }

    return $trouvailles;
}

// ----------------------------------------------------------------------- main

$options = array_slice($argv, 1);
$reference = REFERENCE_DEFAUT;
$fichiers = null;

foreach ($options as $option) {
    if (str_starts_with($option, '--contre=')) {
        $reference = substr($option, strlen('--contre='));
    } elseif (str_starts_with($option, '--fichiers=')) {
        $fichiers = array_values(array_filter(explode(',', substr($option, strlen('--fichiers=')))));
    }
}

$lexique = chargerLexique(LEXIQUE);
$cibles = $fichiers ?? fichiersAjoutes($reference);

if ($cibles === []) {
    echo sprintf("Nommage anglais : OK — aucun fichier ajouté à contrôler (référence %s).\n", $reference);
    exit(0);
}

$trouvailles = [];
foreach ($cibles as $cible) {
    $trouvailles = [...$trouvailles, ...analyser($cible, $lexique)];
}

if ($trouvailles === []) {
    echo sprintf(
        "Nommage anglais : OK — %d fichier(s) ajouté(s) contrôlé(s), aucun identifiant français.\n",
        count($cibles)
    );
    exit(0);
}

echo "\n=== ÉCHEC — identifiant français dans un fichier nouvellement ajouté (D5) ===\n\n";

$parFichier = [];
foreach ($trouvailles as $t) {
    $parFichier[$t['fichier']][] = $t;
}

foreach ($parFichier as $fichier => $lignes) {
    echo $fichier . "\n";
    foreach ($lignes as $l) {
        echo sprintf(
            "  ligne %-4d %-12s %-38s ← %s\n",
            $l['ligne'],
            $l['genre'],
            $l['identifiant'],
            implode(', ', $l['jetons'])
        );
    }
    echo "\n";
}

echo <<<'TXT'
D5 : tous les identifiants techniques sont en anglais — entités, tables, colonnes, valeurs d'énum,
champs d'API, codes de permission, noms d'événements. Les libellés vus par l'utilisateur ne sont
jamais en dur : ce sont des clés de traduction (français par défaut).

L'existant billetterie reste en français jusqu'au retrofit ; ce contrôle ne porte que sur les
fichiers AJOUTÉS. Un fichier neuf n'a donc aucune raison d'introduire du vocabulaire français.

Deux cas particuliers :
  - tu consommes une classe historique française (SessionCaisse, Etablissement…) : c'est permis,
    seules les *déclarations* sont contrôlées, pas les références ni les propriétés ;
  - le mot signalé est en réalité correct en anglais : c'est un défaut du lexique, pas de ton code.
    Retire-le de bin/nommage.lexique-francais.txt et dis-le dans MESSAGES.md — le lexique est fait
    pour être corrigé, il a été bâti sur un relevé de fréquence, pas sur une vérité révélée.

TXT;

exit(1);
