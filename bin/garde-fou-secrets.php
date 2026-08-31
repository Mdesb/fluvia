#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Garde-fou n°4 — aucun secret cryptographique en valeur par défaut.
 *
 * Refuse qu'une clé, un jeton ou un mot de passe ait une valeur littérale de repli dans le code.
 *
 * **Pourquoi ce garde-fou n'a pas de ligne de base, et n'en aura jamais.** Le n°1 (cloisonnement) en a
 * une : sa dette est ancienne, étalée sur 46 fichiers, et la geler était le seul moyen d'arrêter
 * l'hémorragie sans bloquer tout le monde. Ici, non. Une clé de scellement en dur n'est pas une dette
 * qu'on étale : c'est un secret publié. Une liste de dérogation reviendrait à écrire « ces secrets-là,
 * on accepte qu'ils soient connus » — ce qui n'a aucun sens. Un cas trouvé se corrige, point.
 *
 * **Ce qu'il cherche, et pourquoi c'est cette forme-là.** Le motif dangereux n'est pas le secret écrit
 * en dur et assumé — celui-là se voit. C'est la **valeur par défaut** : le code marche sans
 * configuration, donc personne ne remarque qu'il manque une variable d'environnement, et le bouchon
 * part en production sans bruit. Trois chaînes de scellement NF525 de ce projet ont vécu ainsi.
 *
 * Le bon motif, déjà employé huit fois ici (`ChiffreurIban`, `ChiffreurSecret`, `HashChainSignataire`…) :
 *
 *     public function __construct(
 *         #[Autowire(env: 'NF525_SEAL_KEY')] private readonly string $cleScellement,
 *     ) {}
 *
 * Aucune valeur par défaut : si la variable manque, le conteneur refuse de démarrer. Échec fermé.
 *
 * Usage :
 *   php bin/garde-fou-secrets.php
 *   php bin/garde-fou-secrets.php --liste
 */

const RACINE_SRC = 'app/src';
const RACINE_DEPOT = __DIR__ . '/..';

/**
 * Jetons qui désignent un secret. On raisonne par **jeton** (découpage camelCase) et non par
 * sous-chaîne : « cle » en sous-chaîne signalerait article, cycle, oracle, nucleaire…
 */
const JETONS_SECRET = [
    'cle', 'clef', 'key', 'keys', 'secret', 'secrets', 'token', 'password', 'passphrase',
    'hmac', 'salt', 'signature', 'scellement', 'chiffrement', 'cipher', 'apikey',
];

/** Propriété promue ou paramètre typé `string` avec une valeur littérale par défaut. */
const MOTIF_DEFAUT = '/(?:private|protected|public)\s+(?:readonly\s+)?\??string\s+\$(\w+)\s*=\s*([\'"])([^\'"]*)\2/';

/** Repli `?? \'…\'` sur une variable au nom parlant. */
const MOTIF_REPLI = '/\$(\w+)\s*\?\?\s*([\'"])([^\'"]{6,})\2/';

/**
 * Exclusions. Les fixtures sont des données de test : un secret y est attendu, et il ne protège rien.
 * Le garde-fou lui-même contient la liste des mots recherchés, il s'exclurait sinon.
 */
const EXCLUS = ['/DataFixtures/', '/Tests/'];

/** @return list<string> */
function jetons(string $identifiant): array
{
    $espace = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $identifiant) ?? $identifiant;
    $espace = str_replace('_', ' ', $espace);

    $resultat = [];
    foreach (preg_split('/\s+/', $espace) ?: [] as $morceau) {
        if ($morceau !== '') {
            $resultat[] = mb_strtolower($morceau);
        }
    }

    return $resultat;
}

function designeUnSecret(string $nom): bool
{
    return array_intersect(jetons($nom), JETONS_SECRET) !== [];
}

