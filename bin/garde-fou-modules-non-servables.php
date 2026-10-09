<?php

declare(strict_types=1);

/**
 * UNE DÉCISION FONDÉE SUR UNE ABSENCE, ET L'ABSENCE VA CESSER — §8.1.
 *
 * ── CE QUI EST DÉCIDÉ, ET SUR QUOI ──────────────────────────────────────────────────────────────
 *
 * Trois modules du catalogue étaient **activables et facturés 19,00 € / mois** sans pouvoir rendre
 * le moindre service. Recompté le 04/09 par ce contrôle même — occurrences de `#[ORM\Entity]` et de
 * `#[ApiResource]` — contre `padel` pris comme témoin positif (15 entités, 17 ressources, des
 * écrans) :
 *
 *     lodging   0 entité · 0 ressource API · 0 écran   il ne peut pas enregistrer une chambre
 *     stay      2 entités · 2 ressources    · 0 écran   ⚠ PÉRIMÉ — cf. ci-dessous
 *     dining    2 entités · 1 ressource     · 0 écran   même situation
 *
 * ⚠ **`stay` A UN ÉCRAN DEPUIS LE 05/09** (`pages/Sejours.jsx`, câblé dans la navigation avec ses
 * quatre permissions). La mesure ci-dessus est celle du 04/09 et elle est fausse depuis. Ce contrôle
 * ne l'a pas vu pendant des heures — son signal frontal exigeait un séparateur que les vraies formes
 * ne portent jamais. Corrigé ; il crie désormais, et c'est à l'arbitrage §8.1 de trancher si `stay`
 * redevient vendable.
 *
 * ⚠ CES CHIFFRES SONT CEUX D'UNE DÉFINITION REPRODUCTIBLE : les occurrences de `#[ORM\Entity]` et
 * de `#[ApiResource]`, ce que `bin/garde-fou-modules-non-servables.php` recompte à chaque passage.
 * §8.1 en annonçait d'autres (« stay : 9 entités ») avec un autre comptage — le même qui donne 36
 * entités à Padel là où les attributs en montrent 15. Les deux sont cohérents chacun de leur côté ;
 * **un chiffre sans sa définition ne se relaie pas**, et j'avais recopié le premier sans le refaire.
 *
 * Arbitrage de Maxime : « les rendre non facturables ». `CatalogueCapacites::peutServir()` les
 * nomme, et `OfferCatalog` les retire de la vente.
 *
 * ── POURQUOI CE CONTRÔLE EXISTE ─────────────────────────────────────────────────────────────────
 *
 * ⚠ UNE LISTE QUI DIT UNE ABSENCE NE VIEILLIT PAS MAL : ELLE S'INVERSE. Le jour où quelqu'un
 * construit `lodging`, ces trois lignes deviennent le contraire du vrai — et le module resterait
 * **gratuit pour toujours**, parce que rien ne relie le fait de le construire au fait de le vendre.
 * Celui qui écrit la première entité de `Lodging` n'a aucune raison d'aller lire un `match` dans
 * `CatalogueCapacites`, et aucun test ne tombera : le module marchera, il ne sera juste pas facturé.
 *
 * C'est le défaut le plus cher de la famille, parce qu'il ne ressemble à rien. Ce contrôle **rend
 * l'absence bruyante** : il fige ce qui a été mesuré, et refuse la poussée dès qu'un module listé
 * grossit — plus d'entités, plus de ressources API, ou une trace dans le frontal.
 *
 * ⚠ IL NE DÉCIDE PAS À LA PLACE DE MAXIME. Il ne dit pas « ce module sert maintenant, vends-le » :
 * il dit « ce module a bougé, la décision de 8.1 repose sur une mesure périmée, refais-la ». La
 * différence compte — un contrôle qui déciderait tout seul remettrait en vente un module à moitié
 * fait.
 *
 * ── ⚠ SON ANGLE MORT, DECLARE ───────────────────────────────────────────────────────────────────
 *
 * Le signal frontal cherche le NOM DU MODULE dans les sources du frontal. Un ecran dont le fichier
 * porte un nom FRANCAIS lui est invisible par ce chemin : `pages/Sejours.jsx` ne contient pas la
 * chaine `stay`. Il ne voit ce module que par ses PERMISSIONS (`stay.read` dans `api/menu.js`) et
 * ses ROUTES (`/api/stays/`) — ce qui a suffi ici, mais ne suffira pas a un ecran qui n'appellerait
 * ni l'un ni l'autre.
 *
 * Cette limite a ete apprise en ecrivant le temoin : la liste des « formes que le compteur doit
 * voir » contenait le nom de fichier de l'ecran, et le temoin est tombe.
 *
 * ── CE QU'IL NE PROUVE PAS ──────────────────────────────────────────────────────────────────────
 *
 * Qu'un module non listé, lui, sert vraiment. Il ne regarde QUE les modules déclarés non servables.
 * Un quatrième module vide, jamais listé, lui est invisible — et c'est assumé : décider qu'un module
 * est vide est un jugement produit, pas une mesure.
 *
 * Usage :
 *   php bin/garde-fou-modules-non-servables.php
 *   php bin/garde-fou-modules-non-servables.php --nettoyer
 */

