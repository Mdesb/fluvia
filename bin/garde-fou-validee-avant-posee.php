<?php

declare(strict_types=1);

/**
 * VALIDÉE AVANT D'ÊTRE POSÉE — garde-fou n°48.
 *
 * ⚠ IL A UN VOISIN, ET IL FAUT LIRE LES DEUX AVANT D'EN ÉCRIRE UN TROISIÈME.
 * `bin/garde-fou-validation-avant-processeur.php` (n°34, `c2`, 01/09) couvre la même règle par
 * l'autre bout : il signale une COLLISION — champ contraint ET posé par un processeur, écrivable
 * ou non — et gèle 32 cas. Ses mots : « il pose la question ; il ne tranche pas ».
 *
 * Celui-ci ne pose aucune question : il signale ce qui NE PEUT PAS ABOUTIR, et rend zéro.
 * Ce qu'il voit et que le n°34 ne voit pas : si l'une des 32 collisions gelées sort du groupe
 * d'écriture, le compte du n°34 reste à 32 et reste vert — alors que le cas est devenu un 422
 * permanent. C'est une TRANSITION, invisible à un cliquet qui compte.
 *
 * ⚠ Ce fichier existe parce qu'une fiche périmée disait « n°34 à écrire » trois jours après sa
 * livraison. Le doublon a été évité de justesse ; la duplication de la FICHE, elle, a bien eu lieu.
 *
 * ── LE DÉFAUT QU'IL ATTRAPE ─────────────────────────────────────────────────────────────────────
 *
 * La validation d'API Platform tourne **avant** le processeur. Une propriété qui porte une
 * contrainte de non-vacuité et que **seul un processeur** peut remplir fait donc échouer la requête
 * avant que ce processeur soit atteint : un 422 permanent, sur une opération qui ne peut jamais
 * aboutir.
 *
 * Deux sessions s'y sont cassées le même jour (§3 quinquies), dans les deux sens :
 *   · un `Assert\NotNull` sur un champ posé par un processeur rendait la création d'une région
 *     impossible — 422, sur un écran qui invite justement à en créer une ;
 *   · un processeur posant le slug d'une vitrine APRÈS la validation laissait passer un nom que
 *     l'`Assert` aurait dû refuser.
 *
 * Les correctifs sont justes, invisibles, et **verts nulle part**. D'où ce contrôle.
 *
 * ── LES QUATRE CONDITIONS, ET POURQUOI CHACUNE RESSERRE ─────────────────────────────────────────
 *
 *   1. la propriété porte `NotNull` ou `NotBlank` ;
 *   2. elle n'est dans AUCUN groupe `:write` — le client ne peut donc pas la fournir. Sans cette
 *      condition, toute propriété obligatoire qu'un processeur complète aussi serait signalée ;
 *   3. elle n'a pas de valeur par défaut non vide, qui satisfait déjà la contrainte ;
 *   4. l'opération DÉSÉRIALISE (`input` non `false`). Une opération `read: true, input: false`
 *      charge l'entité depuis la base : la propriété y est déjà posée, et il n'y a pas de piège.
 *
 * ⚠ LA CONDITION 4 EST CE QUI SÉPARE UNE MESURE UTILE D'UN BRUIT. Sans elle, `Produit::$type` sort
 * deux fois — via `/produits/{id}/dupliquer` et `/produits/{id}/convertir`, qui partent toutes deux
 * d'un produit existant.
 *
 * ── ⚠ ET LE CROISEMENT SE FAIT PAR L'OPÉRATION, JAMAIS PAR LE NOM ───────────────────────────────
 *
 * Une première version cherchait `->setX(` dans tout `State/` et `Service/` : 28 candidats, dont
 * `Plan::$label` « posé par GenerateurTotp » — un service qui pose le label d'autre chose. Compter
 * des homonymes, c'est compter les occurrences d'une cause au lieu de ses effets. Ici, seul le
 * processeur **déclaré par l'opération de cette ressource** est lu.
 *
 * ── ⚠ CE CONTRÔLE NE TOURNE PAS PARTOUT, ET IL LE DIT ───────────────────────────────────────────
 *
 * Il lit des attributs, donc il lui faut `vendor/`. L'arbre du `pre-receive` n'en a pas : il s'y
 * annonce NON EXÉCUTÉ plutôt que de rendre un vert qui n'a rien mesuré. Même repli que n°47.
 */

const RACINE = __DIR__ . '/../app';