/** @return list<array{fichier: string, ligne: int, nom: string, valeur: string, genre: string}> */
function analyser(string $racine): array
{
    if (!is_dir($racine)) {
        fwrite(STDERR, sprintf("Répertoire source introuvable : %s\nLance ce script depuis la racine du dépôt.\n", $racine));
        exit(2);
    }

    $trouvailles = [];
    $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));

    /** @var SplFileInfo $fichier */
    foreach ($iterateur as $fichier) {
        if (!$fichier->isFile() || $fichier->getExtension() !== 'php') {
            continue;
        }

        $relatif = str_replace('\\', '/', substr($fichier->getPathname(), strlen($racine) + 1));

        foreach (EXCLUS as $exclu) {
            if (str_contains('/' . $relatif, $exclu)) {
                continue 2;
            }
        }

        $source = (string) file_get_contents($fichier->getPathname());
        $vus = [];

        foreach ([MOTIF_DEFAUT => 'valeur par défaut', MOTIF_REPLI => 'repli ??'] as $motif => $genre) {
            if (preg_match_all($motif, $source, $correspondances, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
                continue;
            }

            foreach ($correspondances as $jeu) {
                [$nom, $decalage] = $jeu[1];
                $valeur = $jeu[3][0];

                if ($valeur === '' || !designeUnSecret($nom)) {
                    continue;
                }

                $ligne = substr_count(substr($source, 0, (int) $decalage), "\n") + 1;
                $signature = $relatif . ':' . $ligne;

                if (isset($vus[$signature])) {
                    continue;   // même endroit capté par les deux motifs
                }
                $vus[$signature] = true;

                $trouvailles[] = [
                    'fichier' => $relatif,
                    'ligne' => $ligne,
                    'nom' => $nom,
                    'valeur' => $valeur,
                    'genre' => $genre,
                ];
            }
        }
    }

    usort($trouvailles, static fn (array $a, array $b): int => [$a['fichier'], $a['ligne']] <=> [$b['fichier'], $b['ligne']]);

    return $trouvailles;
}

// ----------------------------------------------------------------------- main

$trouvailles = analyser(RACINE_SRC);

// ── SECONDE PASSE : LES `.env` VERSIONNÉS ───────────────────────────────────────────────────────
//
// ⚠ CE GARDE-FOU NE LISAIT QUE `app/src`, ET SON MESSAGE LAISSAIT CROIRE AUTRE CHOSE.
//
// « Aucune clé cryptographique en valeur par défaut » est vrai de ce qu'il contrôlait. Quiconque le
// lisait concluait « pas de clé dans le dépôt ». Huit secrets réels vivaient dans `app/.env`, qui
// est versionné — dont les trois clés de scellement NF525.
//
// Toute installation qui suivait la procédure documentée héritait donc des clés du dépôt.
// Silencieusement : le conteneur démarre très bien, la clé est là.
//
// LA RÈGLE : UN BOUCHON DIT QU'IL EST UN BOUCHON — en clair, ou une fois décodé en base64.
//
// Juger sur la longueur ou l'entropie laisserait passer un vrai secret court et refuserait un
// bouchon long. Exempter `.env.test` en bloc créerait un endroit où cacher un vrai secret sans que
// rien ne le dise. On exige donc que la valeur SE NOMME.
const FICHIERS_ENV = ['app/.env', 'app/.env.test'];
const MOTS_DE_BOUCHON = ['test', 'change', 'exemple', 'a_generer', 'todo', 'placeholder', 'factice', 'bidon'];

$secretsEnv = [];

foreach (FICHIERS_ENV as $relatif) {
    $chemin = RACINE_DEPOT . '/' . $relatif;
    if (!is_file($chemin)) {
        continue;
    }
    $lignes = preg_split('/\R/', (string) file_get_contents($chemin)) ?: [];

    foreach ($lignes as $i => $ligne) {
        if (preg_match('/^([A-Z][A-Z0-9_]*(?:KEY|SECRET|PASSPHRASE|TOKEN))=(.*)$/', $ligne, $m) !== 1) {
            continue;
        }
        $nom = $m[1];
        $valeur = trim($m[2], " \t\"'");

        // Une valeur vide est le bon état : l'installation la fournit.
        if ($valeur === '') {
            continue;
        }
        // Un chemin de fichier n'est pas un secret : `JWT_SECRET_KEY` POINTE vers une clé, il
        // n'en est pas une.
        //
        // ⚠ ON RECONNAIT UN CHEMIN A SON DEBUT, PAS A SES CARACTERES. Ma première version écartait
        // toute valeur CONTENANT un `/` — or l'alphabet base64 contient `/`, et une clé de 32
        // octets en base64 en porte un une fois sur deux. Le contrôle épargnait donc la moitié des
        // secrets qu'il devait nommer, tout en refusant correctement les autres : il paraissait
        // sain. Seul un témoin — un vrai secret glissé exprès — l'a démasqué.
        if (str_starts_with($valeur, '%') || str_starts_with($valeur, '/') || str_starts_with($valeur, './')) {
            continue;
        }

        // Le bouchon doit se nommer — en clair, ou une fois décodé.
        $candidats = [strtolower($valeur)];
        $decode = base64_decode($valeur, true);
        if ($decode !== false) {
            $candidats[] = strtolower($decode);
        }

        $seNomme = false;
        foreach ($candidats as $texte) {
            foreach (MOTS_DE_BOUCHON as $mot) {
                if (str_contains($texte, $mot)) {
                    $seNomme = true;
                    break 2;
                }
            }
        }

        if (!$seNomme) {
            $secretsEnv[] = ['fichier' => $relatif, 'ligne' => $i + 1, 'nom' => $nom];
        }
    }
}

