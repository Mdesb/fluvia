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

/**
 * **Faux positif du lexique — la procédure, volontairement placée ici et pas dans le message d'échec.**
 *
 * Le lexique a été bâti sur un relevé de fréquence, pas sur une vérité révélée : il contient des mots
 * qui s'écrivent à l'identique dans les deux langues, et il en manque d'autres. Il est fait pour être
 * corrigé.
 *
 * **Mais il se corrige à deux.** Retirer un mot désarme le contrôle pour les neuf sessions, sur toutes
 * les familles de mots, et ce désarmement ne se voit nulle part ensuite. Retirer `facturation` pour
 * débloquer un fichier, c'est laisser passer tous les `facturation*` de la semaine suivante.
 *
 * Donc : retire le mot de `bin/nommage.lexique-francais.txt`, **et écris pourquoi dans `MESSAGES.md`**,
 * dans le même commit. Sans la seconde moitié, personne ne saura que le contrôle a rétréci.
 *
 * C'est D53 : le message d'échec dit ce qui est cassé et comment le réparer ; le contournement vit
 * dans la documentation. Qui le cherche le trouve, qui est pressé ne tombe pas dessus.
 */
const LEXIQUE = 'bin/nommage.lexique-francais.txt';

// Partage entre la detection et la recherche du meme code ailleurs dans le depot.
const MOTIF_CODE_PERMISSION = '/is_granted\s*\(\s*[\'"]PERM[\'"]\s*,\s*[\'"]([\w.]+)[\'"]/';
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
    'permission' => MOTIF_CODE_PERMISSION,
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

    $jusqua = $GLOBALS['jusqua'] ?? 'HEAD';
    $sortie = [];
    $code = 0;

    // Pas de `2>/dev/null` ici : une liste vide parce que git a échoué et une liste vide parce que
    // rien n'a été ajouté se ressemblent, et la première passerait pour un succès. On distingue.
    exec(
        sprintf(
            'git diff --diff-filter=A --name-only %s...%s 2>&1',
            escapeshellarg($ref),
            escapeshellarg($jusqua)
        ),
        $sortie,
        $code
    );

    if ($code !== 0) {
        fwrite(STDERR, sprintf(
            "\n=== ERREUR — `git diff %s...%s` a échoué (code %d) ===\n  %s\n\n"
            . "  Sans cette comparaison, le garde-fou ne sait pas ce qui est nouveau : il ne peut rien\n"
            . "  contrôler, donc il refuse plutôt que de rendre un vert qui ne veut rien dire.\n\n",
            $ref,
            $jusqua,
            $code,
            implode("\n  ", $sortie)
        ));
        exit(2);
    }

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
 * Un code de permission cité ailleurs dans le dépôt est une **référence**, pas une déclaration.
 *
 * **Le faux positif, et il a bloqué une session entière.** `claude-D` livrait `CommercialDocument`, qui
 * porte huit `is_granted('PERM', 'facturation.gerer')`. Le contrôle a compté huit fautes. Or ce code
 * n'est pas forgé là : il est **déclaré** dans `FacturationFixtures`, le catalogue du module, et cité
 * à l'identique par quatre autres fichiers.
 *
 * Écrire `billing.manage` à la place n'aurait rien renommé — ça aurait désigné une permission **absente
 * du catalogue**, et le voteur aurait refusé tout accès. Le garde-fou aurait donc produit un défaut de
 * droits en croyant corriger un défaut de nommage.
 *
 * **Ce fichier fait déjà cette distinction pour les classes** — « seules les déclarations sont
 * contrôlées, pas les références ». Elle n'avait simplement jamais été appliquée aux codes de
 * permission, où elle compte autant : renommer une permission, c'est une matrice de droits à reprendre,
 * et c'est précisément pour ça que ce genre est surveillé.
 *
 * Le contrôle garde tout son objet : un code français **réellement neuf** n'existe nulle part ailleurs,
 * donc il est toujours attrapé. Signalé et diagnostiqué par `claude-D`, qui a refusé pour la troisième
 * fois de cette semaine la porte de sortie que le message d'échec lui proposait (D53).
 */
