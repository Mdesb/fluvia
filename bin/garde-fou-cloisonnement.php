#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Garde-fou n°1 — cloisonnement (D3, D8).
 *
 * Refuse toute **résolution d'entité à partir d'un identifiant fourni par le client** qui ne
 * revérifie pas l'appartenance au périmètre.
 *
 * Pourquoi cette formulation et pas « tout endpoint qui touche la base » : le cloisonnement de ce
 * projet repose sur les extensions Doctrine `Perimetre*`, qui ne s'appliquent qu'aux opérations de
 * **lecture** d'API Platform. Un Processor qui fait `getRepository(X::class)->find($idDuCorps)` sort
 * du filet sans que rien ne le signale (D8). C'est cet angle mort précis que ce contrôle surveille —
 * viser plus large produirait un bruit tel que le garde-fou finirait désactivé.
 *
 * Usage :
 *   php bin/garde-fou-cloisonnement.php              # contrôle (code de sortie 1 si échec)
 *   php bin/garde-fou-cloisonnement.php --liste      # toutes les violations actuelles, triées
 *   php bin/garde-fou-cloisonnement.php --nettoyer   # retire de la ligne de base ce qui est corrigé
 *   php bin/garde-fou-cloisonnement.php --contre=origin/main   # cliquet réel (voir ci-dessous)
 *
 * **Pourquoi `--contre` existe.** Le plafond seul ne suffit pas : qui ajoute une entrée peut relever
 * le plafond dans le même commit, et le contrôle passe. Un cliquet dont l'auteur détient la référence
 * n'est pas un cliquet. `--contre=<ref>` relit le plafond sur une révision que l'auteur ne contrôle
 * pas — la branche cible en CI — et refuse toute remontée. C'est là, et seulement là, que la règle
 * « la ligne de base ne peut que rétrécir » devient opposable.
 *
 * Aucune dépendance : ni Composer, ni conteneur, ni base. Tourne en local comme en CI.
 */

const RACINE_SRC = 'app/src';
const LIGNE_DE_BASE = 'bin/cloisonnement.ligne-de-base.json';

/** Usage RÉEL d'un identifiant client — pas la signature de `process()`, qui contient toujours `$uriVariables`. */
const MOTIF_ID_CLIENT = '/\$uriVariables\s*\[|->corps\(\)|\$request->(?:query|request|attributes)->get\(/';

/** Résolution d'une entité à partir de cet identifiant. */
const MOTIF_RESOLUTION = '/->find\(|->findOneBy\(|->getReference\(/';

/**
 * Formes de contrôle de périmètre reconnues. La première est le motif canonique validé par
 * l'intégrateur le 19/08 : l'autorité se recalcule contre l'établissement de l'entité visée, pas
 * contre l'en-tête `X-Etablissement` (D6), et l'échec est fermé en 404.
 */
const MOTIFS_CONTROLE = [
    'codesEffectifs()'        => '/codesEffectifs\s*\(/',
    'Verificateur/Guard'      => '/Verificateur|Guard/',
    'ContexteEtablissement'   => '/ContexteEtablissement/',
    'extension Perimetre'     => '/Perimetre\w*/',
    'getEtablissement()'      => '/->getEtablissement\(\)/',
    // Declaration explicite : le fichier porte un controle de perimetre d'une forme que les motifs
    // ci-dessus ne savent pas reconnaitre. Le message du garde-fou promettait deja cette porte de
    // sortie (« documente-le dans le fichier lui-meme ») sans qu'elle existe — la voici.
    //
    // Elle est preferable a l'elargissement des motifs : une exemption declaree est greppable,
    // datee et attribuable, alors qu'une detection assouplie ouvre un trou pour tout le monde et
    // sans trace. L'annotation doit porter une raison — le deux-points suivi de texte est exige.
    //
    //     @cloisonnement-verifie : <pourquoi ce fichier est controle, et par qui>
    //
    // Pour auditer les exemptions : grep -rn "@cloisonnement-verifie" app/src
    'annotation explicite'    => '/@cloisonnement-verifie\s*:\s*\S/',
];