if ($secretsEnv !== []) {
    fwrite(STDERR, "\n✗ Secrets : valeur réelle dans un fichier .env VERSIONNÉ\n\n");
    foreach ($secretsEnv as $s) {
        fwrite(STDERR, sprintf("  %s:%d  %s\n", $s['fichier'], $s['ligne'], $s['nom']));
    }
    fwrite(STDERR, <<<'TXT'

Ces fichiers partent avec le dépôt. Toute installation qui suit la procédure hérite de la valeur —
silencieusement, puisque le conteneur démarre : la clé est là. Un chiffrement au repos ne protège
alors de rien contre quiconque a accès au dépôt, et toutes les installations partagent le secret.

Deux sorties, et une seule est bonne :

  1. La variable est un VRAI secret — laisse-la VIDE ici, déclare-la dans
     `infra/env.preprod.example` et laisse le déploiement la générer au premier passage.

  2. C'est un bouchon de développement ou de test — alors QU'IL LE DISE. Sa valeur doit contenir
     l'un de ces mots, en clair ou une fois décodée en base64 :

         test · change · exemple · a_generer · todo · placeholder · factice · bidon

⚠ Un statut de « valeur de convenance » qui ne tient qu'à un commentaire ne tient à rien : le jour
où quelqu'un colle une vraie clé à cet endroit, plus rien ne distingue les deux.

Pas de liste de dérogation ici non plus. Rendre un bouchon reconnaissable coûte une seconde ;
l'inscrire sur une liste coûte la règle.

TXT);
    exit(1);
}


if (in_array('--liste', array_slice($argv, 1), true)) {
    echo sprintf("Secrets en valeur par défaut : %d\n", count($trouvailles));
    foreach ($trouvailles as $t) {
        echo sprintf("  %s:%d  $%s\n", $t['fichier'], $t['ligne'], $t['nom']);
    }
    exit(0);
}

if ($trouvailles === []) {
    echo "Secrets : OK — aucune clé cryptographique en valeur par défaut.\n";
    exit(0);
}

echo "\n=== ÉCHEC — secret cryptographique en valeur par défaut ===\n\n";

foreach ($trouvailles as $t) {
    echo sprintf(
        "  %s:%d\n    [%s]  $%s = '%s'\n",
        $t['fichier'],
        $t['ligne'],
        $t['genre'],
        $t['nom'],
        mb_strlen($t['valeur']) > 50 ? mb_substr($t['valeur'], 0, 50) . '…' : $t['valeur']
    );
}

echo <<<'TXT'

Une valeur par défaut sur un secret est plus dangereuse qu'un secret écrit en clair et assumé :
le code fonctionne sans configuration, donc rien ne signale qu'il manque une variable
d'environnement, et le bouchon part en production sans bruit.

Le motif attendu, déjà employé huit fois dans ce dépôt :

    public function __construct(
        #[Autowire(env: 'NF525_SEAL_KEY')] private readonly string $cleScellement,
    ) {}

Pas de valeur par défaut : si la variable manque, le conteneur refuse de démarrer. Échec fermé.

Déclare ensuite la variable dans `infra/env.preprod.example`, VIDE, avec un commentaire disant à
quoi elle sert — le déploiement la génère au premier passage.

⚠ PAS dans `app/.env`, QUI EST VERSIONNÉ. Ce paragraphe disait le contraire jusqu'au 31/08, et
citait NF525_SEAL_KEY et NF525_COMPTA_SEAL_KEY comme exemples à suivre : c'étaient deux des huit
secrets réels qui dormaient dans le dépôt. Ce garde-fou recommandait le défaut qu'il aurait dû
refuser, en affichant vert.

Ce garde-fou n'a pas de liste de dérogation, et n'en aura pas : une clé en dur n'est pas une dette
qu'on étale, c'est un secret publié. Il n'y a rien à geler — il y a à corriger.

TXT;

exit(1);
