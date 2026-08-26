<?php

declare(strict_types=1);

/**
 * Garde-fou n°7 — la charge utile émise est-elle celle que le catalogue annonce ? (D2)
 *
 * **Pourquoi ce contrôle existe.** Le 24/08, `booking.cancelled` a été émis avec
 * `slotId, leadTimeMinutes, withinFreeWindow` alors que le catalogue annonçait `slot, lead_time`.
 * Personne ne l'a vu : `ManifestCatalogueTest` (RG-PLAT-06) ne lit que les **noms** d'événements,
 * jamais la troisième colonne. Le catalogue a été corrigé après coup, à la main. Vingt-six émissions
 * restent à écrire — c'est vingt-six occasions de recommencer.
 *
 * Un abonné se code contre le catalogue. Si l'émetteur envoie `slotId` là où le contrat promet
 * `slot`, l'abonné lit `null` et ne casse rien : il travaille juste sur du vide. C'est la même
 * famille de panne que la règle A du garde-fou n°6 — silencieuse, et invisible aux tests.
 *
 * **Ce que ce garde-fou NE sait PAS faire.** Il ne lit que les clés **littérales** d'un tableau passé
 * directement à `new DomainEvent(...)`. Une charge construite dans une variable, ou par un appel de
 * méthode, lui échappe : il la déclare « non analysable » et passe. Il ne prétend donc pas à
 * l'exhaustivité — il attrape la dérive ordinaire, celle qu'on écrit sans y penser.
 *
 * **Et la dette est réelle.** Les 49 entrées du catalogue décrivent leur charge en langage courant
 * (`amount, lines, customer?`), pas en noms de clés. Les émissions antérieures au 24/08 ne peuvent
 * donc pas correspondre. Elles sont gelées dans la ligne de base ; seules les nouvelles sont tenues.
 *
 * Usage :
 *   php bin/garde-fou-charges-utiles.php
 *   php bin/garde-fou-charges-utiles.php --nettoyer
 *   php bin/garde-fou-charges-utiles.php --contre=origin/main
 */

const CATALOGUE = 'COORDINATION/CONTRACT/catalogue-evenements.md';
const RACINE_SRC = 'app/src';
const LIGNE_DE_BASE = 'bin/charges-utiles.ligne-de-base.json';

/** Nom en 1re colonne, charge utile annoncée en 3e. */
const MOTIF_LIGNE = '/^\|\s*`([a-z][a-z0-9_]*\.[a-z][a-z0-9_]*)`\s*\|[^|]*\|([^|]*)\|/m';

/**
 * Charges annoncées, par événement.
 *
 * @return array<string, list<string>>
 */
function chargesAnnoncees(string $chemin): array
{
    if (!is_file($chemin)) {
        fwrite(STDERR, sprintf("Catalogue introuvable : %s\n", $chemin));
        exit(2);
    }

    preg_match_all(MOTIF_LIGNE, (string) file_get_contents($chemin), $lignes, PREG_SET_ORDER);

    $annoncees = [];
    foreach ($lignes as $ligne) {
        $cles = [];
        foreach (explode(',', $ligne[2]) as $brut) {
            // Le catalogue glisse de la prose dans la cellule : « expires_at (never the token) ».
            // La retirer d'abord, sinon l'annotation est lue comme faisant partie du nom de la clé
            // et le garde-fou signale un écart qui n'existe que dans son propre analyseur.
            $brut = preg_replace('/\([^)]*\)/', '', $brut) ?? $brut;
            // « customer? » = facultatif : le point d'interrogation n'appartient pas au nom.
            $cle = trim(str_replace(['`', '?', '*'], '', $brut));
            if ($cle !== '') {
                $cles[] = $cle;
            }
        }
        $annoncees[$ligne[1]] = $cles;
    }

    return $annoncees;
}

/**
 * Forme canonique d'un nom de clé.
 *
 * Le catalogue est rédigé en `snake_case`, le code émet en `camelCase` — sur **tout** le projet, pas
 * par inadvertance. Refuser cet écart reviendrait à refuser chaque émission du dépôt : le garde-fou
 * serait désactivé dans la semaine, et à raison. On compare donc sur la forme canonique, et on ne
 * signale que les différences de **fond** : une clé promise et jamais envoyée, une clé envoyée que
 * le contrat n'annonce pas.
 *
 * Conséquence assumée : `mime` et `mimeType` restent deux clés distinctes, et c'est voulu — la
 * seconde n'est pas une variante d'écriture de la première. De même `size` et `sizeBytes`, où
 * l'unité fait partie du contrat.
 */
