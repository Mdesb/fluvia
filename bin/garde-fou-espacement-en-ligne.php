<?php

declare(strict_types=1);

/**
 * Garde-fou n°27 — l'espacement écrit à la main ne remonte plus.
 *
 * ── CE QU'IL EMPÊCHE, ET POURQUOI IL NE POUVAIT PAS EXISTER PLUS TÔT ───────────────────────────
 *
 * `allaccess-34` a posé une échelle d'espacement le 29/08 : cinq paliers nommés par rôle
 * (`--esp-serre` à `--esp-section`). Son document disait aussi ce qui arriverait sans cliquet :
 *
 *   > « un écran converti d'un côté est compensé par un écran neuf de l'autre, et le chantier ne
 *   > finit jamais »
 *
 * Mesuré le 30/08, un jour plus tard : **6 fichiers sur 109** emploient l'échelle, et le nombre de
 * déclarations littérales n'a pas baissé. La prédiction s'est vérifiée exactement.
 *
 * ⚠ **Le cliquet ne pouvait PAS être posé le 29.** Un garde-fou qui gèle un nombre alors que
 * l'alternative n'existe pas encore refuse tout écran neuf **sans offrir de remède** — c'est le
 * conflit de deux règles déjà rencontré sur le cliquet d'écart, où la règle neuve gagne toujours et
 * bloque le travail. L'échelle d'abord, le cliquet ensuite : l'ordre était écrit d'avance, et il est
 * respecté.
 *
 * ── ⚠ IL COMPTE L'ESPACEMENT, PAS LA MISE EN PAGE — ET LA DIFFÉRENCE EST DÉCISIVE ─────────────
 *
 * Ma première mesure comptait 1 736 déclarations : tout ce qui touche à la disposition, `display`,
 * `flexWrap`, `alignItems` compris. `allaccess-34` a corrigé, et elle avait raison :
 *
 *   > « aucun jeton ne remplace un `display: flex` »
 *
 * Les ~590 d'écart relèvent de classes de composant qui manquent — un second chantier, une autre
 * solution. Un cliquet qui les compterait **exigerait des conversions impossibles** : le seul moyen
 * de faire baisser le nombre serait de supprimer des `display: flex` dont dépend la mise en page.
 *
 * On ne compte donc que ce qu'un jeton peut remplacer : `gap`, `rowGap`, `columnGap`, `margin*`,
 * `padding*`.
 *
 * ── ⚠ TROIS EXCLUSIONS, ET CHACUNE ÉVITE UNE CONVERSION QUI DÉGRADERAIT ───────────────────────
 *
 *   · `0`      — une remise à zéro. `margin: 0` annule un défaut du navigateur ; il n'existe pas de
 *                jeton « zéro », et en créer un ferait passer une négation pour un espacement.
 *   · `auto`   — un centrage. `margin: '0 auto'` est une règle de disposition déguisée en marge.
 *   · composite — `padding: '1px 8px'` porte DEUX valeurs. Le convertir demanderait de choisir un
 *                jeton pour chacune, donc de changer le rendu, ou d'écrire
 *                `'var(--esp-serre) var(--esp-normal)'` — plus long, moins lisible, et sans gain.
 *
 * Sans ces exclusions, le chiffre mesurerait **le travail fourni** plutôt que le résultat obtenu —
 * et un rapport vert ne le montrerait pas.
 *
 * ── LE PLAFOND NE PEUT QUE DESCENDRE, ET JAMAIS TOUT SEUL ─────────────────────────────────────
 *
 * `--sceller` abaisse le plafond à la mesure du jour. Il **refuse de le relever** : une ligne de base
 * qui se régénère gèle la dérive au lieu de la mesurer, et rend un vert qui ne veut plus rien dire.
 * Descendre est un geste explicite, qu'on commite et qu'on relit.
 */

const RACINE = __DIR__ . '/../frontend/src';
const LIGNE_DE_BASE = __DIR__ . '/espacement-en-ligne.ligne-de-base.json';

