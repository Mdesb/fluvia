<?php

declare(strict_types=1);

/**
 * GARDE-FOU n°32 — UN FRONTAL QUI LIT UNE PROPRIÉTÉ QUE LE SERVEUR N'ENVOIE JAMAIS.
 *
 * ── LE DÉFAUT, ET POURQUOI RIEN NE L'ATTRAPAIT ─────────────────────────────────────────────────
 *
 * `Reservation::$ressourceAffectee` porte le groupe `reservation:read`. `Ressource::$libelle` porte
 * `ressource:read` et `creneau:read` — pas `reservation:read`. La relation, sérialisée sous le
 * groupe d'une réservation, ne rend donc **que son identifiant**, et l'écran qui écrivait
 * `r.ressourceAffectee.libelle` affichait « Affectée : undefined » — précisément l'écran qui sert à
 * savoir quelle chambre a été donnée.
 *
 * ⚠ **Le champ existe, la relation existe, le groupe existe. C'est la COMBINAISON qui manque**, et
 * aucun des trois éléments n'est fautif isolément. C'est le jumeau, en lecture, de ce que le dépôt
 * savait déjà en écriture : les groupes de sérialisation ne disent pas tout, et un contrôle qui
 * regarde une seule des trois pièces ne voit rien.
 *
 * Trouvé en vérifiant une ligne avant de la livrer, pas par un test : aucun test n'échoue sur
 * `undefined` affiché dans une chaîne — il devient le mot « undefined » à l'écran.
 *
 * ── ⚠ LA QUESTION SE POSE DU FRONTAL VERS LE SERVEUR, ET C'EST CE QUI LA REND DÉCIDABLE ────────
 *
 * La première version partait du serveur : « cette relation est un IRI nu, le frontal la
 * déréférence-t-il ? ». **327 résultats, presque tous faux** — `.etablissement.` dans un fichier
 * frontal n'appartient pas forcément à une `RemiseSepa`, et le savoir demanderait d'inférer le type
 * d'un objet en JavaScript dynamique.
 *
 * Retournée, la question se décide sans inférence, par un quantificateur universel :
 *
 *     le frontal lit `.X.Y`
 *     on rassemble TOUTES les sources possibles d'une propriété nommée X
 *     si AUCUNE ne rend Y lisible → `.X.Y` vaut `undefined`, quelle que soit l'origine de l'objet
 *
 * On n'a pas besoin de savoir d'où vient l'objet **si l'ensemble des origines est complet**.
 *
 * ⚠ J'AI ÉCRIT « ZÉRO FAUX POSITIF PAR CONSTRUCTION », ET C'ÉTAIT FAUX. Deux des trois premiers
 * résultats étaient de fausses accusations, trouvées par `allaccess-8e` en moins d'une heure. Mon
 * ensemble d'origines était trop étroit de deux façons :
 *
 *   · **Le pluriel.** `.grille.typeTarif` venait de `Produit::$grilles`, pas de
 *     `PrixHistorique::$grille`. Une boucle sur `grilles` nomme son élément `grille`. Réparé : les
 *     noms singularisés entrent dans l'ensemble.
 *
 *   · **La résolution locale.** `TopologieAcces.jsx` charge la collection des contrôleurs et résout
 *     par identifiant (`parId.get(...)`) : la variable s'appelle `controleur` sans être la relation
 *     d'une autre entité, et les groupes à consulter sont ceux de SA collection. Motif fréquent ici,
 *     et pour une bonne raison — les relations arrivent en IRI, donc les écrans compensent.
 *     **Non réparable sans analyse de flot** : `l.controleur?.itboxRef` et
 *     `r.ressourceAffectee.libelle` ont exactement la même allure.
 *
 *   · **La collection de premier niveau.** Généralisation des deux précédents, relevée par
 *     `allaccess-73` : le quantificateur parcourt les RELATIONS nommées X, et un objet peut venir
 *     d'une collection chargée directement — où la ressource expose bien davantage, sous son propre
 *     groupe. Deux des trois premières entrées étaient de ce type.
 *
 * D'où le marqueur `@lecture-locale: <relation>.<propriété>` — que `allaccess-73` avait proposé et
 * que j'avais écarté en croyant le contrôle exact. Leur avertissement visait juste avant que le
 * défaut n'existe : « sinon il refusera des choses justes et finira désactivé ».
 *
 * ⚠ **LE PLAFOND NE DIT PAS COMBIEN DE DÉFAUTS RESTENT, ET IL NE FAUT PAS LE LIRE AINSI.** Il a
 * valu 3 alors que deux des trois étaient des accusations fausses. Ce qu'il compte, ce sont des
 * lectures que le contrôle **n'a pas su innocenter** — ce qui est autre chose. Chaque entrée se
 * vérifie à la main avant d'être crue, et la façon de le faire est celle qu'ont employée `73` et
 * `8e` : remonter d'où l'objet vient dans l'écran, et lire le contrat que le serveur publie
 * (`/api/contexts/<Ressource>`, avec un témoin négatif sur un nom inexistant).
 *
 * ⚠ **ET UN CONTRÔLE QUI A RAISON POUR LA MAUVAISE RAISON AURA TORT LE JOUR OÙ ELLE DISPARAÎT.**
 * `TopologieAcces.jsx` cesse d'être accusé parce qu'un homonyme — `SynchronisationAcces::$controleurs`,
 * un champ libre de DTO — entre dans les origines plausibles. Correct par conservatisme, pas par
 * compréhension. Le marqueur y est posé pour cette raison précise.
 *
 * ── CE QUE LE CONTRÔLE AFFIRME, ET CE QU'IL N'AFFIRME PAS ─────────────────────────────────────
 *
 * Il affirme : **cette lecture rend toujours `undefined`**. C'est une propriété du code, pas un
 * jugement.
 *
 * Il n'affirme pas que c'est un défaut. `Piscine.jsx` écrit
 * `texte(r.bassin?.libelle, String(r.bassin||'').split('/').pop())` : l'auteur savait, et a posé un
 * repli qui affiche l'identifiant. L'écran est dégradé, pas cassé — et c'est à qui tient l'écran
 * d'en décider, pas à ce script.
 *
 * D'où un **cliquet** et non un refus sec : la dette d'aujourd'hui est gelée, et ce qui est refusé
 * est son augmentation.
 */