function canonique(string $cle): string
{
    return strtolower(str_replace('_', '', $cle));
}

/**
 * Extrait le texte de chaque appel `new DomainEvent(...)`, parenthèses équilibrées.
 *
 * @return list<string>
 */
function appelsDomainEvent(string $source): array
{
    $appels = [];
    $decalage = 0;

    while (($debut = strpos($source, 'new DomainEvent(', $decalage)) !== false) {
        $i = $debut + strlen('new DomainEvent(');
        $profondeur = 1;
        $longueur = strlen($source);

        while ($i < $longueur && $profondeur > 0) {
            $c = $source[$i];
            if ($c === '(') {
                ++$profondeur;
            } elseif ($c === ')') {
                --$profondeur;
            }
            ++$i;
        }

        $appels[] = substr($source, $debut, $i - $debut);
        $decalage = $i;
    }

    return $appels;
}

$options = array_slice($argv, 1);
$annoncees = chargesAnnoncees(CATALOGUE);

/** @var array<string, array{fichier: string, emises: list<string>, manquantes: list<string>, en_trop: list<string>}> $ecarts */
$ecarts = [];

$iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(RACINE_SRC, FilesystemIterator::SKIP_DOTS));

/** @var SplFileInfo $fichier */
foreach ($iterateur as $fichier) {
    if (!$fichier->isFile() || $fichier->getExtension() !== 'php') {
        continue;
    }

    $source = (string) file_get_contents($fichier->getPathname());
    if (!str_contains($source, 'new DomainEvent(')) {
        continue;
    }

    $relatif = str_replace('\\', '/', substr($fichier->getPathname(), strlen(RACINE_SRC) + 1));

    foreach (appelsDomainEvent($source) as $appel) {
        if (preg_match('/[\'"]([a-z][a-z0-9_]*\.[a-z][a-z0-9_]*)[\'"]/', $appel, $nom) !== 1) {
            continue;
        }

        $evenement = $nom[1];
        if (!isset($annoncees[$evenement])) {
            continue; // nom hors catalogue : c'est l'affaire de RG-PLAT-06, pas la mienne.
        }

        // Clés littérales de la charge. Faute d'en trouver, on ne conclut pas.
        preg_match_all('/[\'"](\w+)[\'"]\s*=>/', $appel, $cles);
        if ($cles[1] === []) {
            continue;
        }

        $emises = array_values(array_unique($cles[1]));

        $canonEmises = array_map('canonique', $emises);
        $canonAnnoncees = array_map('canonique', $annoncees[$evenement]);

        $manquantes = array_values(array_filter(
            $annoncees[$evenement],
            static fn (string $cle): bool => !in_array(canonique($cle), $canonEmises, true)
        ));
        $enTrop = array_values(array_filter(
            $emises,
            static fn (string $cle): bool => !in_array(canonique($cle), $canonAnnoncees, true)
        ));

        if ($manquantes === [] && $enTrop === []) {
            continue;
        }

        $ecarts[$evenement] = [
            'fichier' => $relatif,
            'emises' => $emises,
            'manquantes' => $manquantes,
            'en_trop' => $enTrop,
        ];
    }
}

ksort($ecarts);

if (!is_file(LIGNE_DE_BASE)) {
    fwrite(STDERR, sprintf("Ligne de base absente : %s\nCrée-la : php %s --nettoyer\n", LIGNE_DE_BASE, $argv[0]));
    exit(2);
}

$base = json_decode((string) file_get_contents(LIGNE_DE_BASE), true, 512, JSON_THROW_ON_ERROR);

if (!is_array($base) || !isset($base['scelle']['plafond'], $base['entrees'])) {
    fwrite(STDERR, "Ligne de base illisible : clés « scelle.plafond » et « entrees » attendues.\n");
    exit(2);
}

$plafond = (int) $base['scelle']['plafond'];

