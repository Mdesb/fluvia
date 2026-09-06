<?php

declare(strict_types=1);

/*
 * GARDE-FOU — une tâche que l'ordonnanceur lance et que le catalogue ne connaît pas.
 *
 * ── CE QU'IL EXISTE POUR ATTRAPER ──────────────────────────────────────────────────────────────
 *
 * Le 06/09, `reservation:confirmations:expirer` figurait dans `TACHES_AUTORISEES`
 * (`infra/ordonnanceur.sh`) et manquait à `ScheduleCatalog`. Mesuré, pas déduit :
 *
 *     platform:scheduler:run --only=reservation:confirmations:expirer   aucune sortie, code 0
 *     platform:scheduler:run --only=tache:qui:nexiste:pas               aucune sortie, code 0
 *     platform:scheduler:run --status                                   27 tâches, celle-ci ABSENTE
 *
 * L'ordonnanceur appelle `--only=<nom>` pour chaque nom de sa liste. Le lanceur filtrait par égalité
 * de nom : un nom absent du catalogue sautait chaque tour et la commande se terminait « avec
 * succès ». L'ordonnanceur recevait 0 et écrivait « ok ». Rien ne tournait.
 *
 * ⚠ ET `--status` NE POUVAIT PAS LE DIRE : il ne lit que le catalogue, donc la tâche n'y apparaissait
 *   même pas comme « JAMAIS ». Invisible des deux côtés à la fois — c'est ça qui coûte, pas l'oubli.
 *
 * Le lanceur échoue désormais bruyamment sur un `--only` inconnu. Ce garde-fou est l'autre moitié :
 * il refuse la dérive AU COMMIT, avant qu'elle ne tourne une seule fois. Les deux ne se remplacent
 * pas — le lanceur protège l'exécution, celui-ci protège le dépôt.
 *
 * ── UN SEUL SENS, ET LE CHOIX EST DÉLIBÉRÉ ─────────────────────────────────────────────────────
 *
 * Refusé : un nom dans la liste blanche, absent du catalogue. Il ne tournera JAMAIS, en silence.
 *
 * Toléré : une tâche du catalogue absente de la liste blanche. C'est l'état délibéré de 17 tâches
 * sur 28 — elles ne sont pas prêtes, pas sûres au premier passage, ou pas voulues. Et cette
 * absence-là est déjà bruyante : `--status` les affiche « JAMAIS ». Refuser ce sens rendrait le
 * garde-fou rouge dès sa naissance, et on l'aurait désactivé le lendemain.
 *
 * ── POURQUOI LE TOKENIZER ET PAS UNE EXPRESSION RÉGULIÈRE ──────────────────────────────────────
 *
 * ⚠ MA PREMIÈRE VERSION LISAIT `new ScheduledTask\(\s*'([a-z0-9:_.-]+)'` ET A ACCUSÉ UNE TÂCHE SAINE.
 *   `sport:abonnements:traiter-terme` est déclarée `command: 'sport:...'` — un ARGUMENT NOMMÉ — et
 *   d'autres entrées portent un commentaire entre la parenthèse et leur premier argument. Une
 *   expression régulière qui ré-implémente la syntaxe de PHP se trompe sur la première forme qu'elle
 *   n'a pas prévue, et elle se trompe en ACCUSANT : le faux positif d'un garde-fou coûte la
 *   confiance qu'on lui accorde. `token_get_all()` est l'analyseur de PHP lui-même ; il connaît les
 *   arguments nommés et les commentaires sans qu'on ait à les décrire.
 */

$racine = \dirname(__DIR__);
$ordonnanceur = $racine.'/infra/ordonnanceur.sh';
$catalogue = $racine.'/app/src/Platform/Scheduling/ScheduleCatalog.php';

foreach (['ordonnanceur' => $ordonnanceur, 'catalogue' => $catalogue] as $quoi => $chemin) {
    if (!is_file($chemin)) {
        fwrite(STDERR, sprintf(
            "Tâches planifiées fantômes : %s introuvable (%s) — ce contrôle ne peut rien affirmer.\n",
            $quoi,
            $chemin,
        ));
        exit(1);
    }
}

/**
 * Les noms de la liste blanche de l'ordonnanceur.
 *
 * @return list<string>
 */
function listeBlanche(string $source): array
{
    if (1 !== preg_match('/^TACHES_AUTORISEES="([^"]*)"/m', $source, $trouve)) {
        return [];
    }

    return array_values(array_filter(preg_split('/\s+/', trim($trouve[1])) ?: []));
}