$autoload = RACINE . '/vendor/autoload.php';
if (!is_file($autoload)) {
    echo "Validée avant d'être posée : non exécuté — pas de dépendances installées dans cet arbre.\n";
    echo "  La lecture des attributs de ressource et de validation exige `vendor/`, et un arbre sans\n";
    echo "  dépendances déclarerait toutes les opérations saines.\n";
    echo "  Le contrôle tourne dans `./bin/garde-fous.sh` et en pre-commit.\n";
    exit(0);
}

require $autoload;

use ApiPlatform\Metadata\ApiResource;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;

/** La contrainte de non-vacuité portée par la propriété, ou `null`. */
function contrainteDeNonVacuite(ReflectionProperty $propriete): ?string
{
    foreach ($propriete->getAttributes() as $attribut) {
        if (\in_array($attribut->getName(), [NotNull::class, NotBlank::class], true)) {
            return substr(strrchr($attribut->getName(), '\\') ?: '', 1);
        }
    }

    return null;
}

/** Le client peut-il fournir cette propriété ? */
function fournissableParLeClient(ReflectionProperty $propriete): bool
{
    foreach ($propriete->getAttributes(Groups::class) as $attribut) {
        foreach ((array) ($attribut->getArguments()[0] ?? []) as $groupe) {
            if (str_ends_with((string) $groupe, ':write')) {
                return true;
            }
        }
    }

    return false;
}

/**
 * La propriété est-elle piégée : contrainte de non-vacuité, non fournissable, sans défaut ?
 *
 * @param array<string, mixed> $defauts
 */
function estPiegee(ReflectionProperty $propriete, array $defauts): bool
{
    if (contrainteDeNonVacuite($propriete) === null) {
        return false;
    }
    if (fournissableParLeClient($propriete)) {
        return false;
    }

    $defaut = $defauts[$propriete->getName()] ?? null;

    return $defaut === null || $defaut === '' || $defaut === [];
}

// ── LES TÉMOINS ─────────────────────────────────────────────────────────────────────────────────
//
// ⚠ Ce contrôle rend ZÉRO aujourd'hui — c'est précisément quand un détecteur ne trouve rien qu'il
// doit prouver qu'il SAIT trouver. Et le cas qu'il doit ÉPARGNER est celui qui démasque un contrôle
// trop large : sans lui, un détecteur qui signale tout paraîtrait tout aussi vert sur les refus.
$temoins = 0;
$temoinsEpargne = 0;

foreach ([
    TemoinPiege::class => true,
    TemoinFournissable::class => false,
    TemoinAvecDefaut::class => false,
    TemoinSansContrainte::class => false,
] as $classeTemoin => $attendu) {
    $reflexion = new ReflectionClass($classeTemoin);
    $obtenu = estPiegee($reflexion->getProperty('champ'), $reflexion->getDefaultProperties());
    if ($obtenu !== $attendu) {
        fwrite(STDERR, sprintf(
            "✗ Témoin en échec : %s attendait %s, a obtenu %s.\n"
            . "  Le détecteur ne lit pas ce qu'il prétend lire — ne rien conclure de son verdict.\n",
            (new ReflectionClass($classeTemoin))->getShortName(),
            $attendu ? 'PIÉGÉE' : 'saine',
            $obtenu ? 'PIÉGÉE' : 'saine',
        ));
        exit(2);
    }
    ++$temoins;
    if (!$attendu) {
        ++$temoinsEpargne;
    }
}

// ── LE BALAYAGE ─────────────────────────────────────────────────────────────────────────────────
$fichiers = [];
$iterateur = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(RACINE . '/src', FilesystemIterator::SKIP_DOTS),
);
foreach ($iterateur as $fichier) {
    if ($fichier->isFile() && $fichier->getExtension() === 'php') {
        $fichiers[] = $fichier->getPathname();
    }
}

$classes = [];
$sourceDe = [];
foreach ($fichiers as $chemin) {
    $source = file_get_contents($chemin);
    if (!preg_match('/^namespace\s+([^;]+);/m', $source, $ns)) {
        continue;
    }
    if (!preg_match('/^(?:final\s+)?(?:readonly\s+)?class\s+(\w+)/m', $source, $cl)) {
        continue;
    }
    $fqcn = trim($ns[1]) . '\\' . $cl[1];
    $classes[] = $fqcn;
    $sourceDe[$fqcn] = $source;
}
sort($classes);

$ressources = 0;
$operations = 0;
$pieges = [];

