<?php

declare(strict_types=1);

/**
 * Garde-fou n°34 — la validation s'exécute AVANT les processeurs.
 *
 * ── LA RÈGLE ────────────────────────────────────────────────────────────────────────────────────
 *
 * API Platform valide **entre** la désérialisation et le processeur. La validation voit donc la
 * charge du client ; elle ne voit **jamais** ce que le processeur écrit ensuite. Une contrainte
 * posée sur un champ que le processeur fabrique ne protège pas ce champ.
 *
 * Deux conséquences, opposées en symptôme, et c'est ce qui rend la règle difficile à voir :
 *
 *   `Assert\NotNull` sur un champ que le processeur POSE   → 422, la création est impossible.
 *                                                            **Échec bruyant** : un écran qui ne
 *                                                            crée rien, personne ne l'ignore.
 *
 *   `Assert\Callback` sur un champ que le processeur POSE  → la valeur fabriquée ne traverse aucun
 *                                                            contrôle. **Échec silencieux** : il
 *                                                            vit des mois.
 *
 * Deux sessions s'y sont cassées le même jour, une dans chaque sens. Et j'y suis passée moi-même :
 * un `Assert\NotNull` resté sur `etablissement` faisait échouer la création en 422 avant que le
 * processeur qui pose ce champ ne soit atteint.
 *
 * ── CE QUE CE GARDE-FOU NE DIT PAS ──────────────────────────────────────────────────────────────
 *
 * Il signale une **collision**, pas une faute. Un champ à la fois écrivable par le client et posé
 * par le processeur est légitime — c'est le cas de `Vitrine::$slug`, que le client peut choisir et
 * que le processeur complète s'il se tait. Ce qui est fautif, c'est de **croire la contrainte
 * appliquée à la valeur fabriquée**. Le garde-fou pose la question ; il ne tranche pas à la place
 * du propriétaire du module.
 *
 * ── POURQUOI IL PORTE SES PROPRES TÉMOINS ───────────────────────────────────────────────────────
 *
 * Le prototype de ce contrôle trouvait 31 cas et **ratait son propre témoin positif**. Cause :
 * il indexait les processeurs par leur **nom court**, et neuf classes du dépôt s'appellent
 * `EstablishmentStampProcessor` — elles s'écrasaient l'une l'autre, et la survivante n'était pas
 * celle qui pose le champ cherché.
 *
 * C'est le défaut du garde-fou n°32, corrigé le 24/08 : *« il indexait les classes par leur nom
 * court — 18 sont partagés »*. La leçon avait été apprise une fois et n'a pas traversé. **Deux fois
 * le même aveuglement, c'est une propriété de l'outillage, pas un accident** — d'où les deux témoins
 * vérifiés à chaque exécution ci-dessous. Un contrôle qui ne voit plus rien échoue désormais au lieu
 * de féliciter.
 *
 * ── USAGE ───────────────────────────────────────────────────────────────────────────────────────
 *
 *   php bin/garde-fou-validation-avant-processeur.php
 *   php bin/garde-fou-validation-avant-processeur.php --nettoyer
 *   php bin/garde-fou-validation-avant-processeur.php --contre=origin/main
 */

const LIGNE_DE_BASE = 'bin/validation-avant-processeur.ligne-de-base.json';
const SOURCES = 'app/src';

/**
 * Les deux témoins, vérifiés à chaque exécution.
 *
 * Ils ne sont pas des cas d'essai : ce sont des faits du dépôt, choisis parce qu'ils encadrent la
 * règle des deux côtés. Si l'un cesse de se comporter comme attendu, c'est que le détecteur a
 * changé de sens — ou qu'on a corrigé le témoin, auquel cas il faut en désigner un autre, pas
 * retirer le contrôle.
 */
const TEMOINS = [
    // Positif : `slug` porte un `Assert\Callback` (méthode + `atPath`) ET le processeur le pose.
    ['fichier' => 'Boutique/Entity/Vitrine.php', 'propriete' => 'slug', 'attendu' => true],
    // Négatif : posé par son processeur, mais **aucune** contrainte — rien à signaler.
    ['fichier' => 'Compta/Entity/ParametreFacturationEtablissement.php', 'propriete' => 'profilExploitant', 'attendu' => false],
];

/** @return list<string> */
function fichiersPhp(string $racine): array
{
    $trouves = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $fichier) {
        if ($fichier->isFile() && $fichier->getExtension() === 'php') {
            $trouves[] = $fichier->getPathname();
        }
    }
    sort($trouves);

    return $trouves;
}

function espaceDeNoms(string $contenu): ?string
{
    return preg_match('/^namespace\s+([A-Za-z0-9_\\\\]+);/m', $contenu, $m) === 1 ? $m[1] : null;
}

/**
 * Nom pleinement qualifié d'une classe citée dans ce fichier.
 *
 * ⚠ C'est **tout le correctif** de ce garde-fou par rapport à son prototype : sans cette résolution,
 * neuf `EstablishmentStampProcessor` deviennent une seule entrée et huit mesures disparaissent.
 */