function permissionDejaDeclaree(string $code, string $fichierAnalyse): bool
{
    /** @var array<string, bool>|null $connus */
    static $connus = null;

    if ($connus === null) {
        $connus = [];

        $dossier = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator('app/src', FilesystemIterator::SKIP_DOTS)
        );

        foreach ($dossier as $entree) {
            if (!$entree->isFile() || $entree->getExtension() !== 'php') {
                continue;
            }

            $chemin = $entree->getPathname();
            $source = @file_get_contents($chemin);

            if ($source === false) {
                continue;
            }

            // On retient d'ou vient chaque code : un code qui n'apparait QUE dans le fichier analyse
            // y est bel et bien forge, et doit rester signale.
            if (preg_match_all(MOTIF_CODE_PERMISSION, $source, $trouves) === false) {
                continue;
            }

            foreach ($trouves[1] as $trouve) {
                $connus[$trouve][$chemin] = true;
            }
        }
    }

    if (!isset($connus[$code])) {
        return false;
    }

    $reel = realpath($fichierAnalyse);

    foreach (array_keys($connus[$code]) as $ou) {
        if (realpath($ou) !== $reel) {
            return true;
        }
    }

    return false;
}

/**
 * @param list<string> $lexique
 * @return list<array{fichier: string, ligne: int, genre: string, identifiant: string, jetons: list<string>}>
 */
function analyser(string $fichier, array $lexique): array
{
    $source = @file_get_contents($fichier);
    if ($source === false) {
        // Un fichier annoncé comme ajouté mais illisible n'est pas « rien à signaler » : c'est un
        // contrôle qui n'a pas eu lieu. On le dit, plutôt que de le compter comme conforme.
        fwrite(STDERR, sprintf(
            "\n=== ERREUR — fichier annoncé ajouté mais illisible : %s ===\n"
            . "  Le contrôle n'a pas pu porter dessus. Refus plutôt que silence.\n\n",
            $fichier
        ));
        exit(2);
    }

    $trouvailles = [];

    foreach (DECLARATIONS as $genre => $motif) {
        if (preg_match_all($motif, $source, $correspondances, PREG_OFFSET_CAPTURE) === false) {
            continue;
        }

        foreach ($correspondances[1] as $capture) {
            [$identifiant, $decalage] = $capture;

            // Une permission citee ailleurs est une reference, pas une declaration : la renommer ici
            // designerait un code absent du catalogue, et le voteur refuserait tout.
            if ($genre === 'permission' && permissionDejaDeclaree($identifiant, $fichier)) {
                continue;
            }

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

// Révision de fin de comparaison. Vaut HEAD en local ; le hook `pre-receive` la fixe explicitement,
// car côté serveur le commit poussé n'est encore référencé par aucun HEAD.
$GLOBALS['jusqua'] = 'HEAD';

foreach ($options as $option) {
    if (str_starts_with($option, '--contre=')) {
        $reference = substr($option, strlen('--contre='));
    } elseif (str_starts_with($option, '--jusqua=')) {
        $GLOBALS['jusqua'] = substr($option, strlen('--jusqua='));
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
  - un code de permission déjà déclaré ailleurs dans app/src est une référence, pas une déclaration :
    il n'est plus signalé. Si tu vois encore ce message sur un code existant, c'est un défaut du
    contrôle — dis-le-moi, ne renomme pas : tu désignerais une permission absente du catalogue.

La bonne réponse est de renommer. Elle l'a été les trois fois où elle a été refusée cette semaine,
sur trois modules différents, et à chaque fois le code final était meilleur.

Si tu crois tenir un faux positif, la procédure est écrite en tête de ce fichier — elle passe par un
aller-retour, délibérément. Un contrôle qu'on peut désarmer seul, un vendredi soir, ne protège que
les gens qui n'en avaient pas besoin (D53).

TXT;

exit(1);