/**
 * Les noms déclarés au catalogue, lus par l'analyseur de PHP.
 *
 * On cherche `new ScheduledTask` puis le premier littéral chaîne qui suit, en laissant le tokenizer
 * écarter de lui-même les espaces, les commentaires et l'étiquette d'un argument nommé. On exige
 * `new` juste avant : sans ça, un type d'argument (`ScheduledTask $a`) serait pris pour une
 * déclaration.
 *
 * @return list<string>
 */
function auCatalogue(string $source): array
{
    $noms = [];
    $jetons = token_get_all($source);
    $total = \count($jetons);
    $ignorables = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    for ($i = 0; $i < $total; ++$i) {
        $jeton = $jetons[$i];
        if (!\is_array($jeton) || T_STRING !== $jeton[0] || 'ScheduledTask' !== $jeton[1]) {
            continue;
        }

        // Le mot-clé `new` doit précéder, en sautant ce qui ne compte pas.
        $precedent = null;
        for ($k = $i - 1; $k >= 0; --$k) {
            if (\is_array($jetons[$k]) && \in_array($jetons[$k][0], $ignorables, true)) {
                continue;
            }
            $precedent = $jetons[$k];
            break;
        }
        if (!\is_array($precedent) || T_NEW !== $precedent[0]) {
            continue;
        }

        for ($j = $i + 1; $j < $total; ++$j) {
            $suivant = $jetons[$j];

            if (\is_array($suivant)) {
                if (\in_array($suivant[0], $ignorables, true)) {
                    continue;
                }
                // L'étiquette d'un argument nommé : `command:`.
                if (T_STRING === $suivant[0]) {
                    continue;
                }
                if (T_CONSTANT_ENCAPSED_STRING === $suivant[0]) {
                    $noms[] = trim($suivant[1], "'\"");
                }

                break;
            }

            if ('(' === $suivant || ':' === $suivant) {
                continue;
            }

            break;
        }
    }

    return array_values(array_unique($noms));
}

/**
 * Les noms lancés par l'ordonnanceur que le catalogue ne connaît pas.
 *
 * @param list<string> $blanche
 * @param list<string> $connues
 *
 * @return list<string>
 */
function fantomes(array $blanche, array $connues): array
{
    return array_values(array_diff($blanche, $connues));
}

// ── Les témoins : on prouve que le contrôle LIT, qu'il VOIT, et ce qu'il ÉPARGNE ───────────────
$temoins = 0;
$epargnes = 0;

/*
 * ⚠ CE TÉMOIN-CI EST CELUI QUI MANQUAIT. Les premiers ne testaient que le comparateur d'ensembles —
 * « une différence est-elle vue ? » — et le comparateur était juste. C'est le LECTEUR qui était
 * faux, et aucun témoin ne le regardait. Il porte donc sur les trois formes réellement présentes au
 * catalogue : le littéral nu, l'argument nommé, et le commentaire intercalé.
 */
$echantillon = <<<'PHP'
    <?php
    return [
        new ScheduledTask('a:litteral:nu', 15, "peu importe"),
        new ScheduledTask(
            command: 'b:argument:nomme',
            everyMinutes: 60,
        ),
        // Un commentaire avant.
        new ScheduledTask(
            // Et un commentaire APRES la parenthese.
            'c:commentaire:intercale',
            1440,
        ),
    ];
    PHP;

$lus = auCatalogue($echantillon);
foreach (['a:litteral:nu', 'b:argument:nomme', 'c:commentaire:intercale'] as $attendu) {
    if (!\in_array($attendu, $lus, true)) {
        fwrite(STDERR, sprintf(
            "Témoin de LECTURE : la forme « %s » n'est pas lue par `auCatalogue()`.\n"
            ."Lu : %s\n"
            ."Une forme de déclaration non reconnue fait ACCUSER une tâche saine.\n",
            $attendu,
            implode(', ', $lus) ?: '(rien)',
        ));
        exit(1);
    }
}
++$temoins;

// Il voit un nom lancé qui n'est pas au catalogue — le défaut du 06/09, reconstitué.
if (['reservation:confirmations:expirer'] !== fantomes(
    ['securite:delegations:expirer', 'reservation:confirmations:expirer'],
    ['securite:delegations:expirer', 'vente:cloture:journee'],
)) {
    fwrite(STDERR, "Témoin : le contrôle ne voit pas un nom lancé absent du catalogue.\n");
    exit(1);
}
++$temoins;

// Il voit plusieurs fantômes à la fois, et les rend tous.
if (2 !== \count(fantomes(['a:b', 'c:d', 'e:f'], ['c:d']))) {
    fwrite(STDERR, "Témoin : le contrôle ne rend pas tous les fantômes.\n");
    exit(1);
}
++$temoins;