const CATALOGUE = 'app/src/Fonctionnalite/Service/CatalogueCapacites.php';
const LIGNE_DE_BASE = 'bin/modules-non-servables.ligne-de-base.json';
const RACINE_PHP = 'app/src';
const RACINE_FRONT = 'frontend/src';

/**
 * Les capacités que `CatalogueCapacites::peutServir()` déclare non servables.
 *
 * ⚠ ON LIT LA SOURCE QUI DÉCIDE, PAS UNE COPIE. Recopier la liste ici garantirait qu'un jour les
 * deux divergent, et ce contrôle surveillerait des modules que le catalogue a cessé de nommer — en
 * restant vert, ce qui est pire que de ne rien surveiller.
 *
 * @return list<string>
 */
function capacitesNonServables(): array
{
    $source = @file_get_contents(CATALOGUE);

    if ($source === false) {
        fwrite(STDERR, "\n=== ERREUR — catalogue illisible : " . CATALOGUE . " ===\n\n");
        exit(2);
    }

    // Le corps du `match` de `peutServir()` : de sa signature au `default`.
    if (preg_match('/function peutServir\(.*?\{.*?match\s*\(\$code\)\s*\{(.*?)default\s*=>/s', $source, $bloc) !== 1) {
        // ⚠ NE PAS RENDRE UNE LISTE VIDE ICI. Une liste vide se lit « rien à surveiller » et le
        //   contrôle passerait au vert le jour où sa propre lecture casse — un vert qui n'a rien
        //   mesuré. On refuse bruyamment à la place.
        fwrite(STDERR, "\n=== ERREUR — `peutServir()` introuvable ou de forme inattendue dans " . CATALOGUE . " ===\n");
        fwrite(STDERR, "  Ce contrôle lit la liste dans le catalogue. S'il ne sait plus la lire, il ne mesure\n");
        fwrite(STDERR, "  plus rien — et un vert vaudrait alors mensonge. Adapte la lecture, ou retire le contrôle.\n\n");
        exit(2);
    }

    preg_match_all('/CapaciteCode::(\w+)/', $bloc[1], $cas);

    return array_values(array_unique($cas[1]));
}

/**
 * Ce qu'un module pèse, aujourd'hui.
 *
 * @return array{entites: int, ressources: int, frontal: int}
 */
