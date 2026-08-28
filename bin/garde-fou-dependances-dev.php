#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Garde-fou n°20 — le code de production ne dépend d'aucun paquet de développement.
 *
 * ── LE DÉFAUT QU'IL AURAIT ÉVITÉ, TROUVÉ LE 28/08 SUR LA PRÉPROD ────────────────────────────────
 *
 * `symfony/http-client` était déclaré en `require-dev`. Huit classes de production l'utilisaient :
 * l'extraction de documents par Anthropic, les quatre adaptateurs Bluesky et Mastodon, l'annuaire
 * des entreprises, et le calendrier scolaire des heures d'ouverture.
 *
 * Sur un déploiement `composer install --no-dev`, aucune de ces intégrations ne pouvait
 * fonctionner. Pire : le service `http_client` étant construit par le conteneur, TOUT ce qui
 * l'instancie explosait — y compris le transport du mailer, d'où un 500 sur la réinitialisation de
 * mot de passe.
 *
 * ── POURQUOI RIEN NE LE DISAIT, ET POURQUOI RIEN NE LE DIRA JAMAIS SANS CE CONTRÔLE ─────────────
 *
 * Les tests tournent avec les dépendances de développement installées. **Le défaut est invisible
 * partout où on le cherche, et visible seulement là où personne ne regarde : sur la machine
 * déployée.** Aucune suite, aucun type, aucun analyseur ne peut le voir — la classe existe, dans
 * l'environnement où on l'interroge.
 *
 * Il ne se manifeste ni au build, ni au démarrage : le conteneur Symfony ne construit un service
 * que lorsqu'on le demande. La panne attend donc le premier utilisateur qui touche la
 * fonctionnalité, en production, un dimanche.
 *
 * ── PAS DE LIGNE DE BASE, POUR LA MÊME RAISON QUE LE N°4 ────────────────────────────────────────
 *
 * Geler ce défaut reviendrait à écrire « cette fonctionnalité-là, on accepte qu'elle ne marche pas
 * en production ». Ce n'est pas une dette qu'on étale : c'est un paquet à déplacer d'une section à
 * l'autre du `composer.json`, ce qui prend une minute et ne casse rien — un paquet de production en
 * plus n'a jamais retiré quoi que ce soit.
 *
 * ── CE QU'IL LIT ────────────────────────────────────────────────────────────────────────────────
 *
 * Le VERROU, pas le `composer.json` : c'est le verrou qui décide de ce que `--no-dev` installe, et
 * lui seul connaît les dépendances transitives. Un paquet listé en `require-dev` mais tiré aussi
 * par une dépendance de production se retrouve dans `packages` — et il est alors légitime.
 *
 * Usage :
 *   php bin/garde-fou-dependances-dev.php
 *   php bin/garde-fou-dependances-dev.php --liste
 */

const RACINE_SRC = 'app/src';
const VERROU = 'app/composer.lock';

/**
 * ⚠ LA SEULE EXCLUSION, ET CE QU'ELLE COÛTE.
 *
 * Les fixtures étendent `Doctrine\Bundle\FixturesBundle\Fixture`, un paquet de développement, et
 * elles vivent sous `app/src/` — 68 fichiers. Ce ne sont pourtant pas des défauts : elles ne sont
 * jamais exécutées en production, seulement par `doctrine:fixtures:load`.
 *
 * VÉRIFIÉ, PAS SUPPOSÉ. `config/services.yaml` enregistre `App\` depuis tout `src/` SANS exclusion,
 * donc la question se posait : le conteneur de production tente-t-il de les enregistrer, et
 * échoue-t-il faute de classe parente ? Non — le conteneur compilé de la préprod ne contient zéro
 * référence aux fixtures. Symfony ignore en silence les classes qu'il ne peut pas réfléchir.
 *
 * CE QUE CETTE EXCLUSION COÛTE : un vrai défaut écrit DANS un fichier de fixtures ne serait pas vu.
 * C'est accepté — une fixture est du code de développement par construction. Une exclusion plus
 * large, elle, ne le serait pas : un contrôle qui crie au loup finit désactivé, et son absence coûte
 * alors plus cher que son absence n'aurait coûté au départ.
 */
const REPERTOIRES_HORS_PRODUCTION = ['/DataFixtures/'];

$liste = \in_array('--liste', $argv, true);

if (!is_file(VERROU)) {
    fwrite(STDERR, "Dépendances de dev : IGNORÉ — " . VERROU . " introuvable.\n");
    exit(0);
}

$verrou = json_decode((string) file_get_contents(VERROU), true, 512, \JSON_THROW_ON_ERROR);

/**
 * Espaces de noms fournis par un paquet, d'après sa section `autoload`.
 *
 * @param array<string, mixed> $paquet
 *
 * @return list<string>
 */
