<?php

declare(strict_types=1);

/**
 * Garde-fou n°6 — événements du catalogue sans émetteur (D2, D22).
 *
 * Deux règles, et la distinction entre elles est tout l'intérêt du contrôle.
 *
 * RÈGLE A — abonné orphelin. Un événement **consommé** quelque part mais qu'**aucun code n'émet**
 * est un défaut immédiat, sans ligne de base et sans négociation : l'abonné ne se déclenchera
 * jamais. Le module qui en dépend est silencieusement mort, et rien dans la suite de tests ne le
 * dira — un abonné qui ne tourne pas ne casse aucun test, il ne fait rien.
 *
 * RÈGLE C — émission hors contrat. Un événement **publié par le code** dont le nom n'est **pas au
 * catalogue** : échec dur, sans ligne de base. Le sens est l'inverse de la règle B — là c'est le
 * contrat qui attend le code, ici c'est le code qui a doublé le contrat.
 *
 * Ce contrôle comble un trou réel : `ManifestCatalogueTest` (RG-PLAT-06) vérifie que tout événement
 * déclaré par un **manifeste** figure au catalogue, mais rien ne vérifie ceux que le **code** publie.
 * Un module peut donc émettre un fait que personne n'a versé au contrat, et aucun test ne le dira.
 * Au 24/08 la discipline avait tenu — 19 émissions, 0 hors catalogue — d'où une règle sans dette :
 * on ferme la porte pendant qu'elle est encore fermée.
 *
 * RÈGLE B — événement déclaré sans émetteur, avec cliquet. Le contrat précède le code (D2) : un nom
 * inscrit au catalogue que personne n'émet encore est **normal**, c'est même la méthode. Mais le
 * stock ne doit pas grossir. On le gèle et on le fait décroître ; RR-1 et SF-1 sont exactement ce
 * décompte-là.
 *
 * Ce que ce garde-fou NE sait PAS faire, et il faut le savoir avant de s'y fier : il reconnaît un
 * émetteur à la présence du nom littéral dans un fichier qui publie. Un nom construit
 * dynamiquement ('booking.' . $suffixe) lui échappe, et il comptera l'événement comme orphelin. Le
 * faux positif va donc dans le sens prudent — il réclame une émission qui existe peut-être — jamais
 * dans le sens qui rassure à tort.
 *
 * Usage :
 *   php bin/garde-fou-evenements-orphelins.php
 *   php bin/garde-fou-evenements-orphelins.php --nettoyer
 *   php bin/garde-fou-evenements-orphelins.php --contre=origin/main
 */

const CATALOGUE = 'COORDINATION/CONTRACT/catalogue-evenements.md';
const RACINE_SRC = 'app/src';
const LIGNE_DE_BASE = 'bin/evenements-orphelins.ligne-de-base.json';

const MOTIF_CATALOGUE = '/^\|\s*`([a-z][a-z0-9_]*\.[a-z][a-z0-9_]*)`\s*\|/m';
const MOTIF_ABONNEMENT = '/AsEventListener|getSubscribedEvents|addListener/';
const MOTIF_PUBLICATION = '/publier\s*\(|publish\s*\(|DomainEvent/';
/** Nom passé en 1er argument de `new DomainEvent(...)` — ce que le code publie réellement. */
const MOTIF_EMISSION = '/new DomainEvent\(\s*[\'"]([a-z][a-z0-9_]*\.[a-z][a-z0-9_]*)[\'"]/';

/**
 * @return list<string>
 */
function evenementsDuCatalogue(string $chemin): array
{
    if (!is_file($chemin)) {
        fwrite(STDERR, sprintf("Catalogue introuvable : %s\n", $chemin));
        exit(2);
    }

    preg_match_all(MOTIF_CATALOGUE, (string) file_get_contents($chemin), $noms);

    return array_values(array_unique($noms[1]));
}

/**
 * @param  list<string> $evenements
 * @return array<string, array{emis: list<string>, consomme: list<string>}>
 */
