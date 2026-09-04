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
 * ⚠ **CETTE RÈGLE EST PLUS LARGE QUE LE DANGER, ET IL FAUT LE SAVOIR AVANT DE PAYER UN CONTOURNEMENT.**
 *
 * Mesuré le 30/08 sur `EntreeAudit.etablissement`, qui est une référence libre :
 *
 *     ->andWhere('e.etablissement = :actif')
 *     ->setParameter('actif', $uuid, 'uuid')      ← fonctionne
 *
 * Le témoin positif d'un test le montre — l'entrée de l'établissement actif figure dans le résultat,
 * celle du voisin non. Ce qui échoue est la comparaison **sans type déclaré**, ou avec un objet
 * entité passé en paramètre : c'est la conversion qui manque, pas le DQL en soi.
 *
 * Pourquoi le dire ici plutôt que dans un fil de discussion : **une règle plus large que son danger
 * ne se conteste pas.** Elle est verte, donc elle a l'air juste, et elle fait écrire du SQL brut là
 * où deux lignes de DQL suffisaient — à des gens qui n'ont ni le temps ni la raison de vérifier. Le
 * contrôle reste inchangé et continue de tout refuser : on précise ce qu'il couvre en trop, on ne
 * l'assouplit pas. Le jour où quelqu'un a besoin de la forme typée, il saura qu'elle marche et
 * pourquoi elle est quand même refusée ici.
 *
 * Usage :
 *   php bin/garde-fou-references-libres.php
 *   php bin/garde-fou-references-libres.php --fichiers=a.php,b.php
 */

const RACINE = 'app/src';

/**
 * Le motif des références libres, écrit UNE SEULE FOIS.
 *
 * ⚠ Deux lecteurs posent la même question — l'inventaire global (`referencesLibres()`) et la portée
 * par fichier (`analyser()`). Écrit deux fois, il dérive : le jour où l'un s'élargit, l'autre ne suit
 * pas, et un témoin écrit avec le second partagerait exactement l'angle mort du premier.
 */
const MOTIF_REFERENCE_LIBRE = '/private\s+\??Uuid\s+\$(\w+Ref)\b/';

/**
 * Les références libres déclarées dans UN source.
 *
 * @return list<string>
 */
function referencesLibresDe(string $source): array
{
    if (preg_match_all(MOTIF_REFERENCE_LIBRE, $source, $correspondances) === false) {
        return [];
    }

    return array_values(array_unique($correspondances[1]));
}
const LIGNE_DE_BASE = 'bin/references-libres.ligne-de-base.json';

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

        foreach (referencesLibresDe($source) as $propriete) {
            $trouvees[$propriete] = $entree->getPathname();
        }
    }

    return $trouvees;
}

/**
 * Les relations Doctrine ordinaires — la moitié du piège que la première version ne voyait pas.
 *
 * **Ce que la première version manquait, et pourquoi.** Elle ne collectait que les propriétés dont le
 * nom finit par `Ref`, c'est-à-dire la convention des références libres. Mais **le défaut n'est pas
 * produit par la convention : il est produit par le type d'identifiant.** Toute entité à identifiant
 * `Uuid` est concernée — donc toutes.
 *
 * **Ce ne sont pas des hypothèses, les deux ont été payées le même jour :**
 *
 * - `claude-D` : `->andWhere('m.etablissement = :e')->setParameter('e', $etablissement)` rendait une
 *   liste **vide** alors que la donnée existait. Une heure perdue. `etablissement` n'est pas une
 *   propriété `*Ref`.
 * - `claude-G`, avec un symptôme bien pire : `JaugeCreneauGuard::placesOccupees()` comparait un créneau
 *   passé en entité, la requête rendait **zéro place occupée**, donc **la jauge acceptait une
 *   réservation sur un créneau complet**. Une liste vide se voit ; « il reste de la place » ne se voit
 *   pas — ça se découvre le jour où soixante personnes se présentent pour quarante couverts.
 *
 * **Et quelqu'un l'avait rencontré avant nous sans que personne ne le sache.**
 * `ProjectionVenteDoctrineAdapter` porte en commentaire que « `IN(:tableau)` avec un tableau
 * d'entités/UUID s'est révélé peu fiable selon le contexte d'exécution », et son auteur a contourné en
 * filtrant en PHP. `PerimetreFacturationExtension` utilise `IDENTITY()` avec le type explicite partout,
 * **sans que la raison soit dite nulle part**. Un contournement sans sa raison n'enseigne rien : il se
 * lit comme une préférence de style, et il se « simplifie » au premier passage de quelqu'un qui met de
 * l'ordre.
 *
 * @return array<string, string> nom de propriété => fichier qui la déclare
 */