/** Les propriétés qu'un jeton d'espacement peut remplacer. Rien d'autre. */
const PROPRIETES = 'gap|rowGap|columnGap|margin|marginTop|marginRight|marginBottom|marginLeft'
    . '|padding|paddingTop|paddingRight|paddingBottom|paddingLeft';

/**
 * @return array{fichiers: array<string, int>, total: int, jetons: int, exclues: int}
 */
function mesurer(): array
{
    $fichiers = [];
    $total = 0;
    $jetons = 0;
    $exclues = 0;

    $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(RACINE, FilesystemIterator::SKIP_DOTS));

    /** @var SplFileInfo $entree */
    foreach ($iterateur as $entree) {
        if (!$entree->isFile() || $entree->getExtension() !== 'jsx') {
            continue;
        }

        $contenu = (string) file_get_contents($entree->getPathname());
        $chemin = str_replace('\\', '/', substr($entree->getPathname(), strlen(RACINE) + 1));

        // La valeur court jusqu'à la virgule ou l'accolade qui la termine. C'est volontairement
        // grossier : on mesure une tendance sur des milliers d'occurrences, pas un arbre syntaxique.
        if (!preg_match_all('/\b(' . PROPRIETES . ')\s*:\s*([^,}\n]+)/', $contenu, $trouves, PREG_SET_ORDER)) {
            continue;
        }

        $litterales = 0;

        foreach ($trouves as $trouve) {
            $valeur = trim($trouve[2]);

            if (str_contains($valeur, 'var(--esp-')) {
                ++$jetons;
                continue;
            }

            if (estExclue($valeur)) {
                ++$exclues;
                continue;
            }

            ++$litterales;
        }

        if ($litterales > 0) {
            $fichiers[$chemin] = $litterales;
            $total += $litterales;
        }
    }

    ksort($fichiers);

    return ['fichiers' => $fichiers, 'total' => $total, 'jetons' => $jetons, 'exclues' => $exclues];
}

/**
 * ⚠ Les trois exclusions de `allaccess-34`, mesurées le 29/08 et confirmées le 30.
 *
 * Chacune désigne une déclaration qu'un jeton ne peut pas remplacer sans dégrader. Les compter
 * ferait mesurer le travail au lieu du résultat.
 */
function estExclue(string $valeur): bool
{
    $nu = trim($valeur, " \t'\"`");

    // Remise à zéro : il n'existe pas de jeton « zéro », et en créer un ferait passer une négation
    // pour un espacement.
    if ($nu === '0' || $nu === '0px') {
        return true;
    }

    // Centrage : `margin: '0 auto'` est une règle de disposition déguisée en marge.
    if (str_contains($nu, 'auto')) {
        return true;
    }

    // Composite : deux valeurs ou plus. Les convertir demande d'en choisir un jeton pour chacune,
    // donc de changer le rendu — ou d'écrire deux fois plus long pour rien.
    if (preg_match('/^[^ ]+ +[^ ]+/', $nu) === 1) {
        return true;
    }

    return false;
}

// ── Exécution ───────────────────────────────────────────────────────────────────────────────────

$sceller = in_array('--sceller', $argv, true);

if (!is_dir(RACINE)) {
    // ⚠ ABSTENTION BRUYANTE, PAS UN VERT. Le crochet de pré-réception travaille sur un arbre
    // temporaire qui peut ne pas porter le frontal. Rendre « OK » y ferait croire à un contrôle qui
    // n'a rien regardé — le défaut exact corrigé sur le lanceur des garde-fous ce matin.
    fwrite(STDERR, "Espacement en ligne : IGNORÉ — frontend/src absent. Ce contrôle ne dit RIEN ici.\n");
    exit(0);
}

$mesure = mesurer();

if ($mesure['total'] === 0 && $mesure['jetons'] === 0) {
    // Un zéro sur les deux compteurs à la fois n'est pas un frontal parfait : c'est un instrument
    // cassé. On refuse de conclure plutôt que d'annoncer une victoire.
    fwrite(STDERR, "Espacement en ligne : ÉCHEC — aucune déclaration trouvée, ni littérale ni en jeton.\n");
    fwrite(STDERR, "  Un frontal de 109 écrans en porte forcément. L'instrument est en cause, pas le code.\n");
    exit(2);
}