function usages(string $racine, array $evenements, array &$horsCatalogue = []): array
{
    $usages = [];
    foreach ($evenements as $nom) {
        $usages[$nom] = ['emis' => [], 'consomme' => []];
    }

    $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));

    /** @var SplFileInfo $fichier */
    foreach ($iterateur as $fichier) {
        if (!$fichier->isFile() || $fichier->getExtension() !== 'php') {
            continue;
        }

        // Le manifeste d'un module *déclare* ses événements sans les émettre ni les consommer.
        // Le compter dans un sens ou dans l'autre fausserait les deux règles.
        if (str_ends_with($fichier->getFilename(), 'Module.php')) {
            continue;
        }

        $source = (string) file_get_contents($fichier->getPathname());
        $relatif = str_replace('\\', '/', substr($fichier->getPathname(), strlen($racine) + 1));

        $publie = preg_match(MOTIF_PUBLICATION, $source) === 1;
        $ecoute = preg_match(MOTIF_ABONNEMENT, $source) === 1;

        // Règle C : ce que le code publie, confronté au catalogue. On lit le nom là où il est
        // certain — 1er argument de `new DomainEvent(` — et non n'importe quelle chaîne du fichier.
        if (preg_match_all(MOTIF_EMISSION, $source, $emissions) > 0) {
            foreach ($emissions[1] as $nomEmis) {
                if (!in_array($nomEmis, $evenements, true)) {
                    $horsCatalogue[$nomEmis][] = $relatif;
                }
            }
        }

        foreach ($evenements as $nom) {
            if (!str_contains($source, "'" . $nom . "'") && !str_contains($source, '"' . $nom . '"')) {
                continue;
            }

            if ($publie) {
                $usages[$nom]['emis'][] = $relatif;
            } elseif ($ecoute) {
                $usages[$nom]['consomme'][] = $relatif;
            }
            // Mention sans contexte reconnaissable : ignorée. Ni émission ni abonnement — la ranger
            // en abonnement déclencherait la règle A, qui est un échec dur, sur une simple chaîne
            // de test ou de documentation.
        }
    }

    return $usages;
}

$options = array_slice($argv, 1);

$evenements = evenementsDuCatalogue(CATALOGUE);
$horsCatalogue = [];
$usages = usages(RACINE_SRC, $evenements, $horsCatalogue);
ksort($horsCatalogue);

$abonnesOrphelins = [];
$sansEmetteur = [];

foreach ($evenements as $nom) {
    if ($usages[$nom]['emis'] !== []) {
        continue;
    }

    if ($usages[$nom]['consomme'] !== []) {
        $abonnesOrphelins[$nom] = $usages[$nom]['consomme'];
    }

    $sansEmetteur[] = $nom;
}

sort($sansEmetteur);

// --- Ligne de base (règle B uniquement) -------------------------------------------------------
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
    $base['entrees'] = $sansEmetteur;
    $base['scelle']['plafond'] = count($sansEmetteur);
    file_put_contents(
        LIGNE_DE_BASE,
        json_encode($base, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );
    echo sprintf("Ligne de base réécrite : %d événement(s) sans émetteur, plafond %d.\n", count($sansEmetteur), count($sansEmetteur));
    exit(0);
}

// Cliquet opposable : le plafond ne remonte pas par rapport à la branche cible.
$plafondReference = null;
foreach ($options as $option) {
    if (!str_starts_with($option, '--contre=')) {
        continue;
    }

    $ref = substr($option, strlen('--contre='));
    $sortie = [];
    $code = 0;
    exec(sprintf('git show %s:%s 2>/dev/null', escapeshellarg($ref), escapeshellarg(LIGNE_DE_BASE)), $sortie, $code);

    if ($code === 0 && $sortie !== []) {
        $donneesRef = json_decode(implode("\n", $sortie), true);
        if (is_array($donneesRef) && isset($donneesRef['scelle']['plafond'])) {
            $plafondReference = (int) $donneesRef['scelle']['plafond'];
        }
    }
}

$echec = false;

