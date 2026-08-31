<?php

declare(strict_types=1);

/**
 * Garde-fou n°29 — une création qu'on ne peut pas défaire doit dire pourquoi.
 *
 * ── LE DÉFAUT, PAYÉ POUR DE VRAI ────────────────────────────────────────────────────────────────
 *
 * `allaccess-34` a sondé `POST /api/patinoire_parc_patins` avec un corps vide, pour vérifier ce que
 * la route acceptait. Réponse : **201**, une ligne créée sur Patinoire B, et **aucun moyen de la
 * retirer** — la ressource n'expose pas de `Delete`. Le prix d'une vérification ordinaire, et
 * n'importe lequel d'entre nous l'aurait payé.
 *
 * ── ⚠ CE QUI EST REFUSÉ N'EST PAS L'ABSENCE DE `Delete`, C'EST L'ABSENCE DE DÉCISION ──────────
 *
 * `allaccess-c2` avait d'abord proposé « un `Delete` sur toute ressource qui expose un `Post` ».
 * `allaccess-34` l'a refusée, et elle avait raison : l'inaltérabilité est un **choix assumé** ici.
 * Une facture, une vente scellée NF525, une demande RGPD traitée ne doivent pas être supprimables.
 * Ce garde-fou aurait été rouge sur des entités correctes — et désactivé dans la semaine.
 *
 * La forme qui tient est celle du `@sans-ecran:` : on exige une **raison écrite**. Une facture écrit
 * « inaltérabilité NF525 » ; `ParcPatins` n'a rien à écrire, et c'est là que ça mord.
 *
 *     @sans-suppression: <pourquoi cette création ne peut pas être défaite>
 *
 * ── ⚠ ET UN CLIQUET, PARCE QUE 188 JUSTIFICATIONS D'UN COUP NE VAUDRAIENT RIEN ────────────────
 *
 * Exiger la dette entière ferait écrire les raisons par des gens qui ne les connaissent pas, pour
 * faire taire un rouge. **Un motif écrit sous la contrainte a l'autorité de l'écrit sans en avoir la
 * valeur** — c'est pire qu'une absence, parce qu'il arrête la question. On gèle donc l'existant, on
 * refuse le suivant, et la dette se résorbe quand quelqu'un touche l'une d'elles en sachant pourquoi.
 *
 * ── ⚠ LA LIGNE DE BASE EST UNE LISTE, PAS UN NOMBRE, ET C'EST DÉLIBÉRÉ ────────────────────────
 *
 * Deux mesures indépendantes du même défaut ont rendu 188 et 189 ressources sans `Delete` — puis
 * **94 et 126** sur le sous-critère « crée depuis le corps ». Les critères ne coïncidaient pas, et
 * aucun de nous deux ne pouvait reproduire le chiffre de l'autre.
 *
 * Un cliquet sur un compteur aurait figé un désaccord. Une liste nomme le nouveau venu, rend le
 * désaccord sans effet, et dit **quoi corriger** au lieu de dire qu'il y a du travail.
 *
 * ── CE QU'IL NE VOIT PAS ────────────────────────────────────────────────────────────────────────
 *
 * Il lit des déclarations d'API. Un `Post` porté par un contrôleur écrit à la main, ou une écriture
 * passée par un `Patch` sur une collection, lui échappent. Il ferme une porte, pas toutes.
 */

const RACINE = __DIR__ . '/../app/src';
const LIGNE_DE_BASE = __DIR__ . '/post-sans-suppression.ligne-de-base.json';
const MARQUEUR = '@sans-suppression:';

/** @return list<string> */
function fichiersPhp(string $racine): array
{
    $trouves = [];
    $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));
    /** @var SplFileInfo $entree */
    foreach ($iterateur as $entree) {
        if ($entree->isFile() && $entree->getExtension() === 'php') {
            $trouves[] = $entree->getPathname();
        }
    }
    sort($trouves);

    return $trouves;
}

if (!is_dir(RACINE)) {
    fwrite(STDERR, "Créations irréversibles : IGNORÉ — app/src absent. Ce contrôle ne dit RIEN ici.\n");
    exit(0);
}

$sansRaison = [];
$declarees = 0;
$avecSuppression = 0;
$avecPost = 0;
$marqueursNus = [];

