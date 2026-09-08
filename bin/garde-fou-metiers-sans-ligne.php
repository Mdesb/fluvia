<?php

declare(strict_types=1);

/*
 * GARDE-FOU n°55 — un métier de l'énumération sans sa ligne dans le référentiel.
 *
 * ── CE QU'IL EXISTE POUR ATTRAPER ──────────────────────────────────────────────────────────────
 *
 * Depuis le lot « referentiel-metiers », le site sert les métiers de la table `website_trade`, et
 * retombe sur `TradeFallback` TANT QU'AUCUNE LIGNE N'EXISTE. Le repli est tout-ou-rien : zéro ligne
 * → les constantes, au moins une ligne → les lignes seules.
 *
 * Un sixième cas ajouté à `Metier` sans son entrée dans `TradeFallback` n'aurait donc de page dans
 * AUCUN des deux états : absent des constantes, et absent de ce que `website:trades:seed`
 * matérialise, donc absent de la table. Le préréglage existerait, l'établissement se créerait, le
 * produit se vendrait — et la page de vente du métier n'existerait nulle part. Aucune erreur, aucun
 * test rouge : une page qui n'existe pas ne casse rien.
 *
 * ── L'AUTRE SENS, QUI EST LE PLUS IMPORTANT DES DEUX ───────────────────────────────────────────
 *
 * Une entrée dans `TradeFallback` sans son cas dans `Metier` est refusée aussi, et pas par symétrie.
 * `TradeFallback` NE DOIT JAMAIS GRANDIR : ajouter un métier à ce fichier demanderait un
 * déploiement, ce que le référentiel existe précisément pour supprimer. Un sixième métier est une
 * LIGNE DE BASE. Sans ce contrôle, la première urgence commerciale ferait ajouter une entrée ici —
 * ça marcherait, et le lot serait mort sans que personne s'en aperçoive.
 *
 * ── LA TROISIÈME RÈGLE : UNE ENTRÉE SANS ACTIVITÉS ─────────────────────────────────────────────
 *
 * `entries()` lit `self::ACTIVITES[$code] ?? []`. Un code présent dans `NOMS` et absent d'`ACTIVITES`
 * produit donc une ligne semée SANS AUCUNE ACTIVITÉ — silencieusement. Pour les cinq métiers connus
 * ça ne se verrait pas (leurs modules viennent du préréglage), mais la ligne mentirait sur ce que
 * l'établissement fait, et l'écart mesuré par `website:trades:modules-diff` deviendrait faux.
 *
 * ── POURQUOI IL EST STATIQUE ───────────────────────────────────────────────────────────────────
 *
 * ⚠ AUCUN GARDE-FOU N'OUVRE DE BASE DE DONNÉES, et celui-ci ne fait pas exception : `pre-receive`
 * tourne sur un arbre extrait, sans application et sans base. Il ne peut donc pas vérifier qu'une
 * ligne EXISTE ; il vérifie que le métier a son entrée dans ce que la commande matérialise. C'est la
 * seule lecture exécutable ici — le sens qui lui échappe est couvert par un test d'intégration.
 */

$racine = \dirname(__DIR__);
$fichierEnum = $racine.'/app/src/Fonctionnalite/Enum/Metier.php';
$fichierRepli = $racine.'/app/src/Website/Config/TradeFallback.php';

if (!is_file($fichierEnum)) {
    fwrite(STDERR, "Métiers sans ligne : `Metier.php` introuvable — l'énumération des verticales ne disparaît pas.\n");
    exit(1);
}

/*
 * ⚠ L'ARBRE PEUT LEGITIMEMENT NE PAS PORTER LE REFERENTIEL, et ce n'est pas theorique : `pre-receive`
 *   travaille sur un arbre EXTRAIT, et le banc d'essai sur un clone dont `app/src` vient de `main`.
 *   Echouer la-dessus refuserait toutes les poussees, y compris celle qui apporte le fichier.
 *
 * ⚠ MAIS « ABSENT DONC RIEN A VERIFIER » EST LE PIEGE INVERSE : supprimer `TradeFallback.php`
 *   ferait taire ce controle pour toujours, et personne d'autre ne le rattrape. Je l'ai mesure
 *   plutot que suppose : fichier retire, toujours cite par trois fichiers, le garde-fou n°37
 *   (classes fantomes) reste VERT. La suppression n'est couverte par personne.
 *
 * D'ou une condition POSITIVE, pas un doute : absent ET CITE quelque part = refus. Absent et cite
 * par personne = hors sujet dans cet arbre, et on nomme ce qu'on n'a pas verifie.
 */
