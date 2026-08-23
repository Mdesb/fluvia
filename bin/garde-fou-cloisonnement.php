#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Garde-fou n°1 — cloisonnement (D3, D8).
 *
 * Refuse toute **résolution d'entité à partir d'un identifiant fourni par le client** qui ne
 * revérifie pas l'appartenance au périmètre.
 *
 * Pourquoi cette formulation et pas « tout endpoint qui touche la base » : le cloisonnement de ce
 * projet repose sur les extensions Doctrine `Perimetre*`, qui ne s'appliquent qu'aux opérations de
 * **lecture** d'API Platform. Un Processor qui fait `getRepository(X::class)->find($idDuCorps)` sort
 * du filet sans que rien ne le signale (D8). C'est cet angle mort précis que ce contrôle surveille —
 * viser plus large produirait un bruit tel que le garde-fou finirait désactivé.
 *
 * Usage :
 *   php bin/garde-fou-cloisonnement.php              # contrôle (code de sortie 1 si échec)
 *   php bin/garde-fou-cloisonnement.php --liste      # toutes les violations actuelles, triées
 *   php bin/garde-fou-cloisonnement.php --nettoyer   # retire de la ligne de base ce qui est corrigé
 *   php bin/garde-fou-cloisonnement.php --contre=origin/main   # cliquet réel (voir ci-dessous)
 *
 * **Pourquoi `--contre` existe.** Le plafond seul ne suffit pas : qui ajoute une entrée peut relever
 * le plafond dans le même commit, et le contrôle passe. Un cliquet dont l'auteur détient la référence
 * n'est pas un cliquet. `--contre=<ref>` relit le plafond sur une révision que l'auteur ne contrôle
 * pas — la branche cible en CI — et refuse toute remontée. C'est là, et seulement là, que la règle
 * « la ligne de base ne peut que rétrécir » devient opposable.
 *
 * Aucune dépendance : ni Composer, ni conteneur, ni base. Tourne en local comme en CI.
 */

const RACINE_SRC = 'app/src';
const LIGNE_DE_BASE = 'bin/cloisonnement.ligne-de-base.json';

/** Usage RÉEL d'un identifiant client — pas la signature de `process()`, qui contient toujours `$uriVariables`. */
const MOTIF_ID_CLIENT = '/\$uriVariables\s*\[|->corps\(\)|\$request->(?:query|request|attributes)->get\(/';

/** Résolution d'une entité à partir de cet identifiant. */
const MOTIF_RESOLUTION = '/->find\(|->findOneBy\(|->getReference\(/';

/**
 * Formes de contrôle de périmètre reconnues. La première est le motif canonique validé par
 * l'intégrateur le 19/08 : l'autorité se recalcule contre l'établissement de l'entité visée, pas
 * contre l'en-tête `X-Etablissement` (D6), et l'échec est fermé en 404.
 */
const MOTIFS_CONTROLE = [
    'codesEffectifs()'        => '/codesEffectifs\s*\(/',
    // `verifierAcces*` rejoint `Verificateur`/`Guard` : ce sont trois façons de nommer la même
    // chose — un assistant de garde. Ajouté après le correctif du 23/08 (n°11), où le contrôle a
    // été posé dans `ResolutionClientSoiTrait::verifierAccesSoi()` : les trois Providers CRM
    // étaient corrigés et restaient signalés, faute que le motif reconnaisse cette forme.
    'Verificateur/Guard'      => '/Verificateur|Guard|verifierAcces/',
    'ContexteEtablissement'   => '/ContexteEtablissement/',
    'extension Perimetre'     => '/Perimetre\w*/',
    'getEtablissement()'      => '/->getEtablissement\(\)/',
    // Declaration explicite : le fichier porte un controle de perimetre d'une forme que les motifs
    // ci-dessus ne savent pas reconnaitre. Le message du garde-fou promettait deja cette porte de
    // sortie (« documente-le dans le fichier lui-meme ») sans qu'elle existe — la voici.
    //
    // Elle est preferable a l'elargissement des motifs : une exemption declaree est greppable,
    // datee et attribuable, alors qu'une detection assouplie ouvre un trou pour tout le monde et
    // sans trace. L'annotation doit porter une raison — le deux-points suivi de texte est exige.
    //
    //     @cloisonnement-verifie : <pourquoi ce fichier est controle, et par qui>
    //
    // Pour auditer les exemptions : grep -rn "@cloisonnement-verifie" app/src
    'annotation explicite'    => '/@cloisonnement-verifie\s*:\s*\S/',
];

