<?php

declare(strict_types=1);

/**
 * Garde-fou n°37 — une classe CSS citée par un écran et définie dans aucune feuille.
 *
 * ── CE QU'IL EMPÊCHE ──────────────────────────────────────────────────────────────────────────
 *
 * Une classe inconnue ne casse RIEN. Le build passe, aucun avertissement n'est émis, l'élément
 * s'affiche — simplement sans le style qu'il devait avoir. Un bandeau critique s'affiche en texte
 * nu, une pastille de couleur devient grise, et personne ne le voit sauf en ouvrant l'écran et en
 * sachant à quoi il devait ressembler.
 *
 * ⚠ LE DÉPÔT AVAIT DÉJÀ LA CONSIGNE, ÉCRITE, ET ELLE N'A PROTÉGÉ PERSONNE. `styles.css:270`
 * documente la faute commise sur la fiche client — « la fiche client composait `alert warn|mut`,
 * trois noms dont aucun n'existe -- une relance a venir s'affichait donc en texte nu ». Le 31/08,
 * je l'ai refaite dans `Personnel.jsx` (`alert crit`), en ayant ce commentaire sous les yeux une
 * heure plus tôt. **Un commentaire qui dit « ne faites pas X » annonce le contrôle qui manque.**
 *
 * ── CE QU'IL A TROUVÉ EN NAISSANT, ET QUI N'AVAIT RIEN À VOIR AVEC MOI ────────────────────────
 *
 * `styles.css` définit les pastilles du calendrier sous les clés ANGLAISES du type d'événement
 * (`.cal-puce.shift`, `.meeting`, `.maintenance`, `.training`, `.unavailability`, `.other`), et
 * `CalendrierAgenda.jsx` les traduisait en français (`travail`, `reunion`, `intervention`…).
 * **Sept couleurs sur huit étaient donc mortes**, et la huitième ne marchait que par coïncidence
 * de graphie (`reservation` s'écrit pareil dans les deux langues). Ni le JSX ni la feuille n'avait
 * tort seul : c'est le raccord qui n'avait jamais été éprouvé.
 *
 * ── ⚠ CE QU'IL NE REGARDE PAS, ET POURQUOI C'EST DÉLIBÉRÉ ─────────────────────────────────────
 *
 * Il ne lit que les chaînes LITTÉRALES d'un `className`. Une classe construite (`COULEURS[type]`,
 * une variable, une concaténation) lui est invisible — c'est d'ailleurs par là que passaient les
 * sept couleurs mortes, trouvées par le repli littéral `|| 'autre'` et non par la table.
 *
 * Il SOUS-DÉTECTE donc, et c'est le bon côté pour se tromper : un garde-fou qui devinerait les
 * classes dynamiques signalerait des faux, et un détecteur qui signale des faux s'apprend à sauter.
 *
 * ── ⚠ TROIS PASSES, ET CHACUNE VIENT D'UN FAUX POSITIF MESURÉ ─────────────────────────────────
 *
 * Ma première version rendait 33 classes, dont ~30 fausses : elle ramassait toute chaîne entre
 * quotes dans le bloc, donc les OPÉRANDES DE COMPARAISON.
 *
 *   1. `x === 'confirmee' ? …`      l'opérande d'une comparaison n'est jamais une classe
 *   2. `roles.includes('admin')`    ni l'argument d'un appel
 *   3. `cond ? 'a' : 'b'`           ni la condition d'un ternaire — on ne lit qu'après le `?`
 *
 * La passe 1 a dû être écrite DEUX fois : la version qui ne jetait que la condition laissait
 * passer les ternaires IMBRIQUÉS (`a === 'x' ? 'p' : b === 'annule' ? 'q' : 'r'`), où le second
 * opérande vit après le premier `?`. 33 → 7 → 2.
 *
 * ── LES TÉMOINS TOURNENT À CHAQUE EXÉCUTION ───────────────────────────────────────────────────
 *
 * Six cas, dont **trois qui prouvent ce que le détecteur ÉPARGNE**. C'est la moitié qui manque
 * partout : un détecteur qui signale se prouve par ce qu'il laisse tranquille, sinon « il alerte
 * sur tout » et « il marche » se ressemblent parfaitement.
 */

$racine = \dirname(__DIR__) . '/frontend/src';
if (!is_dir($racine)) {
    echo "✓ Classes fantômes : pas de frontal ici, rien à vérifier.\n";
    exit(0);
}