// --- RÈGLE A : abonné orphelin — échec dur, hors ligne de base ---------------------------------
if ($abonnesOrphelins !== []) {
    $echec = true;
    echo "\n=== ÉCHEC — abonné qui ne se déclenchera jamais ===\n\n";
    foreach ($abonnesOrphelins as $nom => $fichiers) {
        echo sprintf("  %-32s consommé par %s\n", $nom, implode(', ', $fichiers));
    }
    echo "\n  Ces fichiers écoutent un événement que RIEN n'émet. Le code ne tournera pas, et aucun\n";
    echo "  test ne le signalera : un abonné inerte ne casse rien, il n'agit pas.\n";
    echo "  Corrige en émettant l'événement depuis l'endroit qui sait — pas depuis un endroit commode.\n";
}

// --- RÈGLE C : émission hors contrat — échec dur, hors ligne de base ----------------------------
if ($horsCatalogue !== []) {
    $echec = true;
    echo "\n=== ÉCHEC — événement publié hors du contrat ===\n\n";
    foreach ($horsCatalogue as $nom => $fichiers) {
        echo sprintf("  %-32s publié par %s\n", $nom, implode(', ', array_unique($fichiers)));
    }
    echo "\n  Ce nom n'est nulle part dans CONTRACT/catalogue-evenements.md. RG-PLAT-06 ne l'attrape\n";
    echo "  pas : il ne contrôle que les événements déclarés par un MANIFESTE, jamais ceux que le\n";
    echo "  code publie. Verse-le au catalogue avant de l'émettre — c'est l'ordre qu'impose D2, et\n";
    echo "  c'est ce qui rend l'ajout d'un fait visible de tous plutôt que décidé dans un module.\n";
}

// --- RÈGLE B : cliquet sur le stock -------------------------------------------------------------
$nouveaux = array_values(array_diff($sansEmetteur, $base['entrees']));

if ($nouveaux !== []) {
    $echec = true;
    echo "\n=== ÉCHEC — nouvel événement déclaré sans émetteur ===\n\n";
    foreach ($nouveaux as $nom) {
        echo sprintf("  %s\n", $nom);
    }
    echo "\n  Déclarer un nom au catalogue avant de l'émettre est la méthode (D2) — mais le stock\n";
    echo "  d'événements en attente ne doit pas grossir.\n";
    echo "\n";
    echo "  Un événement entre au catalogue DANS LE MÊME COMMIT que son émetteur. C'est la seule\n";
    echo "  issue qui passe ici.\n";
    echo "\n";
    echo "  « --nettoyer » n'en est pas une, contrairement à ce que ce message disait avant le\n";
    echo "  24/08 : il recalcule le plafond sur l'état courant, donc il le ferait MONTER, et le\n";
    echo "  contrôle contre la référence le refuserait aussitôt. Il ne sert qu'à résorber un\n";
    echo "  stock qui a déjà baissé.\n";
}

if (count($sansEmetteur) > $plafond) {
    $echec = true;
    echo sprintf("\n=== ÉCHEC — la ligne de base a grossi (%d pour un plafond de %d) ===\n", count($sansEmetteur), $plafond);
    // Les deux blocs se déclenchent presque toujours ensemble — un nom nouveau fait aussi monter
    // le compte. Répéter la même explication à trois lignes d'intervalle la fait lire comme du
    // remplissage, et on cesse alors de lire les deux.
    if ($nouveaux === []) {
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
            . "      de référence se change sur « main », pas ici.\n";
    }
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
        . "      de référence se change sur « main », pas ici.\n";
}

if ($echec) {
    exit(1);
}

$resorbes = count($base['entrees']) - count($sansEmetteur);
echo sprintf(
    "Événements orphelins : OK — aucun abonné inerte, aucune émission hors contrat, aucun nouveau nom sans émetteur. En attente : %d, plafond %d.%s\n",
    count($sansEmetteur),
    $plafond,
    $resorbes > 0 ? sprintf(' %d résorbé(s) — pense à --nettoyer.', $resorbes) : ''
);

exit(0);
