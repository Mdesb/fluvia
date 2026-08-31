<?php

declare(strict_types=1);

/**
 * Garde-fou n°21 — un test de cloisonnement qui n'assure pas la NON-VACUITÉ ne mesure rien.
 *
 * ── LE DÉFAUT QU'IL PRÉVIENT ────────────────────────────────────────────────────────────────────
 *
 * Un test de cloisonnement dit : « les données de l'autre établissement ne sont PAS dans la liste ».
 *
 *     $libelles = /* … la collection … *\/;
 *     self::assertNotContains('Guichet Patinoire B', $libelles);
 *
 * Contre une liste VIDE, cette assertion passe **sans avoir rien regardé**. Et une liste peut être
 * vide pour quantité de raisons qui n'ont rien à voir avec le cloisonnement : en-tête
 * `X-Etablissement` absent, jeu de données incomplet, filtre trop serré, route renommée.
 *
 *   > **Le garde-fou et la régression ont alors la même signature : vert.**
 *
 * ── POURQUOI MAINTENANT ─────────────────────────────────────────────────────────────────────────
 *
 * La bascule d'axe du 28/08 a rendu ce piège systématique. Sans établissement actif, une collection
 * rend désormais une liste VIDE plutôt qu'une erreur — c'est le bon choix (une liste vide se
 * remarque, une liste inter-établissements a seulement l'air plus longue), mais il multiplie les
 * façons dont une assertion de non-appartenance peut réussir pour rien.
 *
 * Mesuré le 29/08 : 199 assertions de non-appartenance dans 97 fichiers ; 40 fichiers en portent
 * une sans aucune garde de non-vacuité, dont 18 nommés cloisonnement, périmètre ou sécurité.
 *
 * ── CE QU'IL EXIGE ──────────────────────────────────────────────────────────────────────────────
 *
 * Une méthode de test qui interroge l'API et affirme une NON-appartenance doit d'abord établir que
 * la réponse contient quelque chose. N'importe laquelle de ces gardes suffit :
 *
 *     self::assertNotEmpty($liste, '…');
 *     self::assertGreaterThan(0, \count($liste));
 *     self::assertCount(3, $liste);
 *     self::assertContains('…', $liste);        // affirmer une présence vaut garde
 *
 * ── L'ÉCHAPPATOIRE ──────────────────────────────────────────────────────────────────────────────
 *
 * Certaines non-appartenances sont légitimement insensibles au vide : vérifier qu'un IBAN chiffré
 * ne contient pas le clair n'a aucun besoin d'une liste pleine.
 *
 *     @vacuite-sans-objet : <pourquoi le vide ne peut pas faire passer cette assertion>
 *
 * Greppable, datée, attribuable — même porte que `@cloisonnement-verifie`, `@drop-voulu`,
 * `@liaison-verifiee` et `@rempli-au-serveur`.
 *
 * ── CE QU'IL NE PRÉTEND PAS FAIRE ───────────────────────────────────────────────────────────────
 *
 * Il raisonne par MÉTHODE et par présence de motifs, pas par flot de données : il ne sait pas si la
 * garde protège vraiment l'assertion visée. Il sous-estime (une garde éloignée compte quand même)
 * et surestime (une assertion légitime est signalée). C'est une liste de travail avec un cliquet,
 * pas un verdict — et c'est pour cela que l'échappatoire existe.
 *
 * Usage :
 *   php bin/garde-fou-vacuite-tests.php
 *   php bin/garde-fou-vacuite-tests.php --nettoyer
 */

const RACINE_TESTS = 'app/tests';
const LIGNE_DE_BASE = 'bin/vacuite-tests.ligne-de-base.json';

/** Les assertions qui passent toutes seules contre une liste vide. */
const MOTIF_NON_APPARTENANCE = '/self::assert(NotContains|ArrayNotHasKey|StringNotContainsString)\s*\(/';

/** Ce qui établit qu'il y avait quelque chose à regarder. */
const MOTIF_GARDE = '/self::assert(NotEmpty|Count|Contains|GreaterThan|ArrayHasKey|StringContainsString|Same\(\s*\d+\s*,\s*\\\\?count)\s*\(/';