if (in_array('--nettoyer', $options, true)) {
    $base['entrees'] = $ecarts;
    $base['scelle']['plafond'] = count($ecarts);
    file_put_contents(
        LIGNE_DE_BASE,
        json_encode($base, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );
    echo sprintf("Ligne de base réécrite : %d écart(s), plafond %d.\n", count($ecarts), count($ecarts));
    exit(0);
}

$plafondReference = null;
foreach ($options as $option) {
    if (!str_starts_with($option, '--contre=')) {
        continue;
    }

    $sortie = [];
    $code = 0;
    exec(sprintf(
        'git show %s:%s 2>/dev/null',
        escapeshellarg(substr($option, strlen('--contre='))),
        escapeshellarg(LIGNE_DE_BASE)
    ), $sortie, $code);

    if ($code === 0 && $sortie !== []) {
        $donneesRef = json_decode(implode("\n", $sortie), true);
        if (is_array($donneesRef) && isset($donneesRef['scelle']['plafond'])) {
            $plafondReference = (int) $donneesRef['scelle']['plafond'];
        }
    }
}

$echec = false;
$nouveaux = array_values(array_diff(array_keys($ecarts), array_keys($base['entrees'])));

if ($nouveaux !== []) {
    $echec = true;
    echo "\n=== ÉCHEC — charge utile émise hors contrat ===\n\n";
    foreach ($nouveaux as $evenement) {
        $e = $ecarts[$evenement];
        echo sprintf("  %s  (%s)\n", $evenement, $e['fichier']);
        if ($e['manquantes'] !== []) {
            echo sprintf("      annoncé au catalogue, jamais émis : %s\n", implode(', ', $e['manquantes']));
        }
        if ($e['en_trop'] !== []) {
            echo sprintf("      émis, absent du catalogue         : %s\n", implode(', ', $e['en_trop']));
        }
    }
    echo "\n  Un abonné se code contre le catalogue. Une clé qui ne porte pas le nom promis se lit\n";
    echo "  `null` chez lui, sans erreur et sans test rouge — il travaille sur du vide.\n";
    echo "  Corrige l'émission, ou mets le catalogue à jour si c'est lui qui a vieilli (D2).\n";
}

if (count($ecarts) > $plafond) {
    $echec = true;
    echo sprintf("\n=== ÉCHEC — la ligne de base a grossi (%d pour un plafond de %d) ===\n", count($ecarts), $plafond);
    echo "\n"
        . "  Un cliquet ne monte pas — c'est exactement ce qui lui donne sa valeur.\n"
        . "\n"
        . "  « --nettoyer » n'est PAS l'issue : il recalcule le plafond sur l'état courant, donc\n"
        . "  il le ferait monter, et le contrôle contre la référence le refuserait aussitôt. Il ne\n"
        . "  sert qu'à RÉSORBER un stock qui a déjà baissé.\n"
        . "\n"
        . "  Les deux seules issues :\n"
        . "    · corriger ce qui a fait monter le compte — l'endroit exact est listé ci-dessus ;\n"
        . "    · si la hausse est délibérée, elle demande l'accord de l'intégrateur : le plafond\n"
        . "      de référence se change sur « main », pas ici.\n"
        . "\n"
        . "  ⚠ La cause la plus fréquente n'est pas une faute : ta branche est simplement EN\n"
        . "  RETARD sur la référence, et un plafond a baissé entre-temps. Commence par ça :\n"
        . "\n"
        . "      git fetch origin && git merge --no-edit origin/main\n";
}

if ($plafondReference !== null && $plafond > $plafondReference) {
    $echec = true;
    echo sprintf("\n=== ÉCHEC — plafond relevé : %d sur la référence, %d proposé ===\n", $plafondReference, $plafond);
    echo "\n"
        . "  Un cliquet ne monte pas — c'est exactement ce qui lui donne sa valeur.\n"
        . "\n"
        . "  « --nettoyer » n'est PAS l'issue : il recalcule le plafond sur l'état courant, donc\n"
        . "  il le ferait monter, et le contrôle contre la référence le refuserait aussitôt. Il ne\n"
        . "  sert qu'à RÉSORBER un stock qui a déjà baissé.\n"
        . "\n"
        . "  Les deux seules issues :\n"
        . "    · corriger ce qui a fait monter le compte — l'endroit exact est listé ci-dessus ;\n"
        . "    · si la hausse est délibérée, elle demande l'accord de l'intégrateur : le plafond\n"
        . "      de référence se change sur « main », pas ici.\n"
        . "\n"
        . "  ⚠ La cause la plus fréquente n'est pas une faute : ta branche est simplement EN\n"
        . "  RETARD sur la référence, et un plafond a baissé entre-temps. Commence par ça :\n"
        . "\n"
        . "      git fetch origin && git merge --no-edit origin/main\n";
}

if ($echec) {
    exit(1);
}

$resorbes = count($base['entrees']) - count($ecarts);
echo sprintf(
    "Charges utiles : OK — aucune émission hors contrat. Écarts gelés : %d, plafond %d.%s\n",
    count($ecarts),
    $plafond,
    $resorbes > 0 ? sprintf(' %d résorbé(s) — pense à --nettoyer.', $resorbes) : ''
);

exit(0);
