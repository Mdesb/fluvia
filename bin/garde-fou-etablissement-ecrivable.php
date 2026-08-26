<?php

declare(strict_types=1);

/**
 * Garde-fou n°12 — une entité ne doit pas laisser écrire son PROPRE établissement (D41).
 *
 * ── LE DÉFAUT ───────────────────────────────────────────────────────────────────────────────────
 *
 * L'entité est cloisonnée — elle porte un `Etablissement` — mais le champ qui la rattache est
 * modifiable depuis le corps de la requête. L'appelant choisit donc **à quel établissement elle
 * appartient**. Trouvé par `claude-H` sur `PointDeVente`.
 *
 * L'outillage y était structurellement aveugle : le garde-fou de cloisonnement inspecte les
 * **résolutions** (`find()` depuis l'entrée client), jamais les **groupes de sérialisation**. Et le
 * n°8 regarde l'inverse de ce cas — un propriétaire non cloisonné écrivant vers du cloisonné.
 *
 * ── DEUX VOIES D'EXPOSITION, ET C'EST CE QUE LA MESURE A APPRIS ─────────────────────────────────
 *
 *   A. **groupe d'écriture** — le champ porte un groupe de dénormalisation.
 *   B. **dénormalisation par défaut** — l'entité n'a **aucun** `denormalizationContext`, donc API
 *      Platform rend écrivable toute propriété dotée d'un mutateur.
 *
 * La voie B est **majoritaire** et la moins visible : rien dans le fichier ne signale que le champ
 * est exposé, c'est l'**absence** de déclaration qui l'expose. D'où deux messages : dire « retire-le
 * du groupe d'écriture » à quelqu'un qui n'a pas de groupe ne l'aide pas.
 *
 * ── POURQUOI UN CLIQUET SÉPARÉ DU N°8 ───────────────────────────────────────────────────────────
 *
 * J'ai d'abord ajouté cette règle au n°8. Son cliquet a refusé la poussée — « 5 sur la référence, 52
 * proposé » — et il avait raison : un cliquet ne monte pas. Mais la cause n'était pas une dette qui
 * grossit, c'était une **règle nouvelle qui mesure ce qui n'était pas compté**. Un cliquet ne sait pas
 * distinguer les deux, et il ne le doit pas.
 *
 * D'où une ligne de base propre. C'est la même leçon que le filet de complétude ce soir : un mécanisme
 * correct qui rend impossible son propre enrichissement doit être scindé, pas assoupli.
 *
 * ── UN ANGLE MORT, MESURÉ ET INSCRIT ────────────────────────────────────────────────────────────
 *
 * Le motif ne reconnaît `denormalizationContext: ['groups' => …]` qu'écrit en **apostrophes
 * simples**. Tout le dépôt écrit ainsi, mais une entité en guillemets doubles échapperait au
 * contrôle — et, pire, tomberait dans la branche « aucun contexte », donc serait jugée sur son
 * seul mutateur.
 *
 * Constaté en écrivant un cas d'essai avec des guillemets doubles : le garde-fou s'est tu.
 * L'essai était irréaliste au regard du style du dépôt, mais le silence mérite d'être écrit
 * plutôt que découvert.
 *
 * ── CE QUE CE GARDE-FOU N'AFFIRME PAS ───────────────────────────────────────────────────────────
 *
 * Il lit des attributs, pas l'exécution. Depuis D41, un **décorateur global** refuse d'écrire hors du
 * périmètre à l'exécution, et il est vérifié dans les deux sens. Ce cliquet ne le double pas : il
 * empêche la **cinquantième et unième** entité d'apparaître, pour que la dette cesse de croître
 * pendant qu'on la résorbe.
 *
 * Usage :
 *   php bin/garde-fou-etablissement-ecrivable.php
 *   php bin/garde-fou-etablissement-ecrivable.php --nettoyer
 *   php bin/garde-fou-etablissement-ecrivable.php --contre=origin/main
 */

const RACINE_SRC = 'app/src';
const LIGNE_DE_BASE = 'bin/etablissement-ecrivable.ligne-de-base.json';

const MOTIF_ECRITURE = '/new (Post|Patch|Put)\(/';
const MOTIF_DENORMALISATION = '/denormalizationContext:\s*\[\s*\'groups\'\s*=>\s*\[([^\]]*)\]/';
/**
 * ⚠ `[^;]*` après le nom : la forme la plus courante est `private ?Etablissement $etablissement =
 * null;`. Un motif exigeant le point-virgule immédiatement après le nom échouait sur onze entités,
 * dont `SessionCaisse`, `MandatSepa` et `Facture`.
 */
const MOTIF_REL_ETAB = '/ManyToOne\(targetEntity:\s*Etablissement::class.*?private\s+[^;]*\$\w+[^;]*;/s';
const MOTIF_SETTER = '/function setEtablissement\s*\(/';

/**
 * @return array<string, array{voie: string, fichier: string}>
 */
