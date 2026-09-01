<?php

declare(strict_types=1);

/**
 * Garde-fou n°34 — une contrainte qui ne verra jamais la valeur du processeur (T22, §3 quinquies).
 *
 * ── LA RACINE ───────────────────────────────────────────────────────────────────────────────────
 *
 * API Platform valide la charge du client, PUIS appelle le processeur. La validation ne voit donc
 * JAMAIS ce que le processeur écrit ensuite. Deux sessions s'y sont cassées le même jour, dans les
 * deux sens : un `Assert\NotNull` sur un champ posé par le processeur refusait la création (422) ;
 * un `Assert\Callback` sur un champ posé par le processeur laissait passer la valeur fabriquée.
 *
 * ── POURQUOI LE PREMIER DÉTECTEUR RATAIT SON PROPRE TÉMOIN ──────────────────────────────────────
 *
 * Il indexait les setters des processeurs par NOM COURT de classe. Le dépôt compte **neuf** classes
 * `EstablishmentStampProcessor`, une par module : les neuf s'écrasaient, et seule la dernière
 * parcourue survivait. Celle de `Boutique` est la seule à poser le slug — le témoin ressortait donc
 * « non posé par son processeur » alors que ses trois composants étaient corrects isolément.
 *
 * Mesuré : 312 processeurs, 303 noms courts, **2 noms en collision**. Ici, chaque `processor:` est
 * résolu en nom PLEINEMENT QUALIFIÉ via les `use` du fichier. Deux modules ont le droit de nommer
 * leurs classes pareil.
 *
 * ── POURQUOI LA RÈGLE EST PLUS ÉTROITE QUE SON ÉNONCÉ, MESURE À L'APPUI ─────────────────────────
 *
 * L'énoncé de T22 — « toute propriété écrite par un processeur ET porteuse d'une contrainte » —
 * rend **32** écarts. Classés :
 *
 *     25   la propriété est ÉCRIVABLE par le client (`:write`, `:create`, `:update`, `:patch`).
 *          Le client envoie la valeur, la contrainte la contrôle, elle fait un vrai travail.
 *          Un cliquet à 32 gèlerait ces 25 non-défauts, et le signal ne voudrait plus rien dire.
 *      4   la propriété n'est PAS écrivable et porte un DÉFAUT qui satisfait sa contrainte.
 *          Celle-ci valide le défaut, passe toujours, et la valeur calculée ne traverse rien.
 *          **Contrainte inerte** — elle ressemble à une protection et n'en est pas une.
 *      0   refus garanti au POST (non écrivable, sans défaut). Le symptôme bruyant a été corrigé.
 *
 * ⚠ ET LE TÉMOIN POSITIF DE LA TÂCHE EST PÉRIMÉ. `Vitrine::$slug` était un vrai cas ; il est
 *   corrigé depuis, par le bon remède — `VitrineResolver::fabriquerSlug()` refuse lui-même les noms
 *   d'hôte réservés, là où la valeur finale existe (D106). La propriété est en outre écrivable. Le
 *   signaler serait accuser du code juste. Il sert donc ici de témoin **négatif**.
 *
 * ── CE QU'IL NE SAIT PAS FAIRE, ET QUE PERSONNE NE DEVRAIT LUI DEMANDER ─────────────────────────
 *
 * Décider si un processeur FABRIQUE une valeur (défaut inventé, cas du slug) ou RECOPIE celle du
 * client demanderait de lire son intention. Ça ne se décide pas sur du texte. C'est pourquoi les 25
 * écrivables ne sont pas signalés : parmi eux se cachent peut-être de vrais cas, mais les distinguer
 * à vue produirait 25 accusations dont on ne saurait dire lesquelles tiennent — et un contrôle qui
 * crie au loup finit désactivé, avec ses vraies alertes.
 */

$racine = dirname(__DIR__);
$source = $racine . '/app/src';
$baseChemin = $racine . '/bin/validation-avant-processeur.ligne-de-base.json';
$detail = in_array('--detail', $argv, true);
$nettoyer = in_array('--nettoyer', $argv, true);

if (!is_dir($source)) {
    echo "Validation avant processeur : app/src introuvable, rien à lire.\n";
    exit(0);
}

$fichiers = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source)) as $entree) {
    if ($entree->isFile() && $entree->getExtension() === 'php') {
        $fichiers[$entree->getPathname()] = (string) file_get_contents($entree->getPathname());
    }
}

function nomPleinementQualifie(string $chemin, string $texte): string
{
    $classe = basename($chemin, '.php');

    return preg_match('/^namespace\s+([\w\\\\]+)\s*;/m', $texte, $m) === 1 ? $m[1] . '\\' . $classe : $classe;
}

function importsDe(string $texte): array
{
    $table = [];
    preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m', $texte, $lignes, PREG_SET_ORDER);
    foreach ($lignes as $ligne) {
        $fqcn = $ligne[1];
        $court = ($ligne[2] ?? '') !== '' ? $ligne[2] : substr((string) strrchr('\\' . $fqcn, '\\'), 1);
        $table[$court] = $fqcn;
    }

    return $table;
}