// ── Ce qu'il ÉPARGNE — un détecteur se prouve autant par ses silences ──────────────────────────

// Une tâche du catalogue absente de la liste blanche : c'est l'état voulu de 17 tâches. Épargnée.
if ([] !== fantomes(['securite:delegations:expirer'], ['securite:delegations:expirer', 'dms:purge-expired-documents'])) {
    fwrite(STDERR, "Témoin : le contrôle accuse une tâche catalogue non lancée, qu'il doit épargner.\n");
    exit(1);
}
++$epargnes;

// Les deux listes identiques à l'ordre près : rien à signaler.
if ([] !== fantomes(['a:b', 'c:d'], ['c:d', 'a:b'])) {
    fwrite(STDERR, "Témoin : le contrôle accuse deux listes pourtant équivalentes (ordre différent).\n");
    exit(1);
}
++$epargnes;

// Un nom cité dans un COMMENTAIRE ne vaut pas une déclaration — le tokenizer doit l'écarter.
if ([] !== auCatalogue("<?php // new ScheduledTask('z:cite:en:commentaire')\n")) {
    fwrite(STDERR, "Témoin : un nom cité en commentaire est pris pour une déclaration.\n");
    exit(1);
}
++$epargnes;

// ── La lecture réelle ──────────────────────────────────────────────────────────────────────────
$blanche = listeBlanche((string) file_get_contents($ordonnanceur));
$connues = auCatalogue((string) file_get_contents($catalogue));

/*
 * ⚠ UNE LISTE VIDE N'EST PAS UN VERT, C'EST UN INSTRUMENT CASSÉ. Si un motif de lecture cesse de
 * correspondre — la variable renommée, la classe remplacée par une fabrique — les deux ensembles
 * deviennent vides, leur différence aussi, et ce contrôle passerait au vert en ne mesurant plus
 * rien. On exige donc un témoin positif de chaque côté : un nom qu'on sait présent.
 */
if ([] === $blanche) {
    fwrite(STDERR,
        "Tâches planifiées fantômes : aucune tâche lue dans `TACHES_AUTORISEES`.\n"
        ."Le motif de lecture a dû changer dans `infra/ordonnanceur.sh`. Un ensemble vide rendrait ce\n"
        ."contrôle vert sans qu'il mesure quoi que ce soit : il refuse plutôt que de mentir.\n",
    );
    exit(1);
}

if (!\in_array('vente:cloture:journee', $connues, true)) {
    fwrite(STDERR,
        "Tâches planifiées fantômes : `vente:cloture:journee` est introuvable au catalogue.\n"
        ."C'est le témoin positif de la lecture — cette tâche existe depuis l'origine du fichier. Son\n"
        ."absence signifie que la lecture du catalogue ne correspond plus, pas que le catalogue est\n"
        ."vide. Sans ce témoin, la différence d'ensembles serait vide et le contrôle vert à tort.\n",
    );
    exit(1);
}

// ── Le verdict ─────────────────────────────────────────────────────────────────────────────────
$manquantes = fantomes($blanche, $connues);

if ([] !== $manquantes) {
    fwrite(STDERR, sprintf(
        "✗ Tâches planifiées fantômes : %d tâche(s) lancée(s) par l'ordonnanceur et absente(s) du catalogue.\n\n",
        \count($manquantes),
    ));

    foreach ($manquantes as $nom) {
        fwrite(STDERR, sprintf("  - %s\n", $nom));
    }

    fwrite(STDERR,
        "\n`infra/ordonnanceur.sh` lance `platform:scheduler:run --only=<nom>` pour chacun de ces noms.\n"
        ."Le lanceur refuse désormais un nom inconnu, donc l'ordonnanceur écrira ÉCHEC à chaque cycle.\n"
        ."Et `--status` ne pourra pas aider : il ne connaît que le catalogue, donc ces tâches n'y\n"
        ."apparaîtront même pas comme « JAMAIS ».\n\n"
        ."Soit la tâche entre dans `app/src/Platform/Scheduling/ScheduleCatalog.php`, soit son nom sort\n"
        ."de `TACHES_AUTORISEES`. La laisser dans une seule des deux listes est la seule option qui\n"
        ."donne l'apparence d'une tâche planifiée sans qu'elle tourne jamais.\n",
    );

    exit(1);
}

printf(
    "✓ Tâches planifiées : %d lancée(s) par l'ordonnanceur, toutes au catalogue de %d. (%d témoins passés, dont %d qui prouvent ce qu'il épargne.)\n",
    \count($blanche),
    \count($connues),
    $temoins + $epargnes,
    $epargnes,
);