const PLAFOND_FICHIER = __DIR__ . '/relations-nues.plafond.txt';

$racine = getcwd();
$mode = $argv[1] ?? '';

// ── 1. Les classes, leurs propriétés, leurs groupes ─────────────────────────────────────────────
/** @var array<string, array<string, list<string>>> $proprietes */
$proprietes = [];
/** @var list<array{classe:string,propriete:string,cible:string,groupes:list<string>}> $relations */
$relations = [];
/** @var array<string, true> $champsLibres */
$champsLibres = [];

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine . '/app/src', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || !str_ends_with($f->getFilename(), '.php')) {
        continue;
    }
    $src = file_get_contents($f->getPathname());
    if (!preg_match('/^(?:final\s+)?(?:readonly\s+)?class\s+(\w+)/m', $src, $mc)) {
        continue;
    }
    $classe = $mc[1];
    $lignes = explode("\n", $src);
    $proprietes[$classe] ??= [];

    $groupes = [];
    $cible = null;

    foreach ($lignes as $i => $ligne) {
        if (preg_match("/Groups\(\[([^\]]*)\]\)/", $ligne, $mg)) {
            if (preg_match_all("/'([^']+)'/", $mg[1], $g)) {
                foreach ($g[1] as $gr) {
                    $groupes[] = $gr;
                }
            }
            continue;
        }

        if (preg_match('/ORM\\\\(?:ManyToOne|OneToOne|OneToMany|ManyToMany)\(/', $ligne)) {
            $bloc = $ligne . ($lignes[$i + 1] ?? '') . ($lignes[$i + 2] ?? '');
            if (preg_match('/targetEntity:\s*(\w+)::class/', $bloc, $mt)) {
                $cible = $mt[1] === 'self' ? $classe : $mt[1];
            }
            continue;
        }

        if (preg_match('/^\s*(?:private|protected|public)\s+(?:readonly\s+)?[\w\\\\|?<>,\s]+\s+\$(\w+)\s*(?:=|;)/', $ligne, $mp)) {
            $nom = $mp[1];
            $proprietes[$classe][$nom] = array_values(array_unique($groupes));

            if ($cible !== null) {
                $relations[] = [
                    'classe' => $classe,
                    'propriete' => $nom,
                    'cible' => $cible,
                    'groupes' => array_values(array_unique($groupes)),
                ];
            } else {
                // ⚠ TOUTE PROPRIÉTÉ QUI N'EST PAS UNE RELATION REND LA LECTURE PLAUSIBLE, et il faut
                // le tolérer. `.pmv.solde` était signalé parce que la seule RELATION nommée `pmv`
                // est `MouvementPmv::$pmv` — or l'objet vient de `api.ficheClient()`, un DTO dont
                // `$pmv` est un tableau libre. Sans cette porte, le contrôle accuse des écrans justes.
                $champsLibres[$nom] = true;
            }

            $groupes = [];
            $cible = null;
            continue;
        }

        if (trim($ligne) === '' || preg_match('/^\s*(?:public|private|protected)\s+function/', $ligne)) {
            $groupes = [];
            $cible = null;
        }
    }
}