foreach (fichiersPhp(RACINE) as $chemin) {
    $contenu = (string) file_get_contents($chemin);

    if (!str_contains($contenu, 'ApiResource') || !preg_match('/new Post\(/', $contenu)) {
        continue;
    }
    ++$avecPost;

    if (preg_match('/new Delete\(/', $contenu)) {
        ++$avecSuppression;
        continue;
    }

    $relatif = str_replace('\\', '/', substr($chemin, strlen(RACINE) + 1));

    $i = strpos($contenu, MARQUEUR);
    if ($i === false) {
        $sansRaison[] = $relatif;
        continue;
    }

    // Un marqueur nu ne déclare rien : il tait. On le refuse plutôt que de le compter — c'est la
    // règle déjà posée sur `@sans-ecran:`, et pour la même raison.
    $finLigne = strpos($contenu, "\n", $i);
    $raison = trim(substr($contenu, $i + strlen(MARQUEUR), ($finLigne === false ? strlen($contenu) : $finLigne) - $i - strlen(MARQUEUR)));
    if ($raison === '') {
        $marqueursNus[] = $relatif;
        continue;
    }
    ++$declarees;
}

sort($sansRaison);

// ⚠ Un instrument qui ne lit rien rend zéro, ce qui ressemble à une victoire. Un dépôt qui expose
// des centaines d'opérations en porte forcément : refuser de conclure plutôt qu'annoncer un succès.
if ($avecPost === 0) {
    fwrite(STDERR, "Créations irréversibles : ÉCHEC — aucune ressource avec un Post trouvée. L'instrument est en cause.\n");
    exit(2);
}

$sceller = in_array('--sceller', $argv, true);

if (!file_exists(LIGNE_DE_BASE)) {
    if (!$sceller) {
        fwrite(STDERR, "Créations irréversibles : ligne de base absente. Pose-la avec --sceller.\n");
        exit(2);
    }
    $base = ['entrees' => []];
} else {
    $base = json_decode((string) file_get_contents(LIGNE_DE_BASE), true, 512, JSON_THROW_ON_ERROR);
}

$connues = (array) ($base['entrees'] ?? []);

if ($sceller) {
    $nouvelles = array_values(array_diff($sansRaison, $connues));
    if ($nouvelles !== [] && $connues !== []) {
        // ⚠ SCELLER NE MONTE JAMAIS. Régénérer la ligne de base au-dessus de l'existant gèlerait la
        // dérive au lieu de la mesurer, et rendrait un vert qui ne veut plus rien dire.
        fwrite(STDERR, sprintf("Refus de sceller : %d entrée(s) NOUVELLE(S) seraient gelées.\n\n", count($nouvelles)));
        foreach ($nouvelles as $n) {
            fwrite(STDERR, '    ' . $n . "\n");
        }
        fwrite(STDERR, "\nDéclare-les avec « " . MARQUEUR . " <raison> », ou expose un Delete.\n");
        exit(2);
    }

    file_put_contents(LIGNE_DE_BASE, json_encode(
        ['entrees' => $sansRaison],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . "\n");

    printf("Créations irréversibles : %d entrée(s) gelée(s).\n", count($sansRaison));
    exit(0);
}

if ($marqueursNus !== []) {
    fwrite(STDERR, sprintf("Créations irréversibles : %d marqueur(s) sans raison.\n\n", count($marqueursNus)));
    foreach ($marqueursNus as $m) {
        fwrite(STDERR, '    ' . $m . "\n");
    }
    fwrite(STDERR, "\nUn marqueur nu ne déclare rien : il tait. Écris la raison après « " . MARQUEUR . " ».\n");
    exit(1);
}

$nouvelles = array_values(array_diff($sansRaison, $connues));

if ($nouvelles !== []) {
    fwrite(STDERR, sprintf(
        "Créations irréversibles : %d ressource(s) NOUVELLE(S) créent sans pouvoir défaire.\n\n",
        count($nouvelles)
    ));
    foreach ($nouvelles as $n) {
        fwrite(STDERR, '    ' . $n . "\n");
    }
    fwrite(STDERR, "\n⚠ Un POST accepté qu'aucun DELETE ne rattrape laisse une ligne pour toujours.\n");
    fwrite(STDERR, "Mesuré le 30/08 : un corps vide sur `/api/patinoire_parc_patins` a rendu 201 et\n");
    fwrite(STDERR, "créé une ligne sur Patinoire B, définitivement.\n\n");
    fwrite(STDERR, "Deux issues, et l'absence de Delete n'est PAS refusée en soi :\n");
    fwrite(STDERR, "  · exposer un Delete ;\n");
    fwrite(STDERR, "  · ou déclarer pourquoi c'est impossible : « " . MARQUEUR . " inaltérabilité NF525 ».\n");
    exit(1);
}

printf(
    "Créations irréversibles : OK — %d ressource(s) avec Post, %d avec Delete, %d déclarée(s). Dette gelée : %d.\n",
    $avecPost,
    $avecSuppression,
    $declarees,
    count($connues)
);
exit(0);