foreach ($classes as $nomClasse) {
    if (!class_exists($nomClasse)) {
        continue;
    }
    $classe = new ReflectionClass($nomClasse);

    $attributsRessource = $classe->getAttributes(ApiResource::class);
    if ($attributsRessource === []) {
        continue;
    }
    ++$ressources;

    // Les processeurs des opérations qui DÉSÉRIALISENT. Une opération `input: false` charge son
    // entité depuis la base : la propriété y est déjà posée, il n'y a pas de piège.
    $processeurs = [];
    foreach ($attributsRessource as $attribut) {
        foreach ($attribut->getArguments() as $clef => $valeur) {
            if ($clef !== 'operations' || !\is_array($valeur)) {
                continue;
            }
            foreach ($valeur as $operation) {
                if (!\is_object($operation) || !method_exists($operation, 'getProcessor')) {
                    continue;
                }
                ++$operations;
                if (method_exists($operation, 'getInput') && $operation->getInput() === false) {
                    continue;
                }
                $processeur = $operation->getProcessor();
                if (\is_string($processeur) && $processeur !== '') {
                    $processeurs[$processeur] = $operation->getUriTemplate() ?? '(opération standard)';
                }
            }
        }
    }
    if ($processeurs === []) {
        continue;
    }

    $defauts = $classe->getDefaultProperties();

    foreach ($classe->getProperties() as $propriete) {
        if (!estPiegee($propriete, $defauts)) {
            continue;
        }

        $motif = '/->set' . ucfirst($propriete->getName()) . '\s*\(/';
        foreach ($processeurs as $processeur => $route) {
            $source = $sourceDe[$processeur] ?? null;
            if ($source === null || !preg_match($motif, $source)) {
                continue;
            }
            $pieges[] = sprintf(
                '%s::$%s (%s) — posé par %s sur %s',
                $classe->getShortName(),
                $propriete->getName(),
                contrainteDeNonVacuite($propriete),
                substr(strrchr($processeur, '\\') ?: $processeur, 1),
                $route,
            );
        }
    }
}

// ⚠ ZÉRO RESSOURCE LUE N'EST PAS UN VERT. Le dépôt en porte plus de trois cents ; une regex de
// classe qui ne matche plus déclarerait tout sain sans avoir rien vu.
if ($ressources === 0) {
    fwrite(STDERR, "✗ Validée avant d'être posée : ZÉRO ressource API lue — le contrôle n'a rien mesuré.\n");
    fwrite(STDERR, "  Le dépôt en porte plus de trois cents. Vérifier le balayage avant de conclure.\n");
    exit(2);
}

if ($pieges !== []) {
    fwrite(STDERR, "✗ Propriétés validées AVANT d'être posées — l'opération ne peut jamais aboutir :\n\n");
    foreach ($pieges as $ligne) {
        fwrite(STDERR, '    ' . $ligne . "\n");
    }
    fwrite(STDERR, "\n  La validation tourne avant le processeur : la contrainte voit la propriété vide,\n");
    fwrite(STDERR, "  rend un 422, et le processeur qui devait la remplir n'est jamais atteint.\n");
    fwrite(STDERR, "  Remèdes : poser la valeur par défaut sur la propriété, ou déplacer le geste du\n");
    fwrite(STDERR, "  processeur vers un `#[ORM\\PrePersist]`, ou ouvrir la propriété à l'écriture.\n");
    exit(1);
}

printf(
    "Validée avant d'être posée : OK — %d ressource(s), %d opération(s) inspectée(s), aucun piège.\n"
    . "  (%d témoins passés, dont %d qui prouvent ce que le détecteur épargne.)\n",
    $ressources,
    $operations,
    $temoins,
    $temoinsEpargne,
);
exit(0);

// ── LES CLASSES TÉMOINS ─────────────────────────────────────────────────────────────────────────
//
// Hors de `app/src` : le balayage ne les voit pas, `estPiegee()` est interrogée directement dessus.

/** Contrainte, hors groupe d'écriture, sans défaut : le piège nu. DOIT être signalée. */
final class TemoinPiege
{
    #[NotNull]
    #[Groups(['x:read'])]
    private ?string $champ = null;
}

/** ⚠ Le client PEUT la fournir : pas de piège. DOIT être épargnée. */
final class TemoinFournissable
{
    #[NotBlank]
    #[Groups(['x:read', 'x:write'])]
    private ?string $champ = null;
}

/** ⚠ Une valeur par défaut satisfait déjà la contrainte. DOIT être épargnée. */
final class TemoinAvecDefaut
{
    #[NotBlank]
    #[Groups(['x:read'])]
    private string $champ = 'defaut';
}

/** Aucune contrainte de non-vacuité. DOIT être épargnée. */
final class TemoinSansContrainte
{
    #[Groups(['x:read'])]
    private ?string $champ = null;
}