function peser(string $module): array
{
    $dossier = RACINE_PHP . '/' . $module;

    $entites = 0;
    $ressources = 0;

    if (is_dir($dossier)) {
        $entrees = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dossier, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($entrees as $entree) {
            if (!$entree->isFile() || $entree->getExtension() !== 'php') {
                continue;
            }

            $source = @file_get_contents($entree->getPathname());

            if ($source === false) {
                fwrite(STDERR, sprintf("\n=== ERREUR — fichier illisible : %s ===\n\n", $entree->getPathname()));
                exit(2);
            }

            $entites += preg_match_all('/#\[ORM\\\\Entity\b/', $source);
            $ressources += preg_match_all('/#\[ApiResource\b/', $source);
        }
    }

    // ── Le frontal : une trace du module dans un écran ──────────────────────────────────────────
    //
    // ⚠ C'EST LE SIGNAL QUI COMPTE LE PLUS, et c'est celui qui manquait à la mesure d'origine.
    //   `stay` et `dining` ont NEUF entités chacun et ne servent toujours à rien : c'est l'écran qui
    //   fait la différence entre « du code existe » et « quelqu'un peut s'en servir ».
    // ⚠ « LE FRONTAL SAIT-IL PILOTER CE MODULE ? » — TROISIÈME VERSION DE CE SIGNAL.
    //
    // La première version demandait `/\bstay[_\/-]/i` — le nom du module SUIVI de `_`, `/` ou `-`.
    // Les vraies formes ne le portent jamais : `stays/` (le pluriel intercale un `s`), `stay.read`
    // (un point), `StayStatus` (camel), `Sejours` (le nom français de l'écran).
    //
    // Le 05/09 à 01h10, l'écran `pages/Sejours.jsx` est arrivé — câblé dans la navigation avec ses
    // quatre permissions, routé, servi. **Ce contrôle est resté vert**, quatre heures après avoir
    // été écrit pour crier exactement à ce moment-là.
    //
    //     v1  nom + séparateur     lodging 0   stay  0   dining 0   padel  21   piscine  18
    //     v2  nom seul              lodging 1   stay 29   dining 0   padel 113   piscine 129
    //     v3  pilotage              lodging 0   stay  7   dining 0   padel  14   piscine   8
    //
    // ⚠ LA v1 N'ÉTAIT PAS CASSÉE — padel et piscine répondaient — ELLE ÉTAIT AVEUGLE À UNE FORME.
    //   C'est pire qu'un détecteur muet : celui-là trouve des choses ailleurs, donc on lui fait
    //   confiance.
    //
    // ⚠ ET LA v2, QUI VOYAIT ENFIN `stay`, COMPTAIT UN LIBELLÉ POUR `lodging` : la ligne
    //   `lodging: 'Hébergement'` d'un écran de paramètres. Élargir un motif ne suffit pas — il faut
    //   mesurer LA BONNE CHOSE. Ce qui fait qu'un module sert, c'est que le frontal l'APPELLE : une
    //   route `/api/<module>` dans `api/client.js`, ou une entrée de navigation dans `api/menu.js`.
    //   Un libellé ne pilote rien.
    $frontal = 0;
    $nom = strtolower($module);

    foreach ([
        RACINE_FRONT . '/api/client.js' => '#/api/' . preg_quote($nom, '#') . '#i',
        // Le menu a quitté `AppShell.jsx` pour `api/menu.js` le 08/10 : c'est là que vivent ses entrées.
        RACINE_FRONT . '/api/menu.js' =>"#'" . preg_quote($nom, '#') . "\\.|ic: '" . preg_quote($nom, '#') . "'#i",
    ] as $fichier => $motifPilotage) {
        $source = @file_get_contents($fichier);

        // ⚠ UN FICHIER DE PILOTAGE INTROUVABLE N'EST PAS « ZÉRO PILOTAGE » : c'est une mesure qui
        //   n'a pas eu lieu. On refuse, plutôt que de rendre un zéro qui ressemble à une réponse.
        if ($source === false) {
            fwrite(STDERR, sprintf("\n=== ERREUR — fichier de pilotage introuvable : %s ===\n", $fichier));
            fwrite(STDERR, "  Ce contrôle mesure si le frontal sait piloter un module. Sans ce fichier,\n");
            fwrite(STDERR, "  il ne mesure rien — et un zéro se lirait comme « le module ne sert pas ».\n\n");
            exit(2);
        }

        $frontal += preg_match_all($motifPilotage, $source);
    }

    return ['entites' => $entites, 'ressources' => $ressources, 'frontal' => $frontal];
}

// ---------------------------------------------------------------------------------------------------

$capacites = capacitesNonServables();

if ($capacites === []) {
    echo "Modules non servables : OK — aucun module déclaré non servable, rien à surveiller.\n";
    exit(0);
}

$mesures = [];
foreach ($capacites as $module) {
    $mesures[$module] = peser($module);
}

$gelees = is_file(LIGNE_DE_BASE)
    ? (array) json_decode((string) file_get_contents(LIGNE_DE_BASE), true)
    : [];