const AIDE_CORRECTION = <<<'TXT'
    Motif attendu (référence : correctifs Caisse/SEPA du 19/08, MESSAGES.md) :

        $codes = $this->calculateur->codesEffectifs(
            $utilisateur,
            $entiteVisee->getEtablissement()?->getId()   // l'établissement de l'ENTITÉ, pas l'en-tête
        );
        if (!$this->calculateur->autorise($codes, '<module>', '<action>')) {
            throw new NotFoundHttpException('… introuvable.');   // 404, pas 403 : ne pas révéler l'existence
        }

    Deux points qui ne sont pas des détails :
      - l'autorité se recalcule contre l'établissement de l'entité résolue. `ContexteEtablissement`
        lit l'en-tête client `X-Etablissement` : c'est un sélecteur, pas une preuve d'appartenance (D6) ;
      - 404 et non 403 : distinguer « hors périmètre » de « inexistant » permet d'énumérer l'activité
        d'un autre établissement, ce qui est déjà une fuite.

    Si ce fichier n'a légitimement pas de périmètre (ressource publique, tâche système), ce n'est PAS
    à la ligne de base de l'absorber : documente-le dans le fichier lui-même et viens en parler dans
    MESSAGES.md. La ligne de base est gelée et ne peut que rétrécir.
    TXT;

// ---------------------------------------------------------------------------- analyse

/** @return list<string> chemins relatifs à app/src, triés */
function fichiersHttp(string $racine): array
{
    if (!is_dir($racine)) {
        fwrite(STDERR, sprintf("Répertoire source introuvable : %s\nLance ce script depuis la racine du dépôt.\n", $racine));
        exit(2);
    }

    $trouves = [];
    $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));

    /** @var SplFileInfo $fichier */
    foreach ($iterateur as $fichier) {
        if (!$fichier->isFile() || $fichier->getExtension() !== 'php') {
            continue;
        }
        $relatif = str_replace('\\', '/', substr($fichier->getPathname(), strlen($racine) + 1));
        if (!str_contains($relatif, '/State/') && !str_contains($relatif, '/Controller/')) {
            continue;
        }
        $trouves[] = $relatif;
    }

    sort($trouves);

    return $trouves;
}

/** @return list<string> les fichiers en violation, triés */
function violations(string $racine): array
{
    $resultat = [];

    foreach (fichiersHttp($racine) as $relatif) {
        $source = (string) file_get_contents($racine . '/' . $relatif);

        if (preg_match(MOTIF_ID_CLIENT, $source) !== 1 || preg_match(MOTIF_RESOLUTION, $source) !== 1) {
            continue;
        }

        foreach (MOTIFS_CONTROLE as $motif) {
            if (preg_match($motif, $source) === 1) {
                continue 2;
            }
        }

        $resultat[] = $relatif;
    }

    return $resultat;
}

