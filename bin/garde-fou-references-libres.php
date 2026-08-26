<?php

declare(strict_types=1);

/**
 * GARDE-FOU N°14 — une référence libre ne se compare ni en DQL ni par filtre.
 *
 * **Ce qu'est une référence libre.** Le dépôt franchit les frontières de module par un `?Uuid` nu —
 * `billetSupportRef`, `produitRef`, `reservationRef`, `creditDroitRef`, `alerteEcartRef`… — plutôt que
 * par une relation Doctrine. C'est délibéré : une relation créerait une dépendance de mapping entre deux
 * modules qui doivent pouvoir vivre séparément. **Vingt-sept propriétés suivent cette convention.**
 *
 * **Le piège, et il est structurel.** Doctrine sait convertir un type personnalisé quand il connaît la
 * relation. Sur une colonne `uuid` nue, il ne le fait pas — et il ne s'en plaint pas non plus :
 *
 * | Forme                          | Symptôme                    |
 * |--------------------------------|-----------------------------|
 * | `SearchFilter` sur `?Uuid`     | rend une liste **vide**     |
 * | paramètre d'entité dans `WHERE`| ne compte **rien**          |
 * | `IN (:liste)` en DQL           | ne trouve **rien**          |
 *
 * **Aucune ne lève.** Les trois se découvrent par un test qui devrait passer et ne passe pas — donc
 * seulement si quelqu'un a écrit ce test, et seulement s'il cherche au bon endroit. En production, elles
 * rendent une liste vide, ce qui ressemble exactement à « il n'y a rien ».
 *
 * **Pourquoi un garde-fou et pas une consigne.** `claude-G` s'est fait avoir **trois fois cette semaine**,
 * sur trois modules différents, en connaissant le piège. Sa conclusion, que je reprends : *ce n'est plus
 * de la vigilance, c'est une propriété du terrain.* Et notre règle est constante — quand la même erreur
 * revient une troisième fois, on ne la corrige plus, on supprime ce qui la rend possible.
 *
 * **La règle : sur une référence libre, on compare en SQL avec `UNHEX`, jamais en DQL ni par filtre.**
 *
 * Usage :
 *   php bin/garde-fou-references-libres.php
 *   php bin/garde-fou-references-libres.php --fichiers=a.php,b.php
 */

const RACINE = 'app/src';

/**
 * Les propriétés qui suivent la convention : un `Uuid` nu, sans relation Doctrine.
 *
 * On exige le suffixe `Ref` parce que c'est la convention du dépôt, et parce qu'elle évite d'attraper
 * les identifiants primaires — qui, eux, sont bien convertis puisque Doctrine connaît leur mapping.
 *
 * @return array<string, string> nom de propriété => fichier qui la déclare
 */