if (!is_file($fichierRepli)) {
    $citations = [];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine.'/app/src'));

    foreach ($rii as $fichier) {
        if ($fichier->isFile()
            && str_ends_with($fichier->getFilename(), '.php')
            && str_contains((string) file_get_contents($fichier->getPathname()), 'TradeFallback')
        ) {
            $citations[] = substr($fichier->getPathname(), \strlen($racine) + 1);
        }
    }

    if ([] === $citations) {
        printf(
            "Métiers sans ligne : non exécuté — cet arbre ne porte pas `TradeFallback.php`, et personne ne le cite.\n"
            ."  Il n'y a donc aucune liste de repli à comparer à l'énumération. Le contrôle redevient\n"
            ."  obligatoire dès que le fichier existe, ou dès qu'un fichier le cite.\n",
        );

        exit(0);
    }

    fwrite(STDERR, sprintf(
        "✗ Métiers sans ligne : `TradeFallback.php` a disparu, et %d fichier(s) le cite(nt) encore :\n    - %s\n\n"
        ."  Sans lui, une base sans aucune ligne ne sert AUCUN métier : la page d'accueil n'en liste\n"
        ."  plus, `/metiers` est vide, et `website:trades:seed` n'a plus rien à matérialiser.\n\n"
        ."  ⚠ Aucun autre contrôle ne rattrape ça — vérifié, pas supposé : le garde-fou n°37 reste vert\n"
        ."  sur un `TradeFallback.php` supprimé et toujours cité.\n",
        \count($citations),
        implode("\n    - ", $citations),
    ));

    exit(1);
}

/*
 * ⚠ ON LIT LES CAS, PAS LES VALEURS D'UN `match`. Un `match` sur `Metier` existe ailleurs et
 *   pourrait donner l'illusion d'une liste complète ; seuls les `case` font foi.
 */
preg_match_all(
    "/^\s*case\s+\w+\s*=\s*'([a-z0-9_]+)'\s*;/m",
    (string) file_get_contents($fichierEnum),
    $trouve,
);
$cas = $trouve[1];

$sourceRepli = (string) file_get_contents($fichierRepli);

/**
 * Les clés de premier niveau d'une constante-tableau.
 *
 * ⚠ ON DÉCOUPE LE BLOC AVANT DE LIRE. Chercher les clés dans tout le fichier mélangerait celles de
 * `NOMS` et celles d'`ACTIVITES` — les deux listes deviendraient impossibles à comparer l'une à
 * l'autre, et la troisième règle ne pourrait jamais tomber.
 *
 * @return list<string>
 */
$clesDe = static function (string $source, string $constante): array {
    $debut = strpos($source, 'const '.$constante.' = [');

    if (false === $debut) {
        fwrite(STDERR, sprintf(
            "Métiers sans ligne : la constante `%s` est introuvable dans TradeFallback — le motif de lecture a dû changer.\n",
            $constante,
        ));
        exit(1);
    }

    $fin = strpos($source, "\n    ];", $debut);

    if (false === $fin) {
        fwrite(STDERR, sprintf("Métiers sans ligne : la constante `%s` n'a pas de fin lisible.\n", $constante));
        exit(1);
    }

    preg_match_all("/^        '([a-z0-9_]+)' =>/m", substr($source, $debut, $fin - $debut), $trouve);

    return $trouve[1];
};

$entrees = $clesDe($sourceRepli, 'NOMS');
$activites = $clesDe($sourceRepli, 'ACTIVITES');

/*
 * ⚠ LE TÉMOIN DE L'INSTRUMENT, ET IL PASSE AVANT TOUTE COMPARAISON.
 *
 * Un analyseur cassé — motif qui ne mord plus, fichier déplacé, format changé — rend DEUX LISTES
 * VIDES, donc zéro écart, donc un vert. Un vert obtenu en ne mesurant rien. Le dépôt porte cinq cas
 * et cinq entrées de `NOMS`, et n'en portera jamais moins : en dessous, c'est l'outil qu'il faut réparer, pas le code
 * qu'il faut modifier.
 */