function relationsUuid(): array
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

        if ($source === false || !str_contains($source, 'ORM\\ManyToOne') && !str_contains($source, 'ORM\\OneToOne')) {
            continue;
        }

        // `#[ORM\ManyToOne(...)]` puis, dans les lignes qui suivent, la propriété qu'il décore.
        $motif = '/#\[ORM\\\\(?:ManyToOne|OneToOne)[^\]]*\](?:\s*#\[[^\]]*\])*\s*private\s+\??\\?[A-Z][A-Za-z]*\s+\$(\w+)/';

        if (preg_match_all($motif, $source, $correspondances) === false) {
            continue;
        }

        foreach ($correspondances[1] as $propriete) {
            $trouvees[$propriete] = $entree->getPathname();
        }
    }

    return $trouvees;
}

/**
 * **Les deux familles ne courent pas le même risque, et les confondre a produit 61 faux positifs.**
 *
 * Ma première version de l'élargissement appliquait les trois formes aux relations Doctrine ordinaires
 * comme aux références libres. Résultat : **61 signalements, dont la grande majorité fausse** — presque
 * tous des `SearchFilter` sur `etablissement`.
 *
 * Or un `SearchFilter` sur une **relation** fonctionne : API Platform la résout par son IRI ou son
 * identifiant, et Doctrine sait convertir une relation qu'il connaît. Ce qui échoue, c'est le filtre
 * sur une colonne `uuid` **nue**, que rien ne relie à une entité.
 *
 * Le risque des relations est ailleurs : passer **l'entité elle-même** en paramètre, ou une liste
 * d'entités. C'est ce qui a rendu une jauge aveugle et une remise vide le 26/08.
 *
 * Donc :
 * - **références libres** (`*Ref`) → les trois formes, filtre compris ;
 * - **relations ordinaires** → les deux formes DQL seulement.
 *
 * Livrer la version large aurait coûté plus cher que le défaut : personne ne trie 61 lignes, et un
 * contrôle qu'on ne trie pas finit désactivé — avec les treize autres (D47, D53).
 *
 * @param list<string> $proprietes toutes les propriétés surveillées (formes DQL)
 * @param list<string> $filtrables celles qui craignent aussi un `SearchFilter` (références libres)
 * @return list<array{fichier: string, ligne: int, propriete: string, forme: string, extrait: string}>
 */