function nomComplet(string $contenu, string $nomCourt): string
{
    $motif = '/^use\s+([A-Za-z0-9_\\\\]*\\\\' . preg_quote($nomCourt, '/') . ');/m';
    if (preg_match($motif, $contenu, $m) === 1) {
        return $m[1];
    }

    $ns = espaceDeNoms($contenu);

    return $ns !== null ? $ns . '\\' . $nomCourt : $nomCourt;
}

/** @return array<string, list<string>> nom complet du processeur => propriétés qu'il pose */
function proprietesPosees(array $contenus): array
{
    $poses = [];
    foreach ($contenus as $chemin => $contenu) {
        $court = basename($chemin, '.php');
        if (!str_ends_with($court, 'Processor')) {
            continue;
        }
        preg_match_all('/->set([A-Z]\w*)\(/', $contenu, $m);
        $props = [];
        foreach ($m[1] as $nom) {
            $props[lcfirst($nom)] = true;
        }
        $poses[nomComplet($contenu, $court)] = array_keys($props);
    }

    return $poses;
}

/**
 * @param array<string, list<string>> $poses
 *
 * @return array<string, array{fichier: string, propriete: string, contraintes: list<string>, poseurs: list<string>}>
 */
function collisions(array $contenus, array $poses, string $racine): array
{
    $trouvees = [];

    foreach ($contenus as $chemin => $contenu) {
        if (!str_contains($contenu, '#[ORM\\Entity') || !str_contains($contenu, 'ApiResource')) {
            continue;
        }

        preg_match_all('/processor:\s*(\w+)::class/', $contenu, $m);
        if ($m[1] === []) {
            continue;
        }
        $designes = array_unique(array_map(static fn (string $n): string => nomComplet($contenu, $n), $m[1]));

        $relatif = str_replace('\\', '/', substr($chemin, strlen($racine) + 1));

        $ajouter = static function (string $propriete, array $contraintes) use (&$trouvees, $designes, $poses, $relatif): void {
            $poseurs = array_values(array_filter(
                $designes,
                static fn (string $p): bool => in_array($propriete, $poses[$p] ?? [], true),
            ));
            if ($poseurs === [] || $contraintes === []) {
                return;
            }
            sort($poseurs);
            $cle = $relatif . '::$' . $propriete;
            $trouvees[$cle] = [
                'fichier' => $relatif,
                'propriete' => $propriete,
                'contraintes' => $contraintes,
                'poseurs' => $poseurs,
            ];
        };

        // (a) La contrainte est posée directement sur la propriété.
        $motifPropriete = '/((?:^[ \t]*#\[[^\n]*\]\n)+)[ \t]*(?:private|protected|public)[^\n]*\$(\w+)\s*[;=]/m';
        preg_match_all($motifPropriete, $contenu, $blocs, PREG_SET_ORDER);
        foreach ($blocs as $bloc) {
            preg_match_all('/#\[Assert\\\\(\w+)/', $bloc[1], $c);
            $contraintes = array_values(array_unique($c[1]));
            sort($contraintes);
            $ajouter($bloc[2], $contraintes);
        }

        // (b) `Assert\Callback` vit sur une MÉTHODE : il désigne sa cible par `atPath`.
        //     Sans cette seconde passe, tout le versant silencieux de la règle est invisible.
        if (str_contains($contenu, 'Assert\\Callback')) {
            preg_match_all("/->atPath\('(\w+)'\)/", $contenu, $chemins);
            foreach (array_unique($chemins[1]) as $cible) {
                $ajouter($cible, ['Callback']);
            }
        }
    }

    ksort($trouvees);

    return $trouvees;
}

// ── Exécution ──────────────────────────────────────────────────────────────────────────────────

$racine = getcwd();
if (!is_dir($racine . '/' . SOURCES)) {
    fwrite(STDERR, "À exécuter depuis la racine du dépôt (" . SOURCES . " introuvable).\n");
    exit(2);
}

$contenus = [];
foreach (fichiersPhp($racine . '/' . SOURCES) as $f) {
    $contenus[$f] = (string) file_get_contents($f);
}

$poses = proprietesPosees($contenus);
$collisions = collisions($contenus, $poses, $racine . '/' . SOURCES);

// ── Les témoins, avant toute autre chose ───────────────────────────────────────────────────────
$temoinsEchoues = [];
foreach (TEMOINS as $temoin) {
    $vu = isset($collisions[$temoin['fichier'] . '::$' . $temoin['propriete']]);
    if ($vu !== $temoin['attendu']) {
        $temoinsEchoues[] = $temoin;
    }
}

if ($temoinsEchoues !== []) {
    echo "\n=== ÉCHEC — le détecteur ne reconnaît plus ses propres témoins ===\n\n";
    foreach ($temoinsEchoues as $t) {
        echo sprintf(
            "  %s::\$%s   attendu : %s   obtenu : %s\n",
            $t['fichier'],
            $t['propriete'],
            $t['attendu'] ? 'signalé' : 'épargné',
            $t['attendu'] ? 'épargné' : 'signalé',
        );
    }
    echo "\n"
        . "  Un contrôle qui ne voit plus rien annonce « aucun problème » — c'est le pire des\n"
        . "  verdicts, parce qu'il est rassurant. Ce garde-fou refuse donc de rendre un avis\n"
        . "  tant que ses témoins ne se comportent pas comme attendu.\n"
        . "\n"
        . "  Deux causes possibles, dans cet ordre de vraisemblance :\n"
        . "    1. le détecteur a été modifié et a perdu un cas — c'est arrivé deux fois (n°32, n°34),\n"
        . "       chaque fois parce qu'une classe était nommée par son nom court ;\n"
        . "    2. le témoin lui-même a été corrigé dans le code — alors désigne-en un autre ici,\n"
        . "       ne retire pas le contrôle.\n";
    exit(1);
}