const AIDE_CORRECTION = <<<'TXT'
    Motif attendu (référence : correctifs Caisse/SEPA du 19/08, MESSAGES.md) :

        $codes = $this->calculateur->codesEffectifs(
            $utilisateur,
            $entiteVisee->getEtablissement()?->getId()   // l'établissement de l'ENTITÉ, pas l'en-tête
        );
        if (!$this->calculateur->autorise($codes, '<module>', '<action>')) {
            throw new NotFoundHttpException('… introuvable.');   // 404, pas 403 : ne pas révéler l'existence
        }

    Deux points qui ne sont pas des détails :
      - l'autorité se recalcule contre l'établissement de l'entité résolue. `ContexteEtablissement`
        lit l'en-tête client `X-Etablissement` : c'est un sélecteur, pas une preuve d'appartenance (D6) ;
      - 404 et non 403 : distinguer « hors périmètre » de « inexistant » permet d'énumérer l'activité
        d'un autre établissement, ce qui est déjà une fuite.

    Si ce fichier n'a légitimement pas de périmètre (ressource publique, tâche système), ce n'est PAS
    à la ligne de base de l'absorber : documente-le dans le fichier lui-même et viens en parler dans
    MESSAGES.md. La ligne de base est gelée et ne peut que rétrécir.
    TXT;

// ---------------------------------------------------------------------------- analyse

/** @return list<string> chemins relatifs à app/src, triés */
function fichiersHttp(string $racine): array
{
    if (!is_dir($racine)) {
        fwrite(STDERR, sprintf("Répertoire source introuvable : %s\nLance ce script depuis la racine du dépôt.\n", $racine));
        exit(2);
    }

    $trouves = [];
    $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));

    /** @var SplFileInfo $fichier */
    foreach ($iterateur as $fichier) {
        if (!$fichier->isFile() || $fichier->getExtension() !== 'php') {
            continue;
        }
        $relatif = str_replace('\\', '/', substr($fichier->getPathname(), strlen($racine) + 1));
        if (!str_contains($relatif, '/State/') && !str_contains($relatif, '/Controller/')) {
            continue;
        }
        $trouves[] = $relatif;
    }

    sort($trouves);

    return $trouves;
}

/** @return list<string> les fichiers en violation, triés */
function violations(string $racine): array
{
    $resultat = [];

    foreach (fichiersHttp($racine) as $relatif) {
        $source = (string) file_get_contents($racine . '/' . $relatif);

        if (preg_match(MOTIF_ID_CLIENT, $source) !== 1 || preg_match(MOTIF_RESOLUTION, $source) !== 1) {
            continue;
        }

        foreach (MOTIFS_CONTROLE as $motif) {
            if (preg_match($motif, $source) === 1) {
                continue 2;
            }
        }

        $resultat[] = $relatif;
    }

    return $resultat;
}

// ---------------------------------------------------------------- ligne de base

/** @return array{scelle: array{plafond: int, gelee_le: string}, entrees: array<string, array{depuis: string, accorde_par: string, raison: string}>} */
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