function analyser(string $fichier, array $proprietes, array $filtrables): array
{
    $source = @file_get_contents($fichier);

    if ($source === false) {
        // Un fichier annoncé et illisible n'est pas « rien à signaler » : c'est un contrôle qui n'a pas
        // eu lieu. On refuse plutôt que de compter ça comme conforme.
        fwrite(STDERR, sprintf("\n=== ERREUR — fichier illisible : %s ===\n\n", $fichier));
        exit(2);
    }

    // ⚠ UN `SearchFilter` SE JUGE DANS LE FICHIER QUI DÉCLARE LA PROPRIÉTÉ, ET C'EST NEUF (§8.13).
    //
    // `$filtrables` est l'inventaire GLOBAL des références libres du dépôt. L'utiliser tel quel fait
    // juger un filtre par un nom déclaré ailleurs : `LimiteAutorisation::$etablissement` est une
    // RELATION, et il ressortait signalé parce qu'un AUTRE fichier déclare un `?Uuid $etablissement`.
    // Le nom suffisait à contaminer.
    //
    // Or `#[ApiFilter(SearchFilter::class, ...)]` se pose sur la classe qui déclare le champ : les
    // deux vivent dans le même fichier. La portée du jugement est donc le fichier, pas le dépôt.
    //
    // ⚠ L'INTERSECTION N'EST PAS DÉCORATIVE. `referencesLibresDe()` seul suffirait à la portée ;
    // garder `$filtrables` conserve l'invariant que SEULES les références libres craignent le filtre
    // — une relation ne le craint pas, API Platform la résout par son IRI. Le jour où le collecteur
    // s'élargira, cette ligne empêchera le filtre d'accuser les relations sans qu'on y repense.
    $filtrables = array_values(array_intersect($filtrables, referencesLibresDe($source)));

    $trouvailles = [];
    $lignes = explode("\n", $source);

    foreach ($lignes as $index => $ligne) {
        // Une ligne qui passe par `UNHEX` fait précisément ce qu'on demande : on la laisse tranquille.
        if (str_contains($ligne, 'UNHEX')) {
            continue;
        }

        // ── Sixième forme : `IN (:liste)` sur l'IDENTIFIANT d'une entité, quel que soit l'alias ──
        //
        // Les cinq premières formes portent sur des **propriétés** — références libres ou relations
        // déclarées. Celle-ci porte sur `alias.id`, qui n'est ni l'une ni l'autre : ni une propriété
        // `*Ref`, ni une relation, souvent l'alias d'une **jointure**. Elle passait donc entre les
        // mailles même après l'élargissement aux relations ordinaires.
        //
        // **Deux défauts réels trouvés par ce seul motif, dans deux modules sans rapport :**
        //
        // 1. `JaugeCreneauGuard` — `cs.id IN (:creneaux)` sur une jointure `consumedSlots`. La requête
        //    s'exécute, rend zéro ligne, et la jauge répond « 0 place occupée » pour tous les créneaux.
        //    Un calendrier aurait affiché « tout est libre » sur un planning complet, et une
        //    réservation aurait été acceptée sur un créneau plein. Trouvé par `claude-G`, qui avait
        //    écrit l'avertissement D58 **sur la ligne précédente**.
        //
        // 2. `InventaireRegularisationHandler` — `a.id IN (:ids)` avec une `list<string>` venue de la
        //    requête. Un inventaire lancé sur une **sélection d'articles n'en compte aucun**. Et
        //    `claude-H` avait déjà trouvé que le périmètre « par rayon » les compte **tous** : deux
        //    modes sur trois, faux en sens inverse, aucun des deux ne levant.
        //
        // Tous les identifiants de ce dépôt sont des `Uuid` : `.id` désigne donc toujours un type
        // personnalisé, et `IN` ne le convertit jamais. Le motif est sûr sans avoir à suivre les alias.
        if (preg_match('/\.\s*id\s+IN\s*\(\s*:(\w+)/i', $ligne) === 1) {
            $trouvailles[] = [
                'fichier' => $fichier,
                'ligne' => $index + 1,
                'propriete' => 'identifiant d\'entité',
                'forme' => 'IN (:liste) sur un identifiant',
                'extrait' => trim($ligne),
            ];
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
                // ⚠ TROISIÈME DÉFAUT DE CE GARDE-FOU, ET LA FENÊTRE EST LE SUJET.
                //
                // Une fenêtre de quatre lignes convient à `->andWhere(...)->setParameter(...)` écrit
                // d'un trait. Elle est trop courte pour la forme la plus répandue du dépôt, où le
                // constructeur empile d'abord tous les `andWhere` puis tous les `setParameter` :
                //
                //     ->where('v.etablissement = :etablissement')
                //     ->andWhere('v.statut IN (:statutsScelles)')
                //     ->andWhere('v.date >= :debut')
                //     ->andWhere('v.date <= :fin')
                //     ->setParameter('etablissement', $id, 'uuid')     <-- quatre lignes plus bas
                //
                // Le contrôle accusait donc `ProjectionVenteDoctrine`, qui est saine. La bonne fenêtre
                // n'est pas un nombre de lignes, c'est **la chaîne fluide** : on lit jusqu'à la fin de
                // l'instruction.
                $fenetre = '';
                foreach (array_slice($lignes, $index, 60) as $suivante) {
                    $fenetre .= $suivante . "\n";

                    if (str_contains($suivante, ';')) {
                        break;
                    }
                }

                // ⚠ DEUX ECRITURES DU MEME TYPE, ET LA CONSTANTE EST LA MEILLEURE.
                //
                // `UuidType::NAME` vaut 'uuid' — mais elle survit a un renommage et se cherche par
                // son symbole. Le predicat ne connaissait que le littoral : il refusait donc la
                // forme la plus sure, et enseignait d'ecrire l'autre.
                //
                // Signale le 31/08 en ecrivant `PriceGridProcessor`, dont la ligne etait CORRIGEE
                // au moment ou le controle l'a accusee. Un garde-fou qui teste l'orthographe du
                // remede plutot que le remede finit par produire des contournements plutot que des
                // corrections.
                if (preg_match(
                    '/setParameter\s*\(\s*[\'"]' . preg_quote($m[1], '/') . '[\'"].{0,300}?([\'"]uuid[\'"]|UuidType::NAME)/s',
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
            foreach ($filtrables as $propriete) {
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
$relations = relationsUuid();

// Les deux familles retombent sur le même défaut et se traitent pareil : les références libres par
// convention (`*Ref`), et les relations Doctrine ordinaires. La seconde est la plus fréquente, et
// c'est celle que la première version du contrôle ne regardait pas.
$surveillees = $references + $relations;

if ($surveillees === []) {
    echo "Références libres : OK — aucune propriété à identifiant `Uuid` déclarée, rien à contrôler.\n";
    exit(0);
}

$proprietes = array_keys($surveillees);

// Seules les références libres craignent le `SearchFilter` : sur une relation, API Platform et Doctrine
// savent convertir.
$filtrables = array_keys($references);

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

    $trouvailles = [...$trouvailles, ...analyser($fichier, $proprietes, $filtrables)];
}

/**
 * Le cliquet, dans la forme des treize autres garde-fous : la dette connue est gelée, un défaut neuf
 * fait refuser la poussée.
 *
 * **Pourquoi geler plutôt que corriger d'abord.** Les douze entrées sont réparties sur six périmètres.
 * Laisser le contrôle rouge bloquerait les neuf sessions sur des défauts qui ne sont pas les leurs — et
 * un contrôle qui bloque tout le monde se contourne avant d'être corrigé. Le cliquet fige ce qui existe
 * et rend impossible le treizième.
 *
 * **Chaque entrée gelée reste un défaut réel**, pas une tolérance de style : une comparaison sans type
 * rend une liste vide, un `IN` ne trouve rien. Elles sont distribuées à leurs propriétaires.
 */
$cle = static fn (array $t): string => sprintf('%s:%s:%s', $t['fichier'], $t['propriete'], $t['forme']);

$gelees = is_file(LIGNE_DE_BASE)
    ? (array) json_decode((string) file_get_contents(LIGNE_DE_BASE), true)
    : [];

if (in_array('--nettoyer', array_slice($argv, 1), true)) {
    $liste = array_values(array_unique(array_map($cle, $trouvailles)));
    sort($liste);
    file_put_contents(LIGNE_DE_BASE, json_encode($liste, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo sprintf("Ligne de base réécrite : %d entrée(s) gelée(s).\n", count($liste));
    exit(0);
}

$neuves = array_values(array_filter($trouvailles, static fn (array $t): bool => !in_array($cle($t), $gelees, true)));
$resorbees = count($gelees) - count(array_intersect($gelees, array_map($cle, $trouvailles)));

if ($neuves === []) {
    echo sprintf(
        "Références libres : OK — %d propriété(s) surveillée(s) (%d référence(s) libre(s), %d relation(s)). "
        . "Dette gelée : %d, plafond %d.%s\n",
        count($surveillees),
        count($references),
        count($relations),
        count($gelees) - $resorbees,
        count($gelees),
        $resorbees > 0 ? sprintf(' %d résorbée(s) — pense à --nettoyer.', $resorbees) : '',
    );
    exit(0);
}

$trouvailles = $neuves;

if ($trouvailles === []) {
    echo sprintf(
        "Références libres : OK — %d propriété(s) surveillée(s) (%d référence(s) libre(s), %d relation(s)), "
        . "aucune comparaison DQL ni filtre.\n",
        count($surveillees),
        count($references),
        count($relations)
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