if (!file_exists(LIGNE_DE_BASE)) {
    if (!$sceller) {
        fwrite(STDERR, "Espacement en ligne : ligne de base absente. Pose-la avec --sceller.\n");
        exit(2);
    }
    $base = ['scelle' => ['plafond' => PHP_INT_MAX], 'fichiers' => []];
} else {
    $base = json_decode((string) file_get_contents(LIGNE_DE_BASE), true, 512, JSON_THROW_ON_ERROR);
}

$plafond = (int) ($base['scelle']['plafond'] ?? PHP_INT_MAX);
$avant = (array) ($base['fichiers'] ?? []);

if ($sceller) {
    if ($mesure['total'] > $plafond) {
        // ⚠ SCELLER NE RELÈVE JAMAIS. Régénérer une ligne de base au-dessus du plafond gèlerait la
        // dérive au lieu de la mesurer, et rendrait un vert qui ne veut plus rien dire.
        fwrite(STDERR, sprintf(
            "Refus de sceller : la mesure (%d) DÉPASSE le plafond (%d).\n",
            $mesure['total'],
            $plafond
        ));
        fwrite(STDERR, "  Un plafond ne monte pas. Convertis, ou explique en revue pourquoi il doit monter.\n");
        exit(2);
    }

    file_put_contents(LIGNE_DE_BASE, json_encode([
        'scelle' => ['plafond' => $mesure['total']],
        'fichiers' => $mesure['fichiers'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");

    printf("Espacement en ligne : plafond scellé à %d (était %s).\n", $mesure['total'], $plafond === PHP_INT_MAX ? '∅' : (string) $plafond);
    exit(0);
}

if ($mesure['total'] > $plafond) {
    fwrite(STDERR, sprintf(
        "Espacement en ligne : %d déclaration(s) littérale(s), plafond %d — DÉPASSÉ de %d.\n\n",
        $mesure['total'],
        $plafond,
        $mesure['total'] - $plafond
    ));

    // ⚠ NOMMER LES FICHIERS QUI ONT MONTÉ. Un cliquet qui annonce seulement un total oblige celui
    // qui le lit à chercher où — et il cherchera dans 109 fichiers.
    $montes = [];
    foreach ($mesure['fichiers'] as $chemin => $combien) {
        $ancien = (int) ($avant[$chemin] ?? 0);
        if ($combien > $ancien) {
            $montes[$chemin] = [$ancien, $combien];
        }
    }

    if ($montes !== []) {
        fwrite(STDERR, "Ont monté depuis le scellement :\n\n");
        foreach ($montes as $chemin => [$ancien, $combien]) {
            fwrite(STDERR, sprintf("    %-52s %d → %d\n", $chemin, $ancien, $combien));
        }
        fwrite(STDERR, "\n");
    }

    fwrite(STDERR, "L'échelle est dans frontend/src/styles.css :\n\n");
    fwrite(STDERR, "    --esp-serre 4px · --esp-normal 8px · --esp-large 12px · --esp-bloc 16px · --esp-section 24px\n\n");
    fwrite(STDERR, "Ne sont pas comptés : `0` (remise à zéro), `auto` (centrage) et les valeurs\n");
    fwrite(STDERR, "composites (`'1px 8px'`) — aucun jeton ne les remplace sans dégrader.\n");
    exit(1);
}

printf(
    "Espacement en ligne : OK — %d littérale(s), plafond %d. %d en jeton, %d exclue(s) (0/auto/composite).\n",
    $mesure['total'],
    $plafond,
    $mesure['jetons'],
    $mesure['exclues']
);

if ($mesure['total'] < $plafond) {
    printf("  %d de moins que le plafond : `php bin/garde-fou-espacement-en-ligne.php --sceller` pour l'abaisser.\n", $plafond - $mesure['total']);
}

exit(0);
