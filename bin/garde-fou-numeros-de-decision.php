<?php

declare(strict_types=1);

/**
 * GARDE-FOU n°46 — UN NUMÉRO DE DÉCISION EST UNE POIGNÉE, PAS UNE ÉTIQUETTE.
 *
 * ── CE QUI EST EN JEU ───────────────────────────────────────────────────────────────────────────
 *
 * Mesure du 02/09 : **887 citations de `D<n>` dans `app/src` seul**, pour 122 décisions. Un
 * commentaire qui dit « voir D109 » ne recopie pas la décision : il pointe vers elle. Changer ce
 * qu'un numéro désigne — le réattribuer, renuméroter, réécrire le titre pour dire autre chose —
 * modifie donc silencieusement le sens de centaines de commentaires que personne ne relira.
 *
 * Et le défaut est invisible par construction : aucun test ne tombe, aucun build ne rougit. Le code
 * continue de citer un numéro qui ne dit plus la même chose.
 *
 * ── ⚠ POURQUOI CE CONTRÔLE EXISTE : J'AI MOI-MÊME ANNONCÉ 14 DOUBLONS QUI N'EXISTAIENT PAS ──────
 *
 * Le 01/09, j'ai compté quatorze numéros en double et je l'ai dit à trois sessions et dans un
 * message de commit **avant de vérifier**. Il y en avait ZÉRO. Mon expression régulière prenait le
 * DERNIER `D<n>` de la ligne de titre : sur un titre qui cite une autre décision, elle lisait la
 * citation au lieu du numéro.
 *
 * C'est pourquoi ce fichier prend le PREMIER numéro après la date, et pourquoi il annonce combien
 * de titres il a lus : un contrôle sur les numéros qui se trompe de numéro est pire que rien.
 *
 * ── CE QU'IL GÈLE, ET CE QU'IL LAISSE PASSER ────────────────────────────────────────────────────
 *
 * La ligne de base associe chaque numéro à une empreinte de son titre. Le contrôle refuse :
 *
 *   - deux décisions portant le même numéro ;
 *   - un numéro connu dont le titre a changé de sens ;
 *   - un numéro connu qui a DISPARU (une décision ne se supprime pas : elle se rectifie) ;
 *   - un numéro neuf non figé — pour que l'ajout soit un geste, pas une dérive.
 *
 * ⚠ L'EMPREINTE IGNORE LES CITATIONS, ET C'EST DÉLIBÉRÉ. Un titre qui dit « remplace D91 » doit
 * pouvoir corriger cette référence sans que le contrôle crie : ce qui est gelé est ce que la
 * décision DÉCIDE, pas ce vers quoi elle pointe. Affinement proposé par `allaccess-8e`.
 *
 * ⚠ ET LA CONVENTION `-bis` EST RECONNUE. `D7-bis`, `D33-bis`, `D66-ter` sont des décisions à part
 * entière : elles complètent sans remplacer. Les confondre avec leur base ferait déclarer un doublon
 * sur cinq décisions parfaitement valides — le contrôle deviendrait alors la chose qu'il empêche.
 */

const FICHIER = 'COORDINATION/DECISIONS.md';
const LIGNE_DE_BASE = 'bin/decisions.ligne-de-base.json';

/**
 * Le PLANCHER : en dessous, on n'a pas mesuré.
 *
 * ⚠ CE CONTRÔLE REND LE MÊME VERT QUAND IL NE TROUVE AUCUN DOUBLON ET QUAND IL NE TROUVE AUCUNE
 * DÉCISION. Un titre qui change de forme, un fichier déplacé, et l'expression régulière ne
 * correspond plus : zéro décision lue, zéro doublon, tout va bien. C'est la panne qu'on ne voit pas.
 */
const PLANCHER = 80;

/**
 * Extrait les décisions d'un contenu Markdown.
 *
 * Formes acceptées, toutes présentes dans le fichier :
 *
 *     ### 2026-08-19 · D1 — Convergence en socle unique
 *     ### 2026-08-24 20:45 · D33-bis — La quantité ne se déduit pas des participants
 *     ## D91 — Un produit publié ne peut pas perdre son dernier prix
 *
 * @return array<string, list<array{ligne: int, titre: string}>>
 */
function decisions(string $contenu): array
{
    $trouvees = [];
    $lignes = explode("\n", $contenu);

    foreach ($lignes as $i => $ligne) {
        // ⚠ LE PREMIER `D<n>` APRÈS LA DATE, JAMAIS LE DERNIER. Voir le docblock de tête : c'est la
        // faute exacte qui m'a fait annoncer quatorze doublons inexistants.
        $motif = '/^#{2,4}\s+(?:\d{4}-\d{2}-\d{2}(?:\s+\d{2}:\d{2})?\s+·\s+)?D(\d+)(-[a-z]+)?\s+—\s+(.+?)\s*$/u';

        if (preg_match($motif, $ligne, $m) !== 1) {
            continue;
        }

        $cle = 'D' . $m[1] . $m[2];
        $trouvees[$cle][] = ['ligne' => $i + 1, 'titre' => $m[3]];
    }

    return $trouvees;
}