function entitesExposees(string $racine): array
{
    $exposees = [];
    $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));

    /** @var SplFileInfo $fichier */
    foreach ($iterateur as $fichier) {
        if (!$fichier->isFile() || $fichier->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($fichier->getPathname());

        if (!str_contains($source, '#[ORM\\Entity') || !str_contains($source, '#[ApiResource')) {
            continue;
        }
        if (!str_contains($source, 'ManyToOne(targetEntity: Etablissement::class')) {
            continue;
        }
        if (preg_match(MOTIF_ECRITURE, $source) !== 1) {
            continue;
        }

        // Le bloc peut être illisible sans que ce soit rédhibitoire : la voie B n'en a pas besoin.
        $bloc = preg_match(MOTIF_REL_ETAB, $source, $rel) === 1 ? $rel[0] : '';
        $voie = null;

        if ($bloc !== '' && preg_match(MOTIF_DENORMALISATION, $source, $contexte) === 1) {
            preg_match_all('/\'([^\']+)\'/', $contexte[1], $trouves);
            foreach ($trouves[1] as $groupe) {
                if (str_contains($bloc, "'" . $groupe . "'")) {
                    $voie = 'groupe';
                    break;
                }
            }
        } elseif (
            // ⚠ Exiger l'ABSENCE de contexte, pas seulement l'échec de lecture du bloc. Une entité qui
            // déclare un contexte et n'y met pas le champ ne l'expose PAS ; la classer exposée est une
            // accusation fausse, et gelée dans la ligne de base elle le resterait pour toujours.
            preg_match(MOTIF_DENORMALISATION, $source) !== 1
            && preg_match(MOTIF_SETTER, $source) === 1
        ) {
            $voie = 'defaut';
        }

        if ($voie === null) {
            continue;
        }

        $nom = substr($fichier->getFilename(), 0, -4);
        $exposees[$nom] = [
            'voie' => $voie,
            'fichier' => str_replace('\\', '/', substr($fichier->getPathname(), strlen($racine) + 1)),
        ];
    }

    ksort($exposees);

    return $exposees;
}

$options = array_slice($argv, 1);
$exposees = entitesExposees(RACINE_SRC);

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
    $base['entrees'] = $exposees;
    $base['scelle']['plafond'] = count($exposees);
    file_put_contents(
        LIGNE_DE_BASE,
        json_encode($base, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );
    echo sprintf("Ligne de base réécrite : %d entité(s) exposée(s), plafond %d.\n", count($exposees), count($exposees));
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
        $reference = json_decode(implode("\n", $sortie), true);
        if (is_array($reference) && isset($reference['scelle']['plafond'])) {
            $plafondReference = (int) $reference['scelle']['plafond'];
        }
    }
}

$echec = false;
$nouveaux = array_values(array_diff(array_keys($exposees), array_keys($base['entrees'])));

if ($nouveaux !== []) {
    $echec = true;
    echo "\n=== ÉCHEC — l'entité laisse écrire son PROPRE établissement (D41) ===\n\n";
    foreach ($nouveaux as $nom) {
        echo sprintf("  %-34s %-28s %s\n", $nom, $exposees[$nom]['voie'] === 'groupe' ? "groupe d'écriture" : 'dénormalisation par défaut', $exposees[$nom]['fichier']);
    }
    echo "\n  L'appelant choisit à quel établissement l'entité appartient.\n";
    echo "\n  Le correctif dépend de la voie, et elles n'ont pas le même remède :\n\n";
    echo "  · « groupe d'écriture » — retire le champ du groupe de dénormalisation. La relation se\n";
    echo "    pose côté serveur, jamais depuis le corps de la requête.\n\n";
    echo "  · « dénormalisation par défaut » — l'entité n'a AUCUN denormalizationContext, donc API\n";
    echo "    Platform rend écrivable toute propriété dotée d'un mutateur. Il n'y a pas de groupe à\n";
    echo "    retirer : déclare un contexte qui EXCLUT l'établissement, ou supprime `setEtablissement()`\n";
    echo "    si rien de légitime ne l'appelle.\n";
    echo "\n  Cette seconde voie est la majoritaire, et la moins visible : rien dans le fichier ne\n";
    echo "  signale que le champ est exposé — c'est l'absence de déclaration qui l'expose.\n";
}

if (count($exposees) > $plafond) {
    $echec = true;
    echo sprintf("\n=== ÉCHEC — la ligne de base a grossi (%d pour un plafond de %d) ===\n", count($exposees), $plafond);
}

if ($plafondReference !== null && $plafond > $plafondReference) {
    $echec = true;
    echo sprintf("\n=== ÉCHEC — plafond relevé : %d sur la référence, %d proposé ===\n", $plafondReference, $plafond);
    echo "\n"
        . "  Un cliquet ne monte pas — c'est exactement ce qui lui donne sa valeur.\n"
        . "\n"
        . "  ⚠ La cause la plus fréquente n'est pas une faute : ta branche est simplement EN\n"
        . "  RETARD sur la référence, et un plafond a baissé entre-temps. Commence par ça :\n"
        . "\n"
        . "      git fetch origin && git merge --no-edit origin/main\n";
}

if ($echec) {
    exit(1);
}

$parVoie = ['groupe' => 0, 'defaut' => 0];
foreach ($exposees as $e) {
    ++$parVoie[$e['voie']];
}

$resorbes = count($base['entrees']) - count($exposees);
echo sprintf(
    "Établissement écrivable : OK — aucune nouvelle entité exposée. Gelées : %d, plafond %d. (%d par groupe, %d par défaut.)%s\n",
    count($exposees),
    $plafond,
    $parVoie['groupe'],
    $parVoie['defaut'],
    $resorbes > 0 ? sprintf(' %d résorbée(s) — pense à --nettoyer.', $resorbes) : ''
);

exit(0);