function ecrireLigneDeBase(string $chemin, array $donnees): void
{
    file_put_contents(
        $chemin,
        json_encode($donnees, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );
}

// ------------------------------------------------------------------------ sorties

function bloc(string $titre, array $lignes, string $prefixe = '  - '): void
{
    echo "\n" . $titre . "\n";
    foreach ($lignes as $ligne) {
        echo $prefixe . $ligne . "\n";
    }
}

// -------------------------------------------------------------------------- main

$options = array_slice($argv, 1);
$racine = RACINE_SRC;

$actuelles = violations($racine);
$base = lireLigneDeBase(LIGNE_DE_BASE);
$entrees = $base['entrees'];
$plafond = (int) $base['scelle']['plafond'];

if (in_array('--liste', $options, true)) {
    echo sprintf("Violations actuelles : %d\n", count($actuelles));
    foreach ($actuelles as $v) {
        $etat = isset($entrees[$v]) ? 'ligne de base' : 'NOUVELLE';
        echo sprintf("  [%-13s] %s\n", $etat, $v);
    }
    exit(0);
}

if (in_array('--nettoyer', $options, true)) {
    $corrigees = array_values(array_diff(array_keys($entrees), $actuelles));

    if ($corrigees === []) {
        echo "Rien à nettoyer : toutes les entrées de la ligne de base sont encore en violation.\n";
        exit(0);
    }

    foreach ($corrigees as $c) {
        unset($entrees[$c]);
    }

    $base['entrees'] = $entrees;
    $base['scelle']['plafond'] = count($entrees);   // le plafond ne remonte jamais
    ecrireLigneDeBase(LIGNE_DE_BASE, $base);

    bloc(sprintf("%d entrée(s) corrigée(s), retirée(s) de la ligne de base :", count($corrigees)), $corrigees);
    echo sprintf("\nPlafond abaissé à %d. Commite %s.\n", count($entrees), LIGNE_DE_BASE);
    exit(0);
}

// --- contrôle proprement dit ---

/**
 * Plafond tel qu'il est sur une révision de référence (la branche cible, en CI). Retourne null si le
 * fichier n'y existe pas encore — c'est le cas légitime du commit qui introduit le garde-fou.
 */
$plafondReference = null;
foreach ($options as $option) {
    if (!str_starts_with($option, '--contre=')) {
        continue;
    }

    $ref = substr($option, strlen('--contre='));

    // La révision doit d'abord être résoluble. Sans cette étape, une référence inconnue, un `git`
    // absent ou un dépôt inaccessible produiraient le même résultat qu'une première introduction :
    // le cliquet serait ignoré et le contrôle passerait au vert. Un garde-fou qu'on a demandé et qui
    // ne s'applique pas doit échouer bruyamment — c'est tout l'intérêt de l'avoir demandé.
    exec(sprintf('git rev-parse --verify %s 2>/dev/null', escapeshellarg($ref)), $sortie, $code);

    if ($code !== 0) {
        fwrite(STDERR, sprintf(
            "\n=== ERREUR — référence « %s » non résoluble ===\n"
            . "  Le cliquet a été demandé mais ne peut pas s'appliquer : la ligne de base pourrait\n"
            . "  grossir sans que rien ne l'arrête. Refus de continuer plutôt que de rendre un vert\n"
            . "  qui ne veut rien dire.\n\n"
            . "  Causes habituelles : `git` absent de l'environnement, révision inconnue (un\n"
            . "  `git fetch` manque ?), ou dépôt hors de portée — typiquement un worktree monté dans\n"
            . "  un conteneur sans son répertoire `.git`.\n\n",
            $ref
        ));
        exit(2);
    }

    $brut = shell_exec(sprintf('git show %s:%s 2>/dev/null', escapeshellarg($ref), escapeshellarg(LIGNE_DE_BASE)));

    if (!is_string($brut) || trim($brut) === '') {
        // Cas légitime, et le seul : la révision existe, mais le fichier n'y est pas encore.
        echo sprintf("Référence %s résolue, ligne de base absente : première introduction — cliquet sans objet.\n", $ref);
        break;
    }

    $donneesRef = json_decode($brut, true);
    if (!is_array($donneesRef) || !isset($donneesRef['scelle']['plafond'])) {
        fwrite(STDERR, sprintf("\n=== ERREUR — ligne de base illisible sur %s ===\n\n", $ref));
        exit(2);
    }

    $plafondReference = (int) $donneesRef['scelle']['plafond'];
    break;
}

$nouvelles = array_values(array_diff($actuelles, array_keys($entrees)));
$corrigees = array_values(array_diff(array_keys($entrees), $actuelles));
$echec = false;

// Condition n°2 de l'intégrateur : la ligne de base ne peut que rétrécir.
if (count($entrees) > $plafond) {
    $echec = true;
    echo "\n=== ÉCHEC — la ligne de base a grossi ===\n";
    echo sprintf(
        "  Elle contient %d entrées pour un plafond scellé à %d (gelé le %s).\n",
        count($entrees),
        $plafond,
        $base['scelle']['gelee_le']
    );
    echo "\n  La ligne de base est une dette gelée, pas une soupape. On n'y ajoute rien :\n";
    echo "  un nouveau cas se corrige, il ne se déroge pas. Si tu as un motif impérieux,\n";
    echo "  il se discute dans MESSAGES.md avant de toucher au plafond, pas après.\n";
}

// Le cliquet opposable : le plafond n'a pas le droit de remonter par rapport à la branche cible.
if ($plafondReference !== null && $plafond > $plafondReference) {
    $echec = true;
    echo "\n=== ÉCHEC — le plafond a été relevé ===\n";
    echo sprintf("  Plafond de la branche de référence : %d. Plafond proposé : %d.\n", $plafondReference, $plafond);
    echo "\n  Relever le plafond, c'est convertir une correction à faire en dérogation permanente.\n";
    echo "  La ligne de base ne remonte pas : corrige le cas, ou viens l'exposer dans MESSAGES.md.\n";
}

if ($nouvelles !== []) {
    $echec = true;
    echo "\n=== ÉCHEC — résolution par identifiant client sans contrôle de périmètre ===\n";
    bloc(sprintf("%d fichier(s) concerné(s) :", count($nouvelles)), $nouvelles);
    echo "\n" . AIDE_CORRECTION . "\n";
}

if ($corrigees !== []) {
    bloc(
        sprintf("Bonne nouvelle : %d entrée(s) de la ligne de base ne sont plus en violation.", count($corrigees)),
        $corrigees
    );
    echo "\n  Retire-les : php bin/garde-fou-cloisonnement.php --nettoyer\n";
}

if ($echec) {
    echo "\n";
    exit(1);
}

echo sprintf(
    "Cloisonnement : OK — aucune nouvelle résolution non contrôlée. Dette gelée : %d entrée(s), plafond %d.\n",
    count($entrees),
    $plafond
);
exit(0);
