<?php

declare(strict_types=1);

/**
 * GARDE-FOU N°41 — UNE CAPACITÉ DÉCLARÉE PAR UN MODULE DOIT EXISTER AU CATALOGUE.
 *
 * ── CE QU'IL ATTRAPE, ET CE QUE ÇA COÛTAIT ──────────────────────────────────────────────────────
 *
 * `ModuleAccess::hasModule()` délègue à `Fonctionnalites::estActive()`, qui lit
 * `fonctionnalite_etablissement.capacite_code`. Et le SEUL chemin d'écriture, `definir()`, refuse
 * tout code absent de `CapaciteCode` :
 *
 *     if (!$this->catalogue->existe($code)) { throw new InvalidArgumentException(...); }
 *
 * Un module dont la capacité manque au catalogue est donc **présent et définitivement
 * inaccessible** : aucune ligne ne peut être écrite pour lui, `hasModule()` répond faux pour
 * toujours, et rien ne le dit. Ni erreur, ni journal — l'écran est simplement absent.
 *
 * Mesure du 01/09/2026 : **neuf modules sur quatorze** étaient dans cet état — `finance`,
 * `lodging`, `musee`, `padel`, `patinoire`, `piscine`, `social`, `sport`, `stay`. En base, douze
 * codes distincts, pas une seule verticale.
 *
 * ⚠ ET CINQ D'ENTRE EUX AFFIRMAIENT LE CONTRAIRE DANS LEUR PROPRE DOCBLOC :
 *
 *   « Le code `patinoire` existe déjà au catalogue de capacités, donc `hasModule()` peut répondre
 *     vrai — contrairement à un code inventé, qui rendrait le module présent et définitivement
 *     inaccessible. »
 *
 * La phrase décrit le piège, affirme y échapper, et décrivait en fait son propre état. C'est
 * exactement pourquoi ce contrôle existe : **une phrase ne vérifie rien.** `StayModule`, lui,
 * disait la vérité — et personne n'a agi dessus, parce qu'un commentaire juste n'échoue pas.
 *
 * ── CE QU'IL N'ATTRAPE PAS, ET C'EST VOULU ──────────────────────────────────────────────────────
 *
 * · **Un module transverse** (`capability()` rend `null`) : `dms`, `ocr`, `revenue_recovery`,
 *   `smart_flow`, `vente`. Ils ne se souscrivent pas, ils sont là pour tout le monde.
 * · **Une capacité du catalogue qu'aucun module ne déclare** : douze aujourd'hui (`comptabilite`,
 *   `stock`, `sepa`…). Ce sont des fonctionnalités transverses, pas des modules — elles n'ont
 *   aucune raison d'avoir un manifeste. Crier dessus rendrait ce contrôle inutilisable en une
 *   journée : c'est le sens de la vérification à sens unique.
 */

$racine = \dirname(__DIR__);

// ── 1. LE CATALOGUE ─────────────────────────────────────────────────────────────────────────────
$enumChemin = $racine . '/app/src/Fonctionnalite/Enum/CapaciteCode.php';

if (!is_file($enumChemin)) {
    fwrite(STDERR, "✗ Catalogue introuvable : {$enumChemin}\n");
    fwrite(STDERR, "  Ce contrôle ne peut RIEN mesurer sans lui. Il échoue plutôt que de rendre un vert vide.\n");
    exit(1);
}

preg_match_all(
    "/case\s+\w+\s*=\s*'([a-z0-9_]+)'\s*;/",
    (string) file_get_contents($enumChemin),
    $m,
);
$catalogue = $m[1];

// ⚠ TÉMOIN DE L'INSTRUMENT. Un catalogue lu vide ferait passer TOUS les modules — un vert parfait
// obtenu en ne mesurant rien. Le seuil est délibérément bas : il détecte un analyseur cassé, pas
// une régression du dépôt, et ne tombera donc pas le jour où quelqu'un retire une capacité.
if (\count($catalogue) < 5) {
    fwrite(STDERR, sprintf("✗ Seulement %d capacité(s) lue(s) au catalogue.\n", \count($catalogue)));
    fwrite(STDERR, "  C'est l'analyseur, pas le dépôt : il rendrait tous les modules conformes.\n");
    exit(1);
}

// ── 2. CE QUE LES MODULES DÉCLARENT ─────────────────────────────────────────────────────────────
//
// ⚠ LE NOM DE LA MÉTHODE EST `capability()`, EN ANGLAIS. Une première mesure cherchait `capacite()`
// et a rendu ZÉRO — elle aurait déclaré le dépôt conforme en n'ayant rien lu. D'où le témoin
// ci-dessous, qui exige qu'au moins un module soit vu.
$manifestes = glob($racine . '/app/src/*/[A-Z]*Module.php') ?: [];
$lus = 0;
$fautifs = [];
$transverses = 0;

foreach ($manifestes as $chemin) {
    $src = (string) file_get_contents($chemin);

    if (!preg_match('/function\s+capability\s*\([^)]*\)\s*:\s*\??string\s*\{(.*?)\}/s', $src, $mm)) {
        continue;
    }

    ++$lus;
    $corps = $mm[1];

    if (preg_match('/return\s+null\s*;/', $corps)) {
        ++$transverses;
        continue;
    }

    if (!preg_match("/return\s+'([a-z0-9_]+)'\s*;/", $corps, $mv)) {
        continue;
    }

    if (!\in_array($mv[1], $catalogue, true)) {
        $fautifs[] = [basename($chemin), $mv[1]];
    }
}

if ($lus === 0) {
    fwrite(STDERR, "✗ Aucun manifeste de module lu sur " . \count($manifestes) . " fichier(s).\n");
    fwrite(STDERR, "  C'est l'analyseur, pas le dépôt. Ne pas se fier au résultat.\n");
    exit(1);
}

// ── 3. LE VERDICT ───────────────────────────────────────────────────────────────────────────────
if ($fautifs !== []) {
    echo "\n=== ÉCHEC — capacité de module absente du catalogue ===\n\n";

    foreach ($fautifs as [$fichier, $code]) {
        printf("  %-28s déclare « %s »\n", $fichier, $code);
    }

    echo <<<'TXT'

  Un module dont la capacité manque au catalogue est PRÉSENT ET DÉFINITIVEMENT INACCESSIBLE.

  `Fonctionnalites::definir()` refuse tout code inconnu du catalogue, donc aucune ligne
  d'activation ne peut exister pour lui. `ModuleAccess::hasModule()` répondra faux pour
  toujours — sans erreur, sans journal. L'écran est simplement absent, et on cherche le
  défaut du côté du frontal.

  ⚠ Un commentaire qui affirme que le code « existe déjà au catalogue » ne le met pas au
    catalogue. Cinq modules portaient cette phrase le 01/09 ; les cinq étaient inaccessibles.

  Ajoute le cas dans `App\Fonctionnalite\Enum\CapaciteCode`, ET son descripteur dans
  `App\Fonctionnalite\Service\CatalogueCapacites` — sans descripteur, `match` lève.

  La valeur doit être EXACTEMENT celle que rend `capability()` : un code voisin ajouterait une
  capacité qui n'allume rien, ce qui est le défaut d'origine avec une ligne de plus.

TXT;

    exit(1);
}

printf(
    "Capacités de module : %d manifeste(s) lu(s), %d souscriptible(s) tous au catalogue, %d transverse(s). Catalogue : %d capacité(s).\n",
    $lus,
    $lus - $transverses,
    $transverses,
    \count($catalogue),
);