/**
 * L'empreinte d'un titre : ce qu'il DÉCIDE, débarrassé de ce vers quoi il pointe.
 *
 * Les citations `D<n>` sont retirées, la casse et les espaces normalisés. Corriger une référence
 * croisée dans un titre ne doit pas faire crier un contrôle qui garde le sens.
 */
function empreinte(string $titre): string
{
    $sans = preg_replace('/\bD\d+(-[a-z]+)?\b/u', '', $titre) ?? $titre;
    $normalise = preg_replace('/\s+/u', ' ', mb_strtolower(trim($sans))) ?? $sans;

    return substr(sha1($normalise), 0, 12);
}

// ── LES TÉMOINS ─────────────────────────────────────────────────────────────────────────────────
//
// ⚠ DEUX MOITIÉS. Un extracteur qui ne trouve rien rend un vert parfait ; un extracteur trop large
// déclare des doublons partout. Les deux se lisent comme « le contrôle a tourné ».
function temoins(): array
{
    $passes = [];

    $echantillon = implode("\n", [
        '### 2026-08-19 · D1 — Convergence en socle unique',
        '### 2026-08-24 20:45 · D33-bis — La quantite ne se deduit pas',
        '## D91 — Un produit publie garde son prix',
        'Corps de texte citant D1 et D91, qui ne doit RIEN produire.',
        '**Rectifie D1**, en gras, dans le corps.',
    ]);

    $lues = decisions($echantillon);

    if (count($lues) !== 3) {
        return ['ok' => false, 'quoi' => sprintf('lu %d decision(s) sur 3 dans l echantillon', count($lues))];
    }
    $passes[] = 'lit les trois formes de titre';

    if (!isset($lues['D33-bis'])) {
        return ['ok' => false, 'quoi' => 'la convention -bis n est pas reconnue'];
    }
    $passes[] = 'reconnait la convention -bis';

    // ⚠ CE QU'IL DOIT ÉPARGNER. Une citation dans le corps n'est pas une décision.
    if (isset($lues['D1']) && count($lues['D1']) !== 1) {
        return ['ok' => false, 'quoi' => 'une citation dans le corps a ete comptee comme une decision'];
    }
    $passes[] = 'EPARGNE les citations du corps';

    // Le doublon, vu.
    $avecDoublon = $echantillon . "\n### 2026-08-30 · D1 — Un autre titre sous le meme numero";
    $lues2 = decisions($avecDoublon);
    if (count($lues2['D1'] ?? []) !== 2) {
        return ['ok' => false, 'quoi' => 'un doublon pose expres n a pas ete vu'];
    }
    $passes[] = 'VOIT un numero en double';

    // L'empreinte ignore les citations, pas le sens.
    if (empreinte('Un titre qui remplace D91') !== empreinte('Un titre qui remplace D92')) {
        return ['ok' => false, 'quoi' => 'l empreinte ne neutralise pas les citations'];
    }
    if (empreinte('Un titre') === empreinte('Un autre titre')) {
        return ['ok' => false, 'quoi' => 'l empreinte confond deux titres differents'];
    }
    $passes[] = 'empreinte : ignore les citations, distingue les sens';

    return ['ok' => true, 'passes' => $passes];
}

$t = temoins();
if ($t['ok'] !== true) {
    fwrite(STDERR, "\n=== INSTRUMENT MORT — " . $t['quoi'] . " ===\n\n");
    fwrite(STDERR, "  Aucun chiffre de ce controle ne vaut : il ne mesure pas ce qu'il annonce.\n\n");
    exit(1);
}

// ── LA MESURE RÉELLE ────────────────────────────────────────────────────────────────────────────

if (!is_file(FICHIER)) {
    fwrite(STDERR, "\n=== INSTRUMENT MORT — " . FICHIER . " est introuvable ===\n\n");
    exit(1);
}

$lues = decisions((string) file_get_contents(FICHIER));

if (count($lues) < PLANCHER) {
    fwrite(STDERR, "\n=== INSTRUMENT MORT — perimetre effondre ===\n\n");
    fwrite(STDERR, sprintf("  decisions lues : %d (moins de %d = on n'a pas mesure)\n\n", count($lues), PLANCHER));
    fwrite(STDERR, "  Ce controle rend le meme vert quand il ne trouve aucun doublon et quand il ne\n");
    fwrite(STDERR, "  trouve AUCUNE decision. Un titre qui change de forme suffit.\n\n");
    exit(1);
}