/** Le test parle-t-il à l'API ? Une assertion sur un objet en mémoire n'est pas concernée. */
// ⚠ TROISIEME FORME, TROUVEE PAR allaccess-b8 : LA BOUCLE QUI NE TOURNE PAS.
//
//     foreach ($membres as $item) {
//         self::assertNotSame('Espace Acces B prive', $item['libelle']);
//     }
//
// Une liste vide n'execute jamais le corps : zero assertion jouee, test vert. Le motif general
// ne voyait que `assertNotContains` et ses deux cousins ; `assertNotSame` dans une boucle lui
// echappait, et les trois fichiers concernes n'etaient meme pas dans sa dette gelee.
//
// L'extension exige la CONJONCTION boucle + non-egalite. Ajouter `assertNotSame` au motif
// general aurait signale des centaines de comparaisons legitimes — et un garde-fou qui refuse
// du travail correct finit desactive.
const MOTIF_BOUCLE = '/\bforeach\s*\(/';
const MOTIF_NON_EGALITE = '/self::assert(NotSame|NotEquals)\s*\(/';

const MOTIF_REQUETE = '/->request\(\s*[\'"](GET|POST|PATCH|PUT|DELETE)[\'"]/';

const MOTIF_ANNOTATION = '/@vacuite-sans-objet\s*:\s*\S/';

/**
 * Découpe un fichier de test en méthodes.
 *
 * Volontairement grossier : on cherche `function testXxx` et on prend jusqu'à la prochaine. Une
 * méthode privée d'assistance placée entre deux tests est rattachée au précédent — c'est sans
 * conséquence ici, elle ne porte ni requête ni assertion de non-appartenance.
 *
 * @return array<string, string>
 */
/**
 * Une non-egalite se trouve-t-elle A L'INTERIEUR d'un `foreach` ?
 *
 * ⚠ LA COEXISTENCE NE SUFFIT PAS, ET LE PREMIER ESSAI L'A PROUVE.
 *
 * Exiger seulement qu'un `foreach` et un `assertNotSame` figurent dans le meme corps a signale a
 * tort `PublicCatalogApiTest::testLaVitrineNePublieRienSurLesClients` : le decoupage par
 * `function test…` fait deborder le corps sur les aides privees qui suivent, et il y a ramasse un
 * `foreach` d'un cote, un `assertNotSame([], $membres)` de l'autre — ce dernier etant une GARDE.
 *
 * Deux fragments sans rapport, lus ensemble, et le controle concluait. On lit donc l'interieur des
 * blocs, en comptant les accolades : le seul endroit ou la question a un sens.
 */
function nonEgaliteDansUneBoucle(string $corps): bool
{
    $decalage = 0;

    while (preg_match(MOTIF_BOUCLE, $corps, $trouve, PREG_OFFSET_CAPTURE, $decalage) === 1) {
        $debut = strpos($corps, '{', $trouve[0][1]);
        if ($debut === false) {
            break;
        }

        // Fin du bloc : l'accolade qui ramene la profondeur a zero. Un `foreach` non accolade
        // (instruction unique) n'est pas couvert — il ne se rencontre pas dans ces tests, et le
        // supposer serait ajouter une hypothese a un controle qui doit rester lisible.
        $profondeur = 0;
        $fin = null;
        for ($i = $debut, $n = strlen($corps); $i < $n; ++$i) {
            if ($corps[$i] === '{') {
                ++$profondeur;
            } elseif ($corps[$i] === '}') {
                --$profondeur;
                if ($profondeur === 0) {
                    $fin = $i;
                    break;
                }
            }
        }

        if ($fin === null) {
            break;
        }

        if (preg_match(MOTIF_NON_EGALITE, substr($corps, $debut, $fin - $debut)) === 1) {
            return true;
        }

        $decalage = $fin;
    }

    return false;
}