// ------------------------------------------------- règle n°2 (C19) : contrôle LIÉ à l'entité
//
// L'IDOR d'appairage du 22/08 a montré la limite de la règle n°1 : elle constate la *présence* d'un
// motif de périmètre dans le fichier, pas le fait qu'il porte sur **l'entité résolue depuis l'entrée
// client**. `AppairageProcessor` appelait `etablissementActif()` pour tout autre chose, et résolvait
// `$droit` depuis le corps de la requête sans rien comparer. Le fichier paraissait contrôlé.
//
// Cette règle-ci lie les deux : elle capture la variable issue du `find()` alimenté par l'entrée
// client, puis exige qu'un contrôle mentionne **cette variable**. C'est plus étroit qu'une analyse
// de flot de données — un contrôle indirect via une variable intermédiaire lui échappe encore — mais
// ça ferme le cas concret qui a produit quatre IDOR sur ce projet.

/**
 * Une **instruction** contenant une résolution, affectée ou non.
 *
 * La première version exigeait `$x = …->find(…)`. Or `return $this->em->…->find($data->getRef());`
 * est une façon parfaitement naturelle d'écrire la même chose, et elle échappait entièrement à la
 * règle : sans variable, rien à lier, donc rien à signaler. Le banc d'essai l'a montré dès le premier
 * lancement du croisement `read: false`.
 *
 * Une résolution non affectée est d'ailleurs le cas le plus net : il n'existe *aucune* variable à
 * laquelle un contrôle pourrait se rattacher, donc le contrôle n'existe pas.
 */
const MOTIF_INSTRUCTION_RESOLUTION = '/[^;{}]*->(?:find|findOneBy|getReference)\s*\([^;]*?\)\s*;/s';

/** L'instruction commence-t-elle par une affectation ? */
const MOTIF_AFFECTATION = '/^\s*\$(\w+)\s*=/';

/** Les arguments passés à la résolution, dans une instruction. */
/**
 * Les arguments de la résolution.
 *
 * ⚠ **N'élargissez pas ce motif sans faire tourner `bin/essai-garde-fous.sh`.** J'ai tenté trois fois
 * de le détendre pour rattraper la forme ternaire de l'IDOR n°7
 * (`$x = cond ? …->find($id) : null;`, où `: null` s'intercale avant le `;`). Chaque tentative a
 * empiré : la dernière, avec `(.*)` glouton et une fin d'instruction permissive, faisait passer les
 * signalements de 17 à 32 et cassait 3 cas du banc. Le `(.*)` glouton traverse les instructions
 * suivantes dès qu'on ne borne plus la fin.
 *
 * La forme ternaire reste donc un angle mort **connu et assumé**. La rattraper demande d'analyser la
 * structure du code, pas d'étirer une expression régulière — et une règle qui signale 32 endroits
 * dont la moitié à tort ne serait pas un progrès.
 */
const MOTIF_ARGUMENTS = '/->(?:find|findOneBy|getReference)\s*\((.*)\)\s*;/s';

/**
 * L'argument vient-il de l'entrée client ?
 *
 * **`$data->` n'en fait volontairement pas partie.** Dans un Processor API Platform, `$data` est la
 * *ressource chargée* par l'opération, pas le corps de la requête : quand celle-ci est déclarée
 * `read: true`, l'entité est passée par le provider Doctrine et les extensions `Perimetre*` s'y sont
 * appliquées — elle est donc déjà cloisonnée. Le compter comme entrée client produisait **dix faux
 * positifs sur dix** au gel du 22/08 (Patinoire, Stock, Padel, Compta, Personnel, Autorisation),
 * vérifiés un par un sur deux modules indépendants.
 *
 * ⚠ **Angle mort assumé, et il faut le connaître.** Si une opération est déclarée `read: false`,
 * `$data` provient bien du corps. Ce garde-fou ne le voit pas : la déclaration vit dans l'entité, pas
 * dans le Processor, et une règle par fichier ne peut pas la lire. En pratique ces Processors lisent
 * aussi le corps par `LecteurCorps` — c'est le cas de `MouvementCaisseProcessor`, qui reste détecté.
 * Un Processor `read: false` s'appuyant *uniquement* sur `$data->` échapperait au contrôle.
 * Une règle qui croiserait la déclaration de l'opération le fermerait ; elle reste à écrire.
 */