/** @var array<string, list<int>> $parNom */
$parNom = [];
foreach ($relations as $k => $r) {
    $parNom[$r['propriete']][] = $k;
}

/**
 * ⚠ RETIRER LES COMMENTAIRES N'EST PAS UN DÉTAIL.
 *
 * Sans cela, la première exécution a trouvé `.ressourceAffectee.libelle` dans le commentaire qui
 * explique que le défaut est corrigé. **Un instrument qui lit ses propres explications confirme
 * tout ce qu'on y écrit** — et il l'aurait fait en silence, avec un résultat plausible.
 */
function sansCommentaires(string $src): string
{
    $src = preg_replace('#/\*.*?\*/#s', ' ', $src) ?? $src;

    return implode("\n", array_map(
        static fn (string $l): string => preg_replace('#(?<![:\w])//.*$#', '', $l) ?? $l,
        explode("\n", $src),
    ));
}

// ── 2. Le frontal ───────────────────────────────────────────────────────────────────────────────
$defauts = [];
$it2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine . '/frontend/src', FilesystemIterator::SKIP_DOTS));
foreach ($it2 as $f) {
    if (!$f->isFile() || !preg_match('/\.(jsx?|mjs)$/', $f->getFilename())) {
        continue;
    }
    $chemin = str_replace($racine . '/', '', $f->getPathname());
    $brut = file_get_contents($f->getPathname());
    $src = sansCommentaires($brut);

    if (!preg_match_all('/\.(\w+)\??\.(\w+)\b/', $src, $tous, PREG_SET_ORDER)) {
        continue;
    }

    // ⚠ LA PORTE, ET POURQUOI ELLE EST INDISPENSABLE.
    //
    // Un objet peut venir d'une RESOLUTION LOCALE : l'ecran charge une collection a part et resout
    // par identifiant (`parId.get(...)`) — motif frequent ici, precisement parce que les relations
    // arrivent en IRI et que les ecrans compensent. La variable porte alors le nom d'une relation
    // sans en etre une, et les groupes a consulter sont ceux de SA collection.
    //
    // Aucune forme textuelle ne separe les deux cas : `l.controleur?.itboxRef` et
    // `r.ressourceAffectee.libelle` ont exactement la meme allure. Les distinguer demanderait de
    // suivre l'origine de la variable.
    //
    // On laisse donc une porte, et on exige qu'elle soit MOTIVEE — une ligne de commentaire dans le
    // fichier concerne :
    //
    //     // @lecture-locale: controleur.itboxRef  resolu depuis api.controleursAcces(), l.1120
    //
    // 73 l'avait demandee des le depart. Je l'avais ecartee en croyant le controle exact ; il ne
    // l'etait pas, et leur avertissement disait exactement pourquoi : « sinon il refusera des choses
    // justes et finira desactive ».
    $marques = [];
    if (preg_match_all('/@lecture-locale:\s*([\w.]+)/', $brut, $mm)) {
        foreach ($mm[1] as $marque) {
            $marques[$marque] = true;
        }
    }

    $vus = [];
    foreach ($tous as [$_, $rel, $sous]) {
        $cle = $rel . '.' . $sous;
        if (isset($vus[$cle]) || isset($marques[$cle])) {
            continue;
        }
        $vus[$cle] = true;

        // ⚠ LE PLURIEL COMPTE. `.grille.typeTarif` etait accuse parce que la seule relation nommee
        // `grille` est `PrixHistorique::$grille` [prix:read] — or l'objet vient de `p.grilles`, la
        // relation PLURIELLE de `Produit`, ou `typeTarif` est bien expose. Une boucle sur `grilles`
        // nomme naturellement son element `grille`, et ne pas le voir accuse un ecran juste.
        $noms = [$rel, $rel . 's', $rel . 'x'];
        $origines = [];
        foreach ($noms as $n) {
            if (isset($champsLibres[$n])) {
                $origines = null;
                break;
            }
            foreach ($parNom[$n] ?? [] as $k) {
                $origines[] = $k;
            }
        }

        if ($origines === null || $origines === []) {
            continue;
        }

        $plausible = false;
        $candidats = [];
        foreach ($origines as $k) {
            $r = $relations[$k];
            $candidats[] = $r;

            $lecture = array_values(array_filter(
                $r['groupes'],
                static fn (string $g): bool => !str_ends_with($g, ':write'),
            ));
            $cible = $proprietes[$r['cible']] ?? null;

            // Aucun groupe de lecture, cible hors `app/src`, ou propriété absente de la cible :
            // trois raisons de ne pas conclure. Le contrôle ne parle que de ce qu'il sait.
            if ($lecture === [] || $cible === null || !isset($cible[$sous])) {
                $plausible = true;
                break;
            }
            if (array_intersect($lecture, $cible[$sous]) !== []) {
                $plausible = true;
                break;
            }
        }

        if (!$plausible) {
            $defauts[] = ['fichier' => $chemin, 'lecture' => $cle, 'candidats' => $candidats];
        }
    }
}