function espacesDeNoms(array $paquet): array
{
    $espaces = [];
    foreach (['psr-4', 'psr-0'] as $norme) {
        foreach (array_keys($paquet['autoload'][$norme] ?? []) as $prefixe) {
            $prefixe = trim((string) $prefixe, '\\');
            if ($prefixe !== '') {
                $espaces[] = $prefixe . '\\';
            }
        }
    }

    return $espaces;
}

/** @var array<string, string> $dev espace de noms => paquet */
$dev = [];
foreach ($verrou['packages-dev'] ?? [] as $paquet) {
    foreach (espacesDeNoms($paquet) as $espace) {
        $dev[$espace] = (string) $paquet['name'];
    }
}

// Un espace de noms fourni AUSSI par un paquet de production est légitime : `--no-dev` l'installe.
foreach ($verrou['packages'] ?? [] as $paquet) {
    foreach (espacesDeNoms($paquet) as $espace) {
        unset($dev[$espace]);
    }
}

if ($dev === []) {
    echo "Dépendances de dev : OK — aucun paquet de développement ne fournit d'espace de noms.\n";
    exit(0);
}

// Les préfixes les plus longs d'abord : `Symfony\Component\HttpClient\` doit gagner sur `Symfony\`
// si jamais les deux existaient.
uksort($dev, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

if ($liste) {
    echo "Espaces de noms fournis UNIQUEMENT par des paquets de développement :\n";
    foreach ($dev as $espace => $paquet) {
        printf("  %-55s %s\n", $espace, $paquet);
    }
    echo "\n";
}

$fautes = [];
$fichiers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(RACINE_SRC, FilesystemIterator::SKIP_DOTS));

foreach ($fichiers as $fichier) {
    if (!$fichier->isFile() || $fichier->getExtension() !== 'php') {
        continue;
    }

    $chemin = (string) $fichier->getPathname();
    foreach (REPERTOIRES_HORS_PRODUCTION as $horsProduction) {
        if (str_contains($chemin, $horsProduction)) {
            continue 2;
        }
    }

    $lignes = file((string) $fichier->getPathname(), \FILE_IGNORE_NEW_LINES);
    if ($lignes === false) {
        continue;
    }

    foreach ($lignes as $numero => $ligne) {
        // `use Foo\Bar;`, `use Foo\Bar as Baz;`, `use function Foo\bar;`. On s'arrête au premier
        // `class`/`interface`/`trait`/`enum` : au-delà, `use` désigne un trait ou une fermeture.
        if (preg_match('/^\s*(?:abstract\s+|final\s+|readonly\s+)*(?:class|interface|trait|enum)\s/', $ligne) === 1) {
            break;
        }
        if (preg_match('/^\s*use\s+(?:function\s+|const\s+)?([A-Za-z_\x80-\xff][\\\\A-Za-z0-9_\x80-\xff]*)/', $ligne, $trouve) !== 1) {
            continue;
        }

        $importe = $trouve[1];
        foreach ($dev as $espace => $paquet) {
            if (str_starts_with($importe, $espace)) {
                $fautes[] = [
                    'fichier' => str_replace('\\', '/', (string) $fichier->getPathname()),
                    'ligne' => $numero + 1,
                    'importe' => $importe,
                    'paquet' => $paquet,
                ];
                break;
            }
        }
    }
}

if ($fautes === []) {
    printf(
        "Dépendances de dev : OK — aucun code de production n'importe un paquet de développement. (%d espace(s) de noms surveillé(s).)\n",
        \count($dev),
    );
    exit(0);
}

usort($fautes, static fn (array $a, array $b): int => [$a['paquet'], $a['fichier']] <=> [$b['paquet'], $b['fichier']]);

fwrite(STDERR, "✗ Dépendances de dev : du code de production dépend d'un paquet absent en production.\n\n");
foreach ($fautes as $faute) {
    fwrite(STDERR, sprintf(
        "  %s:%d\n    %s\n    fourni par %s, déclaré en require-dev\n\n",
        $faute['fichier'],
        $faute['ligne'],
        $faute['importe'],
        $faute['paquet'],
    ));
}

$paquets = array_values(array_unique(array_column($fautes, 'paquet')));

fwrite(STDERR, <<<TXT

Sur un déploiement « composer install --no-dev », ces classes n'existent pas. Les tests, eux,
tournent avec les dépendances de développement : le défaut est invisible partout où on le cherche,
et visible seulement là où personne ne regarde.

Il n'échoue ni au build ni au démarrage — le conteneur Symfony ne construit un service que
lorsqu'on le demande. La panne attend le premier utilisateur qui touche la fonctionnalité.

Le remède, une minute :

    composer remove --dev {$paquets[0]} --no-update
    composer require {$paquets[0]} --no-update
    composer update {$paquets[0]} --no-install

Un paquet de production en plus n'a jamais retiré quoi que ce soit. Ce garde-fou n'a pas de ligne
de base et n'en aura pas : la geler reviendrait à écrire « cette fonctionnalité-là, on accepte
qu'elle ne marche pas en production ».


TXT);

exit(1);