// ── Ce que chaque processeur écrit, par nom pleinement qualifié ─────────────────────────────────
$ecrit = [];
foreach ($fichiers as $chemin => $texte) {
    if (!str_ends_with(basename($chemin, '.php'), 'Processor')) {
        continue;
    }
    preg_match_all('/->set([A-Z]\w*)\(/', $texte, $poses);
    $propres = [];
    foreach ($poses[1] as $nom) {
        $propres[lcfirst($nom)] = true;
    }
    $ecrit[nomPleinementQualifie($chemin, $texte)] = $propres;
}

$ecrivables = [];
$inertes = [];
$refusGaranti = [];
$entitesLues = 0;
$nonResolus = [];

foreach ($fichiers as $chemin => $texte) {
    if (!str_contains($texte, '#[ORM\Entity') || !str_contains($texte, 'ApiResource')) {
        continue;
    }
    preg_match_all('/processor:\s*(\w+)::class/', $texte, $trouves);
    if ($trouves[1] === []) {
        continue;
    }
    ++$entitesLues;

    $imports = importsDe($texte);
    $relatif = str_replace($racine . '/', '', $chemin);

    $poseurs = [];
    foreach (array_unique($trouves[1]) as $court) {
        $fqcn = $imports[$court] ?? null;
        if ($fqcn === null || !isset($ecrit[$fqcn])) {
            $nonResolus[$relatif . ' → ' . $court] = true;
            continue;
        }
        $poseurs[$fqcn] = $ecrit[$fqcn];
    }
    if ($poseurs === []) {
        continue;
    }

    // La propriété, ses attributs contigus, et son éventuel défaut.
    $blocs = [];
    preg_match_all(
        '/((?:^[ \t]*#\[[^\n]*\]\n)+)[ \t]*(?:private|protected|public)[^\n]*?\$(\w+)\s*(=\s*[^;]+)?;/m',
        $texte,
        $trouvailles,
        PREG_SET_ORDER
    );
    foreach ($trouvailles as $t) {
        $blocs[$t[2]] = ['attributs' => $t[1], 'defaut' => trim($t[3] ?? '')];
    }

    $contraintes = [];
    foreach ($blocs as $propriete => $bloc) {
        preg_match_all('/#\[Assert\\\\(\w+)/', $bloc['attributs'], $familles);
        foreach ($familles[1] as $famille) {
            $contraintes[$propriete][$famille] = true;
        }
    }
    // `Assert\Callback` vit sur une MÉTHODE : il se rattache à sa propriété par `atPath`.
    if (str_contains($texte, 'Assert\Callback')) {
        preg_match_all("/->atPath\('(\w+)'/", $texte, $chemins);
        foreach ($chemins[1] as $propriete) {
            $contraintes[$propriete]['Callback'] = true;
        }
    }

    foreach ($contraintes as $propriete => $familles) {
        $auteurs = [];
        foreach ($poseurs as $fqcn => $proprietes) {
            if (isset($proprietes[$propriete])) {
                $auteurs[] = substr((string) strrchr('\\' . $fqcn, '\\'), 1);
            }
        }
        if ($auteurs === []) {
            continue;
        }

        $noms = array_keys($familles);
        sort($noms);
        $cle = $relatif . '::' . $propriete;
        $info = [
            'contraintes' => implode('/', $noms),
            'poseurs' => implode(', ', array_unique($auteurs)),
        ];

        $attributs = $blocs[$propriete]['attributs'] ?? '';
        $defaut = $blocs[$propriete]['defaut'] ?? '';

        // ⚠ Quatre suffixes valent écriture, pas un seul. Ne chercher que `write` classait
        //    `Produit::type` — exposé en `produit:create` — comme non écrivable, et inventait un
        //    défaut certain qui n'en était pas un.
        if (preg_match('/Groups\(\[[^\]]*:(write|create|update|patch)[^\]]*\]\)/', $attributs) === 1) {
            $ecrivables[$cle] = $info;
            continue;
        }

        if ($defaut !== '' && $defaut !== '= null') {
            $info['defaut'] = ltrim($defaut, '= ');
            $inertes[$cle] = $info;
        } else {
            $refusGaranti[$cle] = $info;
        }
    }
}

ksort($inertes);
ksort($refusGaranti);
ksort($ecrivables);