/** Les jetons de classe d'un bloc `className=…`. */
$classesCitees = static function (string $bloc): array {
    if (str_starts_with($bloc, '"')) {
        return preg_split('/\s+/', trim(substr($bloc, 1, -1)), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
    }
    $t = str_starts_with($bloc, '{') ? substr($bloc, 1, -1) : $bloc;

    // 1. les opérandes de comparaison, des deux côtés de l'opérateur.
    $t = preg_replace('/[!=]==?\s*(\'[^\']*\'|"[^"]*")/', ' ', $t) ?? $t;
    $t = preg_replace('/(\'[^\']*\'|"[^"]*")\s*[!=]==?/', ' ', $t) ?? $t;
    // 2. les arguments d'un appel de méthode.
    $t = preg_replace('/\.\w+\(\s*(\'[^\']*\'|"[^"]*")/', ' ', $t) ?? $t;
    // 3. la condition d'un ternaire.
    $p = strpos($t, '?');
    if ($p !== false) {
        $t = substr($t, $p + 1);
    }

    $jetons = [];
    if (preg_match_all('/\'([^\']*)\'|"([^"]*)"/', $t, $m, \PREG_SET_ORDER)) {
        foreach ($m as $couple) {
            $texte = $couple[1] !== '' ? $couple[1] : ($couple[2] ?? '');
            foreach (preg_split('/\s+/', trim($texte), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $j) {
                $jetons[] = $j;
            }
        }
    }

    return $jetons;
};

// ── LES TÉMOINS, AVANT TOUTE MESURE ───────────────────────────────────────────────────────────
$temoins = [
    ['voit un ternaire simple', "{revoque || incident ? 'alert crit' : 'alert warn'}", ['alert', 'crit', 'warn']],
    ['voit un attribut nu', '"banner banner-warn"', ['banner', 'banner-warn']],
    ['voit un repli de dictionnaire', "{`cal-puce \${COULEURS[b.type] || 'other'}`}", ['other']],
    ['ÉPARGNE un opérande simple', "{`badge \${v.statut === 'confirmee' ? 'good' : 'mut'}`}", ['good', 'mut']],
    ['ÉPARGNE un opérande imbriqué', "{`badge \${s === 'planifie' ? 'info' : s === 'annule' ? 'crit' : 'mut'}`}", ['info', 'crit', 'mut']],
    ['ÉPARGNE un argument d\'appel', "{roles.includes('admin') ? 'ok' : 'ko'}", ['ok', 'ko']],
];
foreach ($temoins as [$nom, $entree, $attendu]) {
    // Les doublons ne veulent rien dire ici : `cond ? 'alert crit' : 'alert warn'` cite `alert`
    // deux fois, et c'est une seule classe. Le balayage plus bas dédoublonne aussi.
    $obtenu = array_values(array_unique($classesCitees($entree)));
    sort($obtenu);
    $att = $attendu;
    sort($att);
    if ($obtenu !== $att) {
        echo "✗ Classes fantômes : le détecteur lui-même est faux.\n";
        echo "  témoin « {$nom} » : attendu [" . implode(', ', $att) . '], obtenu [' . implode(', ', $obtenu) . "]\n";
        echo "  Tant qu'il échoue, sa réponse sur le dépôt ne vaut rien — ni son vert, ni son rouge.\n";
        exit(1);
    }
}

// ── CE QUE LES FEUILLES DÉFINISSENT ───────────────────────────────────────────────────────────
$fichiers = static function (string $racine, string $ext): array {
    $sortie = [];
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && str_ends_with($f->getFilename(), $ext)) {
            $sortie[] = $f->getPathname();
        }
    }
    sort($sortie);

    return $sortie;
};

$definies = [];
$feuilles = $fichiers($racine, '.css');
foreach ($feuilles as $f) {
    // ⚠ Les commentaires CITENT des classes (`styles.css:270` cite `alert`) : les laisser
    //    rendrait le garde-fou aveugle à la faute que ce commentaire raconte.
    $css = preg_replace('#/\*.*?\*/#s', ' ', (string) file_get_contents($f)) ?? '';
    if (preg_match_all('/\.([a-zA-Z][\w-]*)/', $css, $m)) {
        foreach ($m[1] as $c) {
            $definies[$c] = true;
        }
    }
}
if ($definies === []) {
    echo "✗ Classes fantômes : aucune classe lue dans " . \count($feuilles) . " feuille(s).\n";
    echo "  Un dépôt sans aucune classe définie n'existe pas : c'est la mesure qui est cassée.\n";
    exit(1);
}

// ── CE QUE LE JSX CITE ────────────────────────────────────────────────────────────────────────
$inconnues = [];
foreach ($fichiers($racine, '.jsx') as $f) {
    $src = (string) file_get_contents($f);
    if (!preg_match_all('/className=(\{(?:[^{}]|\{[^{}]*\})*\}|"[^"]*")/s', $src, $m, \PREG_OFFSET_CAPTURE)) {
        continue;
    }
    foreach ($m[1] as [$bloc, $offset]) {
        foreach (array_unique($classesCitees($bloc)) as $jeton) {
            if (preg_match('/^[a-z][a-z0-9-]*$/', $jeton) && !isset($definies[$jeton])) {
                $ligne = substr_count(substr($src, 0, $offset), "\n") + 1;
                $inconnues[$jeton][] = substr($f, \strlen($racine) + 1) . ':' . $ligne;
            }
        }
    }
}

if ($inconnues !== []) {
    $usages = array_sum(array_map('count', $inconnues));
    echo '✗ Classes fantômes : ' . \count($inconnues) . " classe(s) citée(s) et définie(s) nulle part ({$usages} usage(s)).\n";
    ksort($inconnues);
    foreach ($inconnues as $jeton => $lieux) {
        echo '  · ' . str_pad($jeton, 24) . ' ' . implode(', ', \array_slice($lieux, 0, 4)) . "\n";
    }
    echo "\n  L'élément s'affichera SANS ce style, et rien d'autre ne vous le dira : pas d'erreur,\n";
    echo "  pas d'avertissement, un build vert. Vérifiez le nom dans `frontend/src/styles.css` —\n";
    echo "  un modificateur n'existe souvent que sur son porteur (`.badge.crit`, pas `.crit`).\n";
    exit(1);
}

echo '✓ Classes fantômes : ' . \count($definies) . " classes définies, aucune citation orpheline.\n";
echo "  (6 témoins passés, dont 3 qui prouvent ce que le détecteur épargne.)\n";
exit(0);