$options = array_slice($argv, 1);

if (in_array('--nettoyer', $options, true)) {
    $entrees = [];
    foreach ($collisions as $cle => $c) {
        $entrees[$cle] = [
            'depuis' => date('Y-m-d'),
            'contraintes' => $c['contraintes'],
            'poseurs' => $c['poseurs'],
            'raison' => "Antérieur au gel. Collision mesurée, non arbitrée : le propriétaire du module doit dire si la contrainte protège encore quelque chose.",
        ];
    }
    file_put_contents(LIGNE_DE_BASE, json_encode([
        '_lisez_moi' => [
            "Chaque entrée est un champ portant une contrainte de validation ET posé par un processeur",
            "désigné sur la même ressource. La validation s'exécutant AVANT le processeur, la contrainte",
            "ne s'applique QU'À la valeur du client, jamais à celle que le processeur fabrique.",
            "Ce n'est pas forcément une faute — c'est une question à poser au propriétaire du module.",
        ],
        'scelle' => ['gelee_le' => date('Y-m-d'), 'plafond' => count($entrees)],
        'entrees' => $entrees,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    echo sprintf("Ligne de base réécrite : %d entrée(s).\n", count($entrees));
    exit(0);
}

if (!is_file(LIGNE_DE_BASE)) {
    fwrite(STDERR, sprintf("Ligne de base absente : %s\nCrée-la : php %s --nettoyer\n", LIGNE_DE_BASE, $argv[0]));
    exit(2);
}

$base = json_decode((string) file_get_contents(LIGNE_DE_BASE), true, 512, JSON_THROW_ON_ERROR);
$plafond = count($collisions);
$plafondGele = (int) $base['scelle']['plafond'];

$plafondReference = null;
foreach ($options as $option) {
    if (!str_starts_with($option, '--contre=')) {
        continue;
    }
    $sortie = [];
    exec(sprintf(
        'git show %s 2>/dev/null',
        escapeshellarg(substr($option, strlen('--contre=')) . ':' . LIGNE_DE_BASE),
    ), $sortie);
    $reference = json_decode(implode("\n", $sortie), true);
    if (is_array($reference) && isset($reference['scelle']['plafond'])) {
        $plafondReference = (int) $reference['scelle']['plafond'];
    }
}

$echec = false;
$nouvelles = array_diff_key($collisions, $base['entrees']);

if ($nouvelles !== []) {
    $echec = true;
    echo sprintf("\n=== ÉCHEC — %d nouvelle(s) collision(s) validation/processeur ===\n\n", count($nouvelles));
    foreach ($nouvelles as $cle => $c) {
        echo sprintf("  %-52s %-18s <- %s\n", $cle, implode('/', $c['contraintes']), implode(', ', $c['poseurs']));
    }
    echo "\n"
        . "  La validation s'exécute AVANT le processeur : cette contrainte ne verra jamais la\n"
        . "  valeur que le processeur pose. Selon le sens, elle rend la création impossible (422)\n"
        . "  ou elle laisse passer sans contrôle ce que le processeur fabrique.\n"
        . "\n"
        . "  Trois issues, à choisir par le propriétaire du module :\n"
        . "    · retirer la contrainte, si elle ne protégeait plus que d'une erreur du serveur ;\n"
        . "    · déplacer le contrôle DANS le processeur, s'il porte sur la valeur fabriquée ;\n"
        . "    · la garder, si le champ est réellement fourni par le client — et l'écrire.\n";
}

if ($plafondReference !== null && $plafond > $plafondReference) {
    $echec = true;
    echo sprintf("\n=== ÉCHEC — plafond relevé : %d sur la référence, %d proposé ===\n", $plafondReference, $plafond);
    echo "\n"
        . "  Un cliquet ne monte pas.\n"
        . "\n"
        . "  ⚠ La cause la plus fréquente n'est pas une faute : ta branche est simplement EN RETARD\n"
        . "  sur la référence, et un plafond a baissé entre-temps. Commence par ça :\n"
        . "\n"
        . "      git fetch origin && git merge --no-edit origin/main\n";
}

if ($echec) {
    exit(1);
}

$resorbees = count($base['entrees']) - count($collisions);
echo sprintf(
    "Validation avant processeur : OK — aucune nouvelle collision. Gelées : %d, plafond %d. Témoins : 2/2.%s\n",
    $plafond,
    $plafondGele,
    $resorbees > 0 ? sprintf(' %d résorbée(s) — pense à --nettoyer.', $resorbees) : '',
);

exit(0);