if (in_array('--nettoyer', array_slice($argv, 1), true)) {
    file_put_contents(
        LIGNE_DE_BASE,
        json_encode($mesures, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );
    echo sprintf("Ligne de base réécrite : %d module(s) surveillé(s).\n", count($mesures));
    exit(0);
}

// ── Les témoins : on prouve que le contrôle VOIT une croissance ────────────────────────────────
//
// ⚠ UN CONTRÔLE QUI NE SIGNALE RIEN EST INDISCERNABLE D'UN CONTRÔLE AVEUGLE. Celui-ci passera
//   vert pendant des mois — c'est même son but. Sans un cas fabriqué qu'il DOIT attraper, sa
//   permanence au vert ne prouve rien du tout.
$temoins = 0;
$faux = [];

$reference = ['entites' => 0, 'ressources' => 4, 'frontal' => 0];

// (1) Une entité de plus doit être vue.
if (comparer('temoin', ['entites' => 1, 'ressources' => 4, 'frontal' => 0], $reference) === []) {
    $faux[] = 'une entité de plus n’est pas détectée';
} else {
    ++$temoins;
}

// (2) Un écran de plus doit être vu — c'est LE signal qui distingue « du code » de « ça sert ».
if (comparer('temoin', ['entites' => 0, 'ressources' => 4, 'frontal' => 3], $reference) === []) {
    $faux[] = 'une trace dans le frontal n’est pas détectée';
} else {
    ++$temoins;
}

// (2 bis) ⚠ LE TÉMOIN QUI MANQUAIT, ET SON ABSENCE A COÛTÉ LE DÉFAUT CI-DESSUS.
//
// Les quatre témoins d'origine testaient le COMPARATEUR — « une entité de plus est-elle vue ? » —
// et jamais l'ORGANE QUI MESURE. Le comparateur marchait parfaitement ; c'est le compteur frontal
// qui était aveugle. Celui-ci exerce le motif sur les formes réelles qu'il ratait :
// ⚠ CHACUNE CONTIENT LE NOM DU MODULE. Ma premiere liste y avait mis
//   `import Sejours from './pages/Sejours.jsx'` — le fichier de l'ecran — et ce temoin-la a
//   ECHOUE : **l'ecran porte un nom francais et ne contient pas la chaine `stay`**. Le detecteur
//   ne le voit que par les permissions et les routes. C'est une limite reelle, declaree plus bas,
//   et c'est mon propre temoin qui me l'a apprise en tombant.
$formes = ["perms: ['stay.read', 'stay.write']", 'request(`/api/stays/${id}`)', "ic: 'stay'"];
$vus = 0;
foreach ($formes as $ligne) {
    if (preg_match("#/api/stay|'stay\.|ic: 'stay'#i", $ligne) === 1) {
        ++$vus;
    }
}
if ($vus !== \count($formes)) {
    $faux[] = sprintf('le compteur frontal rate %d des %d formes réelles de `stay`', \count($formes) - $vus, \count($formes));
} else {
    ++$temoins;
}

// ⚠ ET LE TÉMOIN NÉGATIF DU MÊME ORGANE : un mot qui CONTIENT le nom sans être lui.
// ⚠ LE TÉMOIN NÉGATIF, ET C'EST LUI QUI A TUÉ LA VERSION PRÉCÉDENTE : un LIBELLÉ n'est pas un
//   pilotage. `lodging: 'Hébergement'` dans un écran de paramètres ne prouve pas que le module sert.
if (preg_match("#/api/lodging|'lodging\.|ic: 'lodging'#i", "  lodging: 'Hébergement',") === 1) {
    $faux[] = 'le compteur frontal prend un libellé de traduction pour un pilotage';
} else {
    ++$temoins;
}

// (3) ⚠ CE QU'IL DOIT ÉPARGNER : une mesure identique, et une mesure qui DIMINUE. Un module qu'on
//     démonte n'est pas un module qu'on construit ; le signaler apprendrait à sauter le contrôle.
if (comparer('temoin', $reference, $reference) !== []) {
    $faux[] = 'une mesure inchangée est signalée à tort';
} else {
    ++$temoins;
}

if (comparer('temoin', ['entites' => 0, 'ressources' => 2, 'frontal' => 0], $reference) !== []) {
    $faux[] = 'une mesure en BAISSE est signalée à tort';
} else {
    ++$temoins;
}

if ($faux !== []) {
    fwrite(STDERR, "\n=== ÉCHEC — les témoins de ce contrôle ne passent pas ===\n\n");
    foreach ($faux as $f) {
        fwrite(STDERR, '  · ' . $f . "\n");
    }
    fwrite(STDERR, "\nUn contrôle dont les témoins tombent ne mesure plus ce qu'il annonce.\n\n");
    exit(1);
}

/**
 * @param array{entites: int, ressources: int, frontal: int} $maintenant
 * @param array{entites: int, ressources: int, frontal: int} $avant
 * @return list<string> ce qui a grossi
 */
function comparer(string $module, array $maintenant, array $avant): array
{
    $ecarts = [];

    foreach (['entites' => 'entité(s)', 'ressources' => 'ressource(s) API', 'frontal' => 'mention(s) dans le frontal'] as $cle => $mot) {
        if ($maintenant[$cle] > $avant[$cle]) {
            $ecarts[] = sprintf('%s : %d → %d %s', $module, $avant[$cle], $maintenant[$cle], $mot);
        }
    }

    return $ecarts;
}

$ecarts = [];
$neufs = [];

foreach ($mesures as $module => $mesure) {
    if (!isset($gelees[$module])) {
        $neufs[] = $module;
        continue;
    }

    /** @var array{entites: int, ressources: int, frontal: int} $avant */
    $avant = $gelees[$module];
    $ecarts = [...$ecarts, ...comparer($module, $mesure, $avant)];
}

if ($neufs !== []) {
    fwrite(STDERR, "\n=== ÉCHEC — module(s) déclaré(s) non servable(s) sans mesure de référence ===\n\n");
    foreach ($neufs as $m) {
        fwrite(STDERR, '  · ' . $m . "\n");
    }
    fwrite(STDERR, "\n  Lance `php bin/garde-fou-modules-non-servables.php --nettoyer` APRÈS avoir vérifié que\n");
    fwrite(STDERR, "  ce module ne peut effectivement rien servir. Geler sans regarder ferait de ce contrôle\n");
    fwrite(STDERR, "  une formalité.\n\n");
    exit(1);
}

if ($ecarts !== []) {
    fwrite(STDERR, "\n=== ÉCHEC — un module déclaré NON SERVABLE a grossi ===\n\n");
    foreach ($ecarts as $e) {
        fwrite(STDERR, '  ' . $e . "\n");
    }
    fwrite(STDERR, "\n");
    fwrite(STDERR, "La décision de §8.1 — « les rendre non facturables » — repose sur une MESURE : ces modules\n");
    fwrite(STDERR, "ne pouvaient rien servir. Quelqu'un vient de les faire grossir, donc la mesure est périmée.\n\n");
    fwrite(STDERR, "⚠ CE CONTRÔLE NE DÉCIDE PAS À TA PLACE. Il ne dit pas « remets-le en vente » : il dit que la\n");
    fwrite(STDERR, "  raison de ne pas le vendre n'a peut-être plus cours. Deux suites possibles :\n\n");
    fwrite(STDERR, "  · le module sert désormais  → retire-le de `CatalogueCapacites::peutServir()`, et il se\n");
    fwrite(STDERR, "    remet en vente au prix déjà saisi (l'option a été désactivée, pas supprimée).\n\n");
    fwrite(STDERR, "  · il est encore en chantier → `php bin/garde-fou-modules-non-servables.php --nettoyer`,\n");
    fwrite(STDERR, "    ce qui fige la nouvelle taille et te reposera la question au prochain palier.\n\n");
    exit(1);
}

echo sprintf(
    "Modules non servables : OK — %d module(s) surveillé(s), aucun n'a grossi. (%d témoins passés, dont 2 qui prouvent ce qu'il épargne.)\n",
    count($mesures),
    $temoins,
);

foreach ($mesures as $module => $m) {
    echo sprintf(
        "  %-12s %d entité(s) · %d ressource(s) API · %d mention(s) dans le frontal\n",
        $module,
        $m['entites'],
        $m['ressources'],
        $m['frontal'],
    );
}