$actuelle = [];
foreach ($lues as $cle => $occurrences) {
    $actuelle[$cle] = empreinte($occurrences[0]['titre']);
}
ksort($actuelle);

// ── `--figer` ───────────────────────────────────────────────────────────────────────────────────

if (in_array('--figer', $argv, true)) {
    $doublons = array_filter($lues, static fn (array $o): bool => count($o) > 1);
    if ($doublons !== []) {
        fwrite(STDERR, "✗ On ne fige pas une ligne de base qui contient des doublons.\n");
        foreach ($doublons as $cle => $occurrences) {
            fwrite(STDERR, sprintf("    %s — %d fois\n", $cle, count($occurrences)));
        }
        exit(1);
    }

    file_put_contents(LIGNE_DE_BASE, json_encode([
        '_lisez_moi' => [
            'Ligne de base GELEE du garde-fou n46 — numeros de decision.',
            '',
            'Chaque entree associe un numero a une empreinte de son titre. Un numero est une POIGNEE :',
            '887 commentaires de app/src citent des D<n>. Reattribuer un numero ou reecrire un titre',
            'pour dire autre chose modifie silencieusement le sens de tous ces commentaires.',
            '',
            "L'empreinte ignore les citations D<n> presentes dans le titre : ce qui est gele est ce que",
            'la decision DECIDE, pas ce vers quoi elle pointe.',
            '',
            'Regenerer : php bin/garde-fou-numeros-de-decision.php --figer',
        ],
        'empreintes' => $actuelle,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

    echo sprintf("Ligne de base figee : %d decision(s). Commite %s.\n", count($actuelle), LIGNE_DE_BASE);
    exit(0);
}

// ── LE VERDICT ──────────────────────────────────────────────────────────────────────────────────

$echecs = [];

foreach ($lues as $cle => $occurrences) {
    if (count($occurrences) > 1) {
        $lignes = implode(', ', array_map(static fn (array $o): string => 'ligne ' . $o['ligne'], $occurrences));
        $echecs[] = sprintf('%s porte %d decisions differentes (%s).', $cle, count($occurrences), $lignes);
    }
}

$gelees = [];
if (is_file(LIGNE_DE_BASE)) {
    /** @var array{empreintes?: array<string, string>} $json */
    $json = json_decode((string) file_get_contents(LIGNE_DE_BASE), true) ?: [];
    $gelees = $json['empreintes'] ?? [];
}

if ($gelees === []) {
    fwrite(STDERR, "\n=== Aucune ligne de base ===\n\n");
    fwrite(STDERR, "  php bin/garde-fou-numeros-de-decision.php --figer\n\n");
    exit(1);
}

foreach ($gelees as $cle => $empreinte) {
    if (!isset($actuelle[$cle])) {
        $echecs[] = sprintf(
            '%s a DISPARU. Une decision ne se supprime pas : elle se rectifie, sans quoi les commentaires qui la citent pointent vers rien.',
            $cle,
        );
        continue;
    }

    if ($actuelle[$cle] !== $empreinte) {
        $echecs[] = sprintf(
            '%s a change de titre — donc de sens. Les commentaires qui la citent disent maintenant autre chose. Si le sens a vraiment change, ouvre un %s-bis.',
            $cle,
            $cle,
        );
    }
}

$neuves = array_diff_key($actuelle, $gelees);

if ($echecs !== []) {
    fwrite(STDERR, "\n=== ECHEC — numeros de decision ===\n\n");
    foreach ($echecs as $e) {
        fwrite(STDERR, '  - ' . $e . "\n");
    }
    fwrite(STDERR, sprintf("\n  %d decision(s) lues dans %s.\n\n", count($lues), FICHIER));
    exit(1);
}

if ($neuves !== []) {
    fwrite(STDERR, "\n=== ECHEC — decision(s) neuve(s) non figee(s) ===\n\n");
    foreach (array_keys($neuves) as $cle) {
        fwrite(STDERR, '  - ' . $cle . "\n");
    }
    fwrite(STDERR, "\n  Figer une decision neuve est un GESTE, pas une derive : c'est ce qui rend\n");
    fwrite(STDERR, "  son numero definitif a partir de maintenant.\n\n");
    fwrite(STDERR, "      php bin/garde-fou-numeros-de-decision.php --figer\n\n");
    exit(1);
}

echo sprintf(
    "Numéros de décision : OK — %d décision(s) lues, aucun doublon, aucun titre modifié.\n",
    count($actuelle),
);
echo sprintf("  (%d témoins passés, dont 1 qui prouve ce que le détecteur épargne.)\n", count($t['passes']));
exit(0);