function referencesLibres(): array
{
    $trouvees = [];

    $entrees = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(RACINE, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($entrees as $entree) {
        if (!$entree->isFile() || $entree->getExtension() !== 'php') {
            continue;
        }

        $source = @file_get_contents($entree->getPathname());

        if ($source === false) {
            continue;
        }

        if (preg_match_all('/private\s+\??Uuid\s+\$(\w+Ref)\b/', $source, $correspondances) === false) {
            continue;
        }

        foreach ($correspondances[1] as $propriete) {
            $trouvees[$propriete] = $entree->getPathname();
        }
    }

    return $trouvees;
}

/**
 * @param list<string> $proprietes
 * @return list<array{fichier: string, ligne: int, propriete: string, forme: string, extrait: string}>
 */
function analyser(string $fichier, array $proprietes): array
{
    $source = @file_get_contents($fichier);

    if ($source === false) {
        // Un fichier annoncé et illisible n'est pas « rien à signaler » : c'est un contrôle qui n'a pas
        // eu lieu. On refuse plutôt que de compter ça comme conforme.
        fwrite(STDERR, sprintf("\n=== ERREUR — fichier illisible : %s ===\n\n", $fichier));
        exit(2);
    }

    $trouvailles = [];
    $lignes = explode("\n", $source);

    foreach ($lignes as $index => $ligne) {
        // Une ligne qui passe par `UNHEX` fait précisément ce qu'on demande : on la laisse tranquille.
        if (str_contains($ligne, 'UNHEX')) {
            continue;
        }

        foreach ($proprietes as $propriete) {
            if (!str_contains($ligne, '.' . $propriete)) {
                continue;
            }

            $forme = null;

            if (preg_match('/\.\s*' . preg_quote($propriete, '/') . '\s+IN\s*\(\s*:(\w+)/i', $ligne, $m) === 1) {
                // Aucun type scalaire ne s'applique à une liste : `setParameter(..., 'uuid')` ne
                // convertit pas les éléments d'un tableau. Cette forme est donc toujours fautive.
                $forme = 'IN (:liste) en DQL';
            } elseif (preg_match('/\.\s*' . preg_quote($propriete, '/') . '\s*=\s*:(\w+)/', $ligne, $m) === 1) {
                // **Le faux positif du premier passage, et la raison de cette garde.**
                //
                // `MesFacturesProvider` écrit `d.clientRef = :clientRef` — signalé — puis lie le
                // paramètre avec `setParameter('clientRef', $uuid, 'uuid')`. **Le type explicite fait
                // la conversion, et la requête marche.** Le contrôle accusait une requête saine.
                //
                // Ce qui échoue, c'est l'ABSENCE de type : Doctrine ne l'infère pas sur une colonne
                // `uuid` nue, faute de relation à consulter. La comparaison est donc licite quand le
                // type est donné, et fautive quand il est omis.
                // ⚠ La recherche est LOCALE, et ça a été le second défaut de ce garde-fou.
                //
                // Ma première version cherchait le `setParameter` typé dans tout le fichier. Un
                // fichier d'essai contenant une méthode saine et une méthode fautive **utilisant le
                // même nom de paramètre** — ce qui est le cas courant, on appelle tous son paramètre
                // `:ref` — voyait la fautive disculpée par la saine.
                //
                // Le garde-fou aurait donc été vert sur exactement le défaut qu'il existe pour
                // attraper, et je ne l'aurais pas su sans écrire les deux cas dans le même fichier.
                // On regarde désormais la ligne et les trois suivantes : la portée d'une chaîne
                // fluide, pas celle d'un fichier.
                $fenetre = implode("\n", array_slice($lignes, $index, 4));

                if (preg_match(
                    '/setParameter\s*\(\s*[\'"]' . preg_quote($m[1], '/') . '[\'"].{0,300}?[\'"]uuid[\'"]/s',
                    $fenetre
                ) !== 1) {
                    $forme = 'comparaison DQL sans type explicite';
                }
            }

            if ($forme !== null) {
                $trouvailles[] = [
                    'fichier' => $fichier,
                    'ligne' => $index + 1,
                    'propriete' => $propriete,
                    'forme' => $forme,
                    'extrait' => trim($ligne),
                ];
            }
        }
    }

    // Un `SearchFilter` posé sur une référence libre rend une liste vide, silencieusement.
    if (preg_match_all('/SearchFilter::class[^)]*/s', $source, $filtres) !== false) {
        foreach ($filtres[0] as $filtre) {
            foreach ($proprietes as $propriete) {
                if (!preg_match('/[\'"]' . preg_quote($propriete, '/') . '[\'"]/', $filtre)) {
                    continue;
                }

                $decalage = strpos($source, $filtre);
                $numero = $decalage === false ? 0 : substr_count($source, "\n", 0, $decalage) + 1;

                $trouvailles[] = [
                    'fichier' => $fichier,
                    'ligne' => $numero,
                    'propriete' => $propriete,
                    'forme' => 'SearchFilter sur une référence libre',
                    'extrait' => 'ApiFilter(SearchFilter::class, …' . $propriete . '…)',
                ];
            }
        }
    }

    return $trouvailles;
}

// ---------------------------------------------------------------------------------------------------

$fichiers = null;

foreach (array_slice($argv, 1) as $option) {
    if (str_starts_with($option, '--fichiers=')) {
        $fichiers = array_values(array_filter(explode(',', substr($option, strlen('--fichiers=')))));
    }
}

$references = referencesLibres();

if ($references === []) {
    echo "Références libres : OK — aucune propriété `?Uuid …Ref` déclarée, rien à contrôler.\n";
    exit(0);
}

$proprietes = array_keys($references);

if ($fichiers === null) {
    $fichiers = [];
    $entrees = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(RACINE, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($entrees as $entree) {
        if ($entree->isFile() && $entree->getExtension() === 'php') {
            $fichiers[] = $entree->getPathname();
        }
    }
}

$trouvailles = [];

foreach ($fichiers as $fichier) {
    if (!is_file($fichier)) {
        continue;
    }

    $trouvailles = [...$trouvailles, ...analyser($fichier, $proprietes)];
}

if ($trouvailles === []) {
    echo sprintf(
        "Références libres : OK — %d référence(s) libre(s) surveillée(s), aucune comparaison DQL ni filtre.\n",
        count($references)
    );
    exit(0);
}

echo "\n=== ÉCHEC — comparaison d'une référence libre en DQL ou par filtre ===\n\n";

foreach ($trouvailles as $t) {
    echo sprintf(
        "%s\n  ligne %-5d %-38s %s\n    %s\n\n",
        $t['fichier'],
        $t['ligne'],
        $t['propriete'],
        $t['forme'],
        $t['extrait']
    );
}

echo <<<TEXTE
Ces formes NE LÈVENT PAS. Elles rendent une liste vide, ou ne comptent rien — ce qui, en
production, ressemble exactement à « il n'y a rien ». Le défaut se découvre par un test qui
devrait passer et ne passe pas, donc seulement si quelqu'un l'a écrit.

Doctrine sait convertir un type personnalisé quand il connaît la relation. Sur une colonne
`uuid` nue — la convention du dépôt pour franchir une frontière de module — il ne le fait pas.

DEUX SORTIES, SELON LA FORME.

Pour une COMPARAISON SIMPLE, le type explicite suffit — Doctrine convertit alors correctement :

    ->andWhere('d.clientRef = :ref')
    ->setParameter('ref', \$uuid, 'uuid')      <-- le troisième argument est ce qui manque

Pour une LISTE, il n'existe pas de type scalaire applicable : `setParameter` ne convertit pas les
éléments d'un tableau. Il faut passer en SQL direct, avec `UNHEX` sur chaque valeur.

    \$this->connection->fetchFirstColumn(
        'SELECT ... WHERE alerte_ecart_ref IN (' . \$placeholders . ')',
        \$identifiantsHex,   // bin2hex(\$uuid->toBinary())
    );

Le contrôle ignore toute ligne contenant `UNHEX` : elle fait déjà ce qu'on demande.

TEXTE;

exit(1);