/*
 * ⚠ `ACTIVITES` N'A DELIBEREMENT PAS DE PLANCHER, et c'est un defaut trouve en cassant : avec le
 *   meme plancher de 5, retirer les activites d'un metier faisait bien refuser le push, mais avec le
 *   message « c'est l'analyseur qui est casse ». Le controle disait vrai sur le refus et faux sur la
 *   cause — il accusait `bin/` quand le defaut etait dans `app/src`. Un plancher a 5 rend
 *   inatteignable la regle qu'il surplombe.
 *
 *   Elle n'en a pas besoin : elle est lue par la MEME fonction, dans le MEME fichier que `NOMS`.
 *   Cinq cles lues dans `NOMS` prouvent que le motif mord. Un temoin positif vaut mieux qu'un
 *   plancher.
 */
foreach ([['cas de `Metier`', $cas], ['entrées de `NOMS`', $entrees]] as [$quoi, $liste]) {
    if (\count($liste) < 5) {
        fwrite(STDERR, sprintf(
            "✗ Métiers sans ligne : seulement %d %s lu(s), 5 au minimum attendus.\n\n"
            ."C'est l'analyseur qui est cassé, pas le dépôt : un motif de lecture qui ne mord plus rendrait\n"
            ."zéro écart, c'est-à-dire un vert obtenu en ne mesurant rien.\n",
            \count($liste),
            $quoi,
        ));

        exit(1);
    }
}

$sansEntree = array_values(array_diff($cas, $entrees));
$sansCas = array_values(array_diff($entrees, $cas));
$sansActivite = array_values(array_diff($entrees, $activites));

if ([] === $sansEntree && [] === $sansCas && [] === $sansActivite) {
    printf(
        "✓ Métiers : %d cas de `Metier`, %d entrée(s) de repli, chacune avec ses activités — les trois listes coïncident.\n",
        \count($cas),
        \count($entrees),
    );

    exit(0);
}

fwrite(STDERR, "✗ Métiers sans ligne : l'énumération et le référentiel de repli ne coïncident pas.\n\n");

if ([] !== $sansEntree) {
    fwrite(STDERR, sprintf(
        "  %d cas de `Metier` sans entrée dans `TradeFallback::NOMS` :\n    - %s\n\n"
        ."  Ce métier n'aurait de page dans AUCUN des deux états : absent des constantes que sert une\n"
        ."  base vide, et absent de ce que `website:trades:seed` matérialise, donc absent de la table.\n"
        ."  Le préréglage marcherait, l'établissement se créerait — et la page de vente n'existerait\n"
        ."  nulle part, sans erreur ni test rouge.\n\n",
        \count($sansEntree),
        implode("\n    - ", $sansEntree),
    ));
}

if ([] !== $sansCas) {
    fwrite(STDERR, sprintf(
        "  %d entrée(s) de `TradeFallback::NOMS` sans cas dans `Metier` :\n    - %s\n\n"
        ."  ⚠ `TradeFallback` NE GRANDIT JAMAIS. Un métier de plus est une LIGNE EN BASE, créée par\n"
        ."  l'administration ou par un import — pas une entrée dans ce fichier, qui demanderait un\n"
        ."  déploiement. C'est exactement ce que le référentiel existe pour supprimer.\n\n"
        ."  Si ce métier doit vraiment avoir un préréglage en dur, alors il lui faut son cas dans\n"
        ."  `Metier` ET son préréglage dans `PresetVerticale` — et c'est une décision produit, pas un\n"
        ."  ajout de ligne dans une constante.\n\n",
        \count($sansCas),
        implode("\n    - ", $sansCas),
    ));
}

if ([] !== $sansActivite) {
    fwrite(STDERR, sprintf(
        "  %d entrée(s) de `NOMS` sans activités dans `ACTIVITES` :\n    - %s\n\n"
        ."  `entries()` lit `ACTIVITES[\$code] ?? []` : ce métier serait semé SANS AUCUNE ACTIVITÉ, en\n"
        ."  silence. Sa page resterait juste — ses modules viennent du préréglage — mais sa ligne\n"
        ."  mentirait sur ce que l'établissement fait, et l'écart mesuré par\n"
        ."  `website:trades:modules-diff` serait faux.\n\n",
        \count($sansActivite),
        implode("\n    - ", $sansActivite),
    ));
}

exit(1);