usort($defauts, static fn (array $a, array $b): int => [$a['fichier'], $a['lecture']] <=> [$b['fichier'], $b['lecture']]);

// ── 3. Verdict ──────────────────────────────────────────────────────────────────────────────────
$plafond = is_file(PLAFOND_FICHIER) ? (int) trim(file_get_contents(PLAFOND_FICHIER)) : PHP_INT_MAX;
$compte = \count($defauts);

if ($mode === '--geler') {
    if (!is_file(PLAFOND_FICHIER) || $compte < $plafond) {
        file_put_contents(PLAFOND_FICHIER, $compte . "\n");
        printf("✓ Plafond des lectures indéfinies fixé à %d.\n", $compte);
        exit(0);
    }
    printf("✗ Refus : le plafond est %d, la mesure %d. Un plafond qui remonte n'est plus un cliquet.\n", $plafond, $compte);
    exit(1);
}

if ($compte > $plafond) {
    echo "\n=== ÉCHEC — une lecture de plus rend toujours `undefined` ===\n\n";
    foreach ($defauts as $d) {
        printf("  %s\n    lit  .%s\n", $d['fichier'], $d['lecture']);
        foreach ($d['candidats'] as $c) {
            printf("      %s::\$%s → %s, groupes [%s]\n", $c['classe'], $c['propriete'], $c['cible'], implode(', ', $c['groupes']));
        }
    }
    echo "\nLa propriété lue n'est dans AUCUN des groupes que porte la relation : le serveur envoie\n";
    echo "un identifiant nu, et cette lecture vaut `undefined` — quel que soit l'objet d'où elle vient.\n\n";
    echo "TROIS SORTIES.\n";
    echo "  1. Ajouter le groupe de la relation aux `Groups` de la propriété visée, côté serveur.\n";
    echo "     ⚠ Cela alourdit TOUTES les lectures de l'entité porteuse, partout. À réserver aux cas\n";
    echo "     où plusieurs écrans en ont besoin.\n";
    echo "  2. Résoudre le libellé côté frontal depuis une liste déjà chargée. Sans coût réseau, et\n";
    echo "     sans toucher au contrat de l'API.\n";
    echo "  3. Poser un repli explicite si l'identifiant nu suffit — `Piscine.jsx` le fait :\n";
    echo "     `texte(r.bassin?.libelle, String(r.bassin||'').split('/').pop())`. L'écran est alors\n";
    echo "     dégradé et non cassé, et le cliquet peut être regelé en connaissance de cause.\n";
    printf("\nMesure : %d · plafond : %d\n", $compte, $plafond);
    exit(1);
}

printf(
    "Lectures indéfinies : OK — %d lecture(s) toujours `undefined`, plafond %d.%s\n",
    $compte,
    $plafond,
    $compte < $plafond ? sprintf(' (%d de marge : pense à geler.)', $plafond - $compte) : '',
);
exit(0);
