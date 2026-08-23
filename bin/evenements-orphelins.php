#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Quels événements du contrat sont réellement émis, et lesquels n'existent que sur le papier.
 *
 * **Ce n'est pas un garde-fou, et c'est délibéré.** D2 veut que le contrat précède le code : un
 * événement déclaré au catalogue et pas encore émis n'est pas une faute, c'est une intention. En
 * faire échouer un contrôle punirait exactement la méthode qu'on a choisie.
 *
 * Mais l'écart mérite d'être **mesurable à tout moment**, parce qu'il coûte cher quand on l'ignore :
 * D22 note que Revenue Recovery et Smart Flow attendent quatorze événements dont deux seulement
 * existent, et qu'écrire les modules avant leurs déclencheurs produirait deux coquilles inertes. Le
 * projet a déjà ce précédent avec `ProjectionAccesReservation`, écrite et testée sans rien pour
 * l'alimenter.
 *
 * `ManifestCatalogueTest` vérifie qu'un événement **déclaré par un module** figure au catalogue.
 * Ceci regarde dans l'autre sens : un événement **du catalogue** a-t-il un émetteur.
 *
 * Usage :
 *   php bin/evenements-orphelins.php
 *   php bin/evenements-orphelins.php --emis        # seulement ceux qui existent
 *   php bin/evenements-orphelins.php --orphelins   # seulement ceux qui n'existent pas
 */

const CATALOGUE = 'COORDINATION/CONTRACT/catalogue-evenements.md';
const RACINE_SRC = 'app/src';

/** Un nom d'événement dans un tableau du catalogue : `| \`domain.fait\` | … |`. */
const MOTIF_CATALOGUE = '/^\|\s*`([a-z][a-z0-9_]*\.[a-z][a-z0-9_]*)`\s*\|/m';

/** @return list<string> */
function evenementsDuCatalogue(string $chemin): array
{
    if (!is_file($chemin)) {
        fwrite(STDERR, sprintf("Catalogue introuvable : %s — lance depuis la racine du dépôt.\n", $chemin));
        exit(2);
    }

    preg_match_all(MOTIF_CATALOGUE, (string) file_get_contents($chemin), $noms);

    return array_values(array_unique($noms[1]));
}

/**
 * Où chaque nom d'événement apparaît dans le code, et à quel titre.
 *
 * Trois rôles se distinguent au contexte, parce qu'un même littéral sert aux trois :
 *   - **déclaré** : dans un manifeste de module (`eventsEmitted` / `eventsConsumed`) ;
 *   - **consommé** : dans un abonnement (`#[AsEventListener]`, `getSubscribedEvents`) ;
 *   - **émis** : partout ailleurs, c'est-à-dire un appel qui publie.
 *
 * @return array<string, array{emis: list<string>, consomme: list<string>, declare: list<string>}>
 */
function usages(string $racine, array $evenements): array
{
    $usages = [];
    foreach ($evenements as $nom) {
        $usages[$nom] = ['emis' => [], 'consomme' => [], 'declare' => []];
    }

    $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));

    /** @var SplFileInfo $fichier */
    foreach ($iterateur as $fichier) {
        if (!$fichier->isFile() || $fichier->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($fichier->getPathname());
        $relatif = str_replace('\\', '/', substr($fichier->getPathname(), strlen($racine) + 1));
        $estManifeste = str_ends_with($fichier->getFilename(), 'Module.php');

        foreach ($evenements as $nom) {
            if (!str_contains($source, "'" . $nom . "'") && !str_contains($source, '"' . $nom . '"')) {
                continue;
            }

            if ($estManifeste) {
                $usages[$nom]['declare'][] = $relatif;
                continue;
            }

            $abonnement = preg_match('/AsEventListener|getSubscribedEvents|addListener/', $source) === 1;
            $publication = preg_match('/publier\s*\(|publish\s*\(|DomainEvent/', $source) === 1;

            if ($publication) {
                $usages[$nom]['emis'][] = $relatif;
            } elseif ($abonnement) {
                $usages[$nom]['consomme'][] = $relatif;
            } else {
                // Mention sans contexte reconnaissable : on la range en consommation plutôt qu'en
                // émission — se tromper dans ce sens fait dire « manquant » à tort, ce qui se vérifie
                // en dix secondes ; l'inverse ferait croire qu'un déclencheur existe.
                $usages[$nom]['consomme'][] = $relatif;
            }
        }
    }

    return $usages;
}

$options = array_slice($argv, 1);
$evenements = evenementsDuCatalogue(CATALOGUE);
$usages = usages(RACINE_SRC, $evenements);

$emis = array_filter($usages, static fn (array $u): bool => $u['emis'] !== []);
$orphelins = array_filter($usages, static fn (array $u): bool => $u['emis'] === []);

$seulementEmis = in_array('--emis', $options, true);
$seulementOrphelins = in_array('--orphelins', $options, true);

echo sprintf(
    "\nCatalogue d'événements : %d déclarés · %d émis · %d sans émetteur\n",
    count($evenements),
    count($emis),
    count($orphelins)
);
echo str_repeat('─', 72) . "\n";

if (!$seulementOrphelins) {
    echo "\nÉMIS — un déclencheur existe\n";
    foreach ($emis as $nom => $u) {
        echo sprintf("  %-32s %s\n", $nom, implode(', ', array_unique($u['emis'])));
    }
}

if (!$seulementEmis) {
    echo "\nSANS ÉMETTEUR — déclarés au contrat, rien ne les publie\n";
    foreach ($orphelins as $nom => $u) {
        $note = $u['consomme'] !== [] ? '  ⚠ déjà consommé par ' . implode(', ', array_unique($u['consomme'])) : '';
        echo sprintf("  %-32s%s\n", $nom, $note);
    }
}

echo "\n";
echo "Un événement sans émetteur n'est pas une faute : le contrat précède le code (D2).\n";
echo "Un événement sans émetteur **et déjà consommé** en est une : l'abonné ne se déclenchera jamais.\n\n";