const MOTIF_ARG_CLIENT = '/\$corps|\$uriVariables\s*\[|\$payload|\$request->(?:query|request|attributes)->get\(|->corps\(\)/';

/**
 * Le périmètre est-il confronté à CETTE variable ? Les formes acceptées sont celles réellement
 * employées dans le dépôt après les quatre correctifs d'IDOR.
 */
function controleLieA(string $variable, string $source): bool
{
    $v = preg_quote($variable, '/');

    $formes = [
        '/\$' . $v . '->getEtablissement\(\)/',                    // comparaison directe
        '/codesEffectifs\([^)]*\$' . $v . '/',                     // autorité recalculée sur elle
        '/(?:verifier|autorise|assert)\w*\([^)]*\$' . $v . '/',    // passée à un vérificateur
        '/\w*(?:Verificateur|Guard)\w*->\w+\([^)]*\$' . $v . '/',
        '/@cloisonnement-verifie\s*:\s*\S/',                       // exemption déclarée, greppable
    ];

    foreach ($formes as $forme) {
        if (preg_match($forme, $source) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * Processors qui servent au moins une opération déclarée `read: false`.
 *
 * Chez eux — et seulement chez eux — `$data` provient du corps de la requête et non du provider
 * Doctrine : il redevient une entrée client. La déclaration vit dans l'entité, pas dans le Processor,
 * d'où ce croisement entre fichiers. Sans lui, le garde-fou a un angle mort exactement là où le
 * cloisonnement automatique ne s'applique pas.
 *
 * Aujourd'hui ce croisement ne révèle aucune résolution supplémentaire : il est posé pendant qu'il
 * coûte zéro dette, plutôt qu'après l'incident qui l'aurait rendu évident.
 *
 * @return array<string, true> indexé par nom court de classe
 */
function processorsSansLecture(string $racine): array
{
    $sansLecture = [];
    $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));

    /** @var SplFileInfo $fichier */
    foreach ($iterateur as $fichier) {
        if (!$fichier->isFile() || $fichier->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($fichier->getPathname());
        if (!str_contains($source, 'ApiResource')) {
            continue;
        }

        // Chaque `new Get(`, `new Post(`… jusqu'à sa parenthèse fermante appariée : une opération se
        // lit comme un bloc, et un `read: false` d'une opération ne dit rien de la suivante.
        if (preg_match_all('/new\s+(?:Get|GetCollection|Post|Put|Patch|Delete)\s*\(/', $source, $debuts, PREG_OFFSET_CAPTURE) === false) {
            continue;
        }

        foreach ($debuts[0] as $debut) {
            $i = (int) $debut[1] + strlen($debut[0]) - 1;
            $profondeur = 0;
            $j = $i;
            $longueur = strlen($source);

            while ($j < $longueur) {
                if ($source[$j] === '(') {
                    ++$profondeur;
                } elseif ($source[$j] === ')') {
                    --$profondeur;
                    if ($profondeur === 0) {
                        break;
                    }
                }
                ++$j;
            }

            $bloc = substr($source, $i, $j - $i);

            if (preg_match('/read:\s*false/', $bloc) === 1
                && preg_match('/processor:\s*(\w+)::class/', $bloc, $nom) === 1) {
                $sansLecture[$nom[1]] = true;
            }
        }
    }

    return $sansLecture;
}

/**
 * @return list<string> identités « fichier:ligne:$variable », triées — une résolution, pas un fichier :
 *                     un même fichier peut en porter plusieurs, et n'en corriger qu'une doit se voir.
 */
/**
 * Variables affectées depuis une entrée client, dans ce fichier.
 *
 * On lit chaque instruction `$x = …` et on retient `x` si la partie droite contient une entrée
 * client. C'est un unique saut : `$a = $uriVariables['id']; $b = $a;` ne teinte pas `$b`. Assumé —
 * au-delà, il faudrait une vraie analyse de flot, et le rapport coût/prise ne le justifie pas ici.
 *
 * @return list<string> noms de variables, sans le `$`
 */
function variablesIssuesDuClient(string $source, string $motifClient): array
{
    if (preg_match_all('/\$(\w+)\s*=\s*([^;]+);/s', $source, $affectations, PREG_SET_ORDER) === false) {
        return [];
    }

    $teintees = [];

    foreach ($affectations as $affectation) {
        if (preg_match($motifClient, $affectation[2]) === 1) {
            $teintees[$affectation[1]] = true;
        }
    }

    return array_keys($teintees);
}

function resolutionsNonLiees(string $racine): array
{
    $resultat = [];
    $sansLecture = processorsSansLecture($racine);

    foreach (fichiersHttp($racine) as $relatif) {
        $source = (string) file_get_contents($racine . '/' . $relatif);

        // `$data->` ne compte comme entrée client que chez un Processor dont l'opération ne lit pas
        // la ressource — ailleurs, l'entité est déjà passée par les extensions de périmètre.
        $classe = basename($relatif, '.php');
        $motifClient = isset($sansLecture[$classe])
            ? '/' . substr(MOTIF_ARG_CLIENT, 1, -1) . '|\$data->/'
            : MOTIF_ARG_CLIENT;

        // Un saut d'affectation. L'IDOR n°7 (23/08) est passé parce que l'identifiant client
        // transitait par une variable :
        //
        //     $ticketId = $uriVariables['ticketId'] ?? null;      // l'entrée client est ICI
        //     … ->find((string) $ticketId)                        // et plus visible LÀ
        //
        // On repère donc les variables alimentées par une entrée client et on les traite comme telles.
        // **Un seul saut, délibérément** : une analyse de flot complète serait hors de proportion, et
        // cette forme-là — lire l'identifiant, le valider, puis résoudre — est de loin la plus courante.
        $variablesTeintees = variablesIssuesDuClient($source, $motifClient);
        if ($variablesTeintees !== []) {
            $motifClient = '/' . substr($motifClient, 1, -1)
                . '|\$(?:' . implode('|', array_map('preg_quote', $variablesTeintees)) . ')\b/';
        }

        if (preg_match_all(MOTIF_INSTRUCTION_RESOLUTION, $source, $correspondances, PREG_OFFSET_CAPTURE) === false) {
            continue;
        }

        foreach ($correspondances[0] as $capture) {
            [$instruction, $decalage] = $capture;

            if (preg_match(MOTIF_ARGUMENTS, $instruction, $args) !== 1
                || preg_match($motifClient, $args[1]) !== 1) {
                continue;
            }

            $ligne = substr_count(substr($source, 0, (int) $decalage), "\n") + 1;

            if (preg_match(MOTIF_AFFECTATION, $instruction, $nom) === 1) {
                if (controleLieA($nom[1], $source)) {
                    continue;
                }
                $resultat[] = sprintf('%s:%d:$%s', $relatif, $ligne, $nom[1]);
                continue;
            }

            // Aucune affectation : il n'y a pas de variable à laquelle rattacher un contrôle. Seule
            // l'annotation explicite peut lever le signalement — et elle laisse une trace greppable.
            if (preg_match('/@cloisonnement-verifie\s*:\s*\S/', $source) === 1) {
                continue;
            }

            $resultat[] = sprintf('%s:%d:(résolution non affectée)', $relatif, $ligne);
        }
    }

    sort($resultat);

    return $resultat;
}

const AIDE_RESOLUTION = <<<'TXT'
    Ici le fichier contient peut-être déjà un contrôle de périmètre — mais il ne porte pas sur
    l'entité que tu viens de résoudre depuis l'entrée client. C'est exactement ce qui a produit
    l'IDOR d'appairage du 22/08 : `etablissementActif()` était appelé six lignes plus haut, pour
    autre chose, et `$droit` n'était comparé à rien.

        $droit = $this->em->getRepository(DroitAcces::class)->find($corps['droit']);
        // ⚠ à partir d'ici, $droit peut appartenir à n'importe quel établissement

        $codes = $this->calculateur->codesEffectifs($utilisateur, $droit->getEtablissement()?->getId());
        if (!$this->calculateur->autorise($codes, '<module>', '<action>')) {
            throw new NotFoundHttpException('… introuvable.');
        }

    Le contrôle doit nommer la variable résolue. Si ton contrôle passe par une forme que ce garde-fou
    ne sait pas lire, pose l'annotation `@cloisonnement-verifie : <raison>` — elle est greppable et
    attribuable, contrairement à un assouplissement de la détection.
    TXT;

// ---------------------------------------------------------------- ligne de base

/** @return array{scelle: array{plafond: int, gelee_le: string}, entrees: array<string, array{depuis: string, accorde_par: string, raison: string}>} */
function lireLigneDeBase(string $chemin): array
{
    if (!is_file($chemin)) {
        fwrite(STDERR, sprintf("Ligne de base absente : %s\n", $chemin));
        exit(2);
    }

    $donnees = json_decode((string) file_get_contents($chemin), true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($donnees) || !isset($donnees['scelle']['plafond'], $donnees['entrees'])) {
        fwrite(STDERR, "Ligne de base illisible : clés « scelle.plafond » et « entrees » attendues.\n");
        exit(2);
    }

    return $donnees;
}

function ecrireLigneDeBase(string $chemin, array $donnees): void
{
    file_put_contents(
        $chemin,
        json_encode($donnees, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );
}

// ------------------------------------------------------------------------ sorties

function bloc(string $titre, array $lignes, string $prefixe = '  - '): void
{
    echo "\n" . $titre . "\n";
    foreach ($lignes as $ligne) {
        echo $prefixe . $ligne . "\n";
    }
}

// -------------------------------------------------------------------------- main

$options = array_slice($argv, 1);
$racine = RACINE_SRC;

$actuelles = violations($racine);
$base = lireLigneDeBase(LIGNE_DE_BASE);
$entrees = $base['entrees'];
$plafond = (int) $base['scelle']['plafond'];

// Règle n°2 (C19). Ligne de base distincte : c'est une autre règle, avec sa propre dette et son
// propre cliquet. Les mélanger permettrait de « payer » une régression de l'une avec une correction
// de l'autre.
$resolutions = resolutionsNonLiees($racine);
$entreesResolution = $base['entrees_resolution'] ?? [];
$plafondResolution = (int) ($base['scelle_resolution']['plafond'] ?? count($entreesResolution));

if (in_array('--liste', $options, true)) {
    echo sprintf("Règle n°1 — fichiers sans contrôle de périmètre : %d\n", count($actuelles));
    foreach ($actuelles as $v) {
        $etat = isset($entrees[$v]) ? 'ligne de base' : 'NOUVELLE';
        echo sprintf("  [%-13s] %s\n", $etat, $v);
    }
    echo sprintf("\nRègle n°2 (C19) — contrôle non lié à l'entité résolue : %d\n", count($resolutions));
    foreach ($resolutions as $r) {
        $etat = isset($entreesResolution[$r]) ? 'ligne de base' : 'NOUVELLE';
        echo sprintf("  [%-13s] %s\n", $etat, $r);
    }
    exit(0);
}

if (in_array('--nettoyer', $options, true)) {
    $corrigees = array_values(array_diff(array_keys($entrees), $actuelles));
    $corrigeesResolution = array_values(array_diff(array_keys($entreesResolution), $resolutions));

    if ($corrigees === [] && $corrigeesResolution === []) {
        echo "Rien à nettoyer : toutes les entrées des deux lignes de base sont encore en violation.\n";
        exit(0);
    }

    foreach ($corrigees as $c) {
        unset($entrees[$c]);
    }
    foreach ($corrigeesResolution as $c) {
        unset($entreesResolution[$c]);
    }

    $base['entrees'] = $entrees;
    $base['scelle']['plafond'] = count($entrees);   // le plafond ne remonte jamais
    if ($entreesResolution !== [] || isset($base['entrees_resolution'])) {
        $base['entrees_resolution'] = $entreesResolution;
        $base['scelle_resolution']['plafond'] = count($entreesResolution);
    }
    ecrireLigneDeBase(LIGNE_DE_BASE, $base);

    if ($corrigees !== []) {
        bloc(sprintf("Règle n°1 — %d entrée(s) retirée(s) :", count($corrigees)), $corrigees);
        echo sprintf("  Plafond abaissé à %d.\n", count($entrees));
    }
    if ($corrigeesResolution !== []) {
        bloc(sprintf("Règle n°2 (C19) — %d résolution(s) retirée(s) :", count($corrigeesResolution)), $corrigeesResolution);
        echo sprintf("  Plafond abaissé à %d.\n", count($entreesResolution));
    }
    echo sprintf("\nCommite %s.\n", LIGNE_DE_BASE);
    exit(0);
}

// --- contrôle proprement dit ---

/**
 * Plafond tel qu'il est sur une révision de référence (la branche cible, en CI). Retourne null si le
 * fichier n'y existe pas encore — c'est le cas légitime du commit qui introduit le garde-fou.
 */
$plafondReference = null;
foreach ($options as $option) {
    if (!str_starts_with($option, '--contre=')) {
        continue;
    }

    $ref = substr($option, strlen('--contre='));

    // La révision doit d'abord être résoluble. Sans cette étape, une référence inconnue, un `git`
    // absent ou un dépôt inaccessible produiraient le même résultat qu'une première introduction :
    // le cliquet serait ignoré et le contrôle passerait au vert. Un garde-fou qu'on a demandé et qui
    // ne s'applique pas doit échouer bruyamment — c'est tout l'intérêt de l'avoir demandé.
    exec(sprintf('git rev-parse --verify %s 2>/dev/null', escapeshellarg($ref)), $sortie, $code);

    if ($code !== 0) {
        fwrite(STDERR, sprintf(
            "\n=== ERREUR — référence « %s » non résoluble ===\n"
            . "  Le cliquet a été demandé mais ne peut pas s'appliquer : la ligne de base pourrait\n"
            . "  grossir sans que rien ne l'arrête. Refus de continuer plutôt que de rendre un vert\n"
            . "  qui ne veut rien dire.\n\n"
            . "  Causes habituelles : `git` absent de l'environnement, révision inconnue (un\n"
            . "  `git fetch` manque ?), ou dépôt hors de portée — typiquement un worktree monté dans\n"
            . "  un conteneur sans son répertoire `.git`.\n\n",
            $ref
        ));
        exit(2);
    }

    $brut = shell_exec(sprintf('git show %s:%s 2>/dev/null', escapeshellarg($ref), escapeshellarg(LIGNE_DE_BASE)));

    if (!is_string($brut) || trim($brut) === '') {
        // Cas légitime, et le seul : la révision existe, mais le fichier n'y est pas encore.
        echo sprintf("Référence %s résolue, ligne de base absente : première introduction — cliquet sans objet.\n", $ref);
        break;
    }

    $donneesRef = json_decode($brut, true);
    if (!is_array($donneesRef) || !isset($donneesRef['scelle']['plafond'])) {
        fwrite(STDERR, sprintf("\n=== ERREUR — ligne de base illisible sur %s ===\n\n", $ref));
        exit(2);
    }

    $plafondReference = (int) $donneesRef['scelle']['plafond'];
    break;
}

$nouvelles = array_values(array_diff($actuelles, array_keys($entrees)));
$corrigees = array_values(array_diff(array_keys($entrees), $actuelles));
$echec = false;

// Condition n°2 de l'intégrateur : la ligne de base ne peut que rétrécir.
if (count($entrees) > $plafond) {
    $echec = true;
    echo "\n=== ÉCHEC — la ligne de base a grossi ===\n";
    echo sprintf(
        "  Elle contient %d entrées pour un plafond scellé à %d (gelé le %s).\n",
        count($entrees),
        $plafond,
        $base['scelle']['gelee_le']
    );
    echo "\n  La ligne de base est une dette gelée, pas une soupape. On n'y ajoute rien :\n";
    echo "  un nouveau cas se corrige, il ne se déroge pas. Si tu as un motif impérieux,\n";
    echo "  il se discute dans MESSAGES.md avant de toucher au plafond, pas après.\n";
}

// Le cliquet opposable : le plafond n'a pas le droit de remonter par rapport à la branche cible.
if ($plafondReference !== null && $plafond > $plafondReference) {
    $echec = true;
    echo "\n=== ÉCHEC — le plafond a été relevé ===\n";
    echo sprintf("  Plafond de la branche de référence : %d. Plafond proposé : %d.\n", $plafondReference, $plafond);
    echo "\n  Relever le plafond, c'est convertir une correction à faire en dérogation permanente.\n";
    echo "  La ligne de base ne remonte pas : corrige le cas, ou viens l'exposer dans MESSAGES.md.\n";
}

if ($nouvelles !== []) {
    $echec = true;
    echo "\n=== ÉCHEC — résolution par identifiant client sans contrôle de périmètre ===\n";
    bloc(sprintf("%d fichier(s) concerné(s) :", count($nouvelles)), $nouvelles);
    echo "\n" . AIDE_CORRECTION . "\n";
}

// ---- règle n°2 (C19) : le contrôle porte-t-il sur l'entité résolue ? ----

$nouvellesResolutions = array_values(array_diff($resolutions, array_keys($entreesResolution)));
$resolutionsCorrigees = array_values(array_diff(array_keys($entreesResolution), $resolutions));

if (count($entreesResolution) > $plafondResolution) {
    $echec = true;
    echo "\n=== ÉCHEC — la ligne de base « résolution liée » a grossi ===\n";
    echo sprintf("  %d entrées pour un plafond de %d.\n", count($entreesResolution), $plafondResolution);
}

if ($nouvellesResolutions !== []) {
    $echec = true;
    echo "\n=== ÉCHEC — le contrôle de périmètre ne porte pas sur l'entité résolue ===\n";
    bloc(sprintf("%d résolution(s) concernée(s) :", count($nouvellesResolutions)), $nouvellesResolutions);
    echo "\n" . AIDE_RESOLUTION . "\n";
}

if ($resolutionsCorrigees !== []) {
    bloc(
        sprintf("Bonne nouvelle : %d résolution(s) de la ligne de base sont désormais liées.", count($resolutionsCorrigees)),
        $resolutionsCorrigees
    );
    echo "\n  Retire-les : php bin/garde-fou-cloisonnement.php --nettoyer\n";
}

if ($corrigees !== []) {
    bloc(
        sprintf("Bonne nouvelle : %d entrée(s) de la ligne de base ne sont plus en violation.", count($corrigees)),
        $corrigees
    );
    echo "\n  Retire-les : php bin/garde-fou-cloisonnement.php --nettoyer\n";
}

if ($echec) {
    echo "\n";
    exit(1);
}

echo sprintf(
    "Cloisonnement : OK — aucune nouvelle résolution non contrôlée. Dette gelée : %d entrée(s), plafond %d.\n",
    count($entrees),
    $plafond
);
echo sprintf(
    "Résolution liée (C19) : OK — le contrôle porte sur l'entité résolue. Dette gelée : %d, plafond %d.\n",
    count($entreesResolution),
    $plafondResolution
);
exit(0);