function methodes(string $source): array
{
    if (preg_match_all('/\bfunction\s+(test\w+)\s*\(/', $source, $noms, PREG_OFFSET_CAPTURE) === 0) {
        return [];
    }

    $trouvees = [];
    $bornes = $noms[0];
    foreach ($bornes as $i => $borne) {
        $debut = (int) $borne[1];
        $fin = isset($bornes[$i + 1]) ? (int) $bornes[$i + 1][1] : \strlen($source);
        $trouvees[$noms[1][$i][0]] = substr($source, $debut, $fin - $debut);
    }

    return $trouvees;
}

/** @return list<string> */
function methodesSansGarde(string $racine): array
{
    $trouvees = [];
    $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));

    /** @var SplFileInfo $fichier */
    foreach ($iterateur as $fichier) {
        if (!$fichier->isFile() || !str_ends_with($fichier->getFilename(), 'Test.php')) {
            continue;
        }

        $source = (string) file_get_contents($fichier->getPathname());
        if (preg_match(MOTIF_ANNOTATION, $source) === 1) {
            continue;
        }

        $relatif = substr($fichier->getPathname(), strlen($racine) + 1);

        foreach (methodes($source) as $nom => $corps) {
            if (preg_match(MOTIF_REQUETE, $corps) !== 1) {
                continue;
            }
            // Non-appartenance directe, OU boucle sur une collection dont le corps n'affirme
            // qu'une non-egalite : les deux passent a coup sur sur une liste vide.
            $nonAppartenance = preg_match(MOTIF_NON_APPARTENANCE, $corps) === 1;
            $boucleVide = nonEgaliteDansUneBoucle($corps);

            if (!$nonAppartenance && !$boucleVide) {
                continue;
            }
            if (preg_match(MOTIF_GARDE, $corps) === 1) {
                continue;
            }

            $trouvees[] = $relatif . '::' . $nom;
        }
    }

    sort($trouvees);

    return $trouvees;
}

$options = array_slice($argv, 1);
$sansGarde = methodesSansGarde(RACINE_TESTS);

if (in_array('--nettoyer', $options, true)) {
    file_put_contents(
        LIGNE_DE_BASE,
        json_encode(
            ['scelle' => ['plafond' => count($sansGarde)], 'entrees' => $sansGarde],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) . "\n"
    );
    printf("Ligne de base réécrite : %d méthode(s), plafond %d.\n", count($sansGarde), count($sansGarde));
    exit(0);
}

if (!is_file(LIGNE_DE_BASE)) {
    fwrite(STDERR, sprintf("Ligne de base absente : %s\nCrée-la : php %s --nettoyer\n", LIGNE_DE_BASE, $argv[0]));
    exit(2);
}

$base = json_decode((string) file_get_contents(LIGNE_DE_BASE), true, 512, JSON_THROW_ON_ERROR);
$plafond = (int) ($base['scelle']['plafond'] ?? 0);
$connues = (array) ($base['entrees'] ?? []);
$nouvelles = array_values(array_diff($sansGarde, $connues));

if ($nouvelles !== []) {
    fwrite(STDERR, sprintf(
        "Assertions de non-appartenance sans garde de non-vacuité : %d nouvelle(s), plafond %d.\n\n",
        count($nouvelles),
        $plafond
    ));
    foreach ($nouvelles as $nouvelle) {
        fwrite(STDERR, '    ' . $nouvelle . "\n");
    }
    fwrite(STDERR, "\nContre une liste vide, `assertNotContains` passe SANS AVOIR RIEN REGARDÉ.\n");
    fwrite(STDERR, "Établis d'abord qu'il y avait quelque chose à voir :\n\n");
    fwrite(STDERR, "    self::assertNotEmpty(\$liste, 'sinon le test ne prouverait rien');\n\n");
    fwrite(STDERR, "Ou déclare que le vide ne peut pas faire passer cette assertion :\n\n");
    fwrite(STDERR, "    @vacuite-sans-objet : <pourquoi>\n");
    exit(1);
}

printf(
    "Vacuité des tests : OK — aucune nouvelle. Dette gelée : %d, plafond %d.\n",
    count($sansGarde),
    $plafond
);
exit(0);