// ── Témoins : un contrôle qu'on n'a jamais vu se comporter sur un cas connu ne mesure rien ──────
$temoins = [
    // positif : contrainte inerte avérée — défaut `1`, propriété non exposée, posée par son processeur
    'app/src/Reservation/Entity/Reservation.php::quantity' => 'inerte',
    // négatif : corrigé depuis (D106), et écrivable — le signaler serait accuser du code juste
    'app/src/Boutique/Entity/Vitrine.php::slug' => 'ecrivable',
    // négatif : posé par un processeur, mais SANS aucune contrainte — cas normal, jamais lu ici
    'app/src/Facturation/Entity/ParametreFacturationEtablissement.php::profilExploitant' => 'absent',
];
$echecs = [];
foreach ($temoins as $cle => $attendu) {
    // Un temoin ne vaut que sur le depot qu'il decrit : ailleurs, son fichier n'existe pas et
    // l'exiger ferait echouer le controle pour une raison qui ne le concerne pas.
    if (!is_file($racine . '/' . explode('::', $cle)[0])) {
        continue;
    }
    $reel = isset($inertes[$cle]) ? 'inerte'
        : (isset($ecrivables[$cle]) ? 'ecrivable'
        : (isset($refusGaranti[$cle]) ? 'refus' : 'absent'));
    if ($reel !== $attendu) {
        $echecs[] = sprintf('%s — attendu « %s », obtenu « %s »', $cle, $attendu, $reel);
    }
}

if ($echecs !== []) {
    echo "✗ Validation avant processeur : le détecteur rate ses propres témoins.\n\n";
    echo "  Tant qu'il se trompe sur un cas CONNU, ses autres résultats ne valent rien non plus.\n\n";
    foreach ($echecs as $ligne) {
        echo "    $ligne\n";
    }
    exit(1);
}

$fautifs = $inertes + $refusGaranti;

$base = is_file($baseChemin)
    ? (json_decode((string) file_get_contents($baseChemin), true) ?: [])
    : ['scelle' => ['plafond' => 0], 'entrees' => []];
$gelees = [];
foreach ($base['entrees'] ?? [] as $entree) {
    $gelees[$entree['cle'] ?? ''] = true;
}
$plafond = (int) ($base['scelle']['plafond'] ?? 0);

if ($nettoyer) {
    $restantes = [];
    foreach ($base['entrees'] ?? [] as $entree) {
        if (isset($fautifs[$entree['cle'] ?? ''])) {
            $restantes[] = $entree;
        }
    }
    $base['entrees'] = $restantes;
    $base['scelle']['plafond'] = count($restantes);
    file_put_contents($baseChemin, json_encode($base, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    printf("Ligne de base nettoyée : %d entrée(s), plafond %d.\n", count($restantes), count($restantes));
    exit(0);
}

$nouveaux = array_diff_key($fautifs, $gelees);
$resorbees = array_diff_key($gelees, $fautifs);

if ($detail) {
    printf("── ÉCRIVABLES par le client — non signalés, la contrainte travaille : %d ──\n", count($ecrivables));
    foreach ($ecrivables as $cle => $info) {
        printf("     %-62s %-20s ← %s\n", $cle, $info['contraintes'], $info['poseurs']);
    }
    printf("\n── INERTES — défaut qui satisfait la contrainte : %d ──\n", count($inertes));
    foreach ($inertes as $cle => $info) {
        printf("  !  %-62s %-20s défaut %s\n", $cle, $info['contraintes'], $info['defaut']);
    }
    printf("\n── REFUS GARANTI au POST : %d ──\n\n", count($refusGaranti));
}

if ($nouveaux === []) {
    printf(
        "Validation avant processeur : OK — %d entité(s) à processeur, %d contrainte(s) inerte(s), %d refus garanti(s). Dette gelée : %d, plafond %d.\n",
        $entitesLues,
        count($inertes),
        count($refusGaranti),
        count($gelees),
        $plafond
    );
    printf("  %d contrainte(s) écrivable(s) par le client, non signalée(s) : elles font un vrai travail.\n", count($ecrivables));
    if ($resorbees !== []) {
        printf("  %d résorbée(s) — pense à --nettoyer.\n", count($resorbees));
    }
    if ($nonResolus !== []) {
        printf("  %d processeur(s) désigné(s) non résolu(s) faute de `use`.\n", count($nonResolus));
    }
    exit(0);
}

echo "✗ Validation avant processeur : une contrainte ne verra jamais la valeur du processeur.\n";
echo "\n";
echo "  La validation s'exécute AVANT le processeur. Cette propriété n'est pas exposée en écriture,\n";
echo "  donc la contrainte ne peut voir que le défaut déclaré — qu'elle accepte — pendant que la\n";
echo "  valeur réellement enregistrée, celle que le processeur calcule, ne traverse aucun contrôle.\n";
echo "\n";
echo "  Elle ressemble à une protection et n'en est pas une.\n";
echo "\n";

foreach ($nouveaux as $cle => $info) {
    printf("    %-62s %-20s ← %s\n", $cle, $info['contraintes'], $info['poseurs']);
}

echo "\n";
echo "  La sortie n'est pas de retirer la contrainte, c'est de la déplacer là où la valeur finale\n";
echo "  existe : dans le processeur, ou dans le service qui la fabrique. C'est ce qu'a fait\n";
echo "  `VitrineResolver::fabriquerSlug()` pour les noms d'hôte réservés (D106).\n";
echo "\n";
echo "  Si la propriété est en réalité renseignée par le client par un autre chemin, la contrainte\n";
echo "  est légitime et se déclare dans bin/validation-avant-processeur.ligne-de-base.json.\n";

exit(1);
