<?php

declare(strict_types=1);

/**
 * UNE COLLECTION ÉCRIVABLE QUI N'ÉCRIT RIEN — garde-fou n°47.
 *
 * ── LE DÉFAUT QU'IL ATTRAPE ─────────────────────────────────────────────────────────────────────
 *
 * `PropertyAccessor` écrit une collection par un couple `add<Singulier>` / `remove<Singulier>`
 * construit sur le singulier **anglais** du nom de propriété. Un nom français composé n'y retombe
 * presque jamais :
 *
 *     l'entité offre                      Symfony cherche
 *     addCategorie                        addCategory          (`ies` → `y`)
 *     addEtablissementRattache            addEtablissementsRattache
 *     addGuideAffecte                     addGuidesAffecte
 *     addServiceInclus                    addServicesInclu
 *
 * Quand le couple manque, **API Platform saute le champ en silence** : la requête répond 200, et
 * rien n'est écrit. Aucune erreur, aucun journal, aucun test rouge.
 *
 * ⚠ IL FAUT LA PAIRE. Un adder correctement nommé mais sans remover ne suffit pas — c'est le cas de
 * deux des trois défauts trouvés le 04/09.
 *
 * ── POURQUOI CE CONTRÔLE EXISTE ALORS QU'UN BALAYAGE AVAIT CONCLU « ZÉRO » ───────────────────────
 *
 * Le balayage du 03/09 (§8.7) cherchait `private Collection $x(ies|aux|eux);` : une règle de langue
 * DEVINÉE, à la place de celle qui décide vraiment. Il a manqué trois cas, tous en `-es` ou `-us`,
 * dont un champ de **périmètre d'accès** (`ProfilExploitant::$etablissementsRattaches`).
 *
 * D'où la forme de ce contrôle : il n'imite pas la règle, il **interroge l'inflecteur lui-même**.
 *
 * ── LE REMÈDE, QUAND IL SIGNALE ─────────────────────────────────────────────────────────────────
 *
 * Un `set<Propriété>()` explicite. Il prend le pas sur la recherche d'adder et ne dépend d'aucune
 * règle de langue. ⚠ Il doit remplacer le CONTENU sans changer d'instance de collection — Doctrine
 * suit les ajouts et retraits de celle-ci.
 *
 * ── ⚠ CE CONTRÔLE NE TOURNE PAS PARTOUT, ET IL LE DIT ───────────────────────────────────────────
 *
 * Il a besoin de l'autoloader et des attributs de sérialisation, donc de `vendor/`. L'arbre du
 * `pre-receive` n'en a pas — comme pour « Appels du frontal dans le vide ». Il s'annonce alors
 * NON EXÉCUTÉ plutôt que de rendre un vert qui n'a rien mesuré.
 */

const RACINE = __DIR__ . '/../app';

$autoload = RACINE . '/vendor/autoload.php';
if (!is_file($autoload)) {
    echo "Collections muettes : non exécuté — pas de dépendances installées dans cet arbre.\n";
    echo "  La lecture des attributs de sérialisation et la singularisation exigent `vendor/`,\n";
    echo "  et un arbre sans dépendances déclarerait toutes les collections saines.\n";
    echo "  Le contrôle tourne dans `./bin/garde-fous.sh` et en pre-commit.\n";
    // Le lanceur compte les abstentions grace a ce marqueur (voir `bin/garde-fous.sh`). Hors
    // du lanceur -- appel direct, hooks -- la variable est absente et rien n'est imprime.
    if ($marqueur = getenv('GARDE_FOU_MARQUEUR_ABSTENTION')) {
        echo $marqueur, PHP_EOL;
    }
    exit(0);
}

require $autoload;

use Doctrine\Common\Collections\Collection;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\String\Inflector\EnglishInflector;

$inflecteur = new EnglishInflector();

/**
 * Les groupes d'écriture portés par une propriété, s'il y en a.
 *
 * @return list<string>
 */
function groupesDEcriture(ReflectionProperty $propriete): array
{
    $groupes = [];
    foreach ($propriete->getAttributes(Groups::class) as $attribut) {
        foreach ((array) ($attribut->getArguments()[0] ?? []) as $groupe) {
            $groupes[] = (string) $groupe;
        }
    }

    return array_values(array_filter($groupes, static fn (string $g): bool => str_ends_with($g, ':write')));
}

/**
 * Par quel chemin `PropertyAccessor` écrirait cette collection : `setter`, `adder`, ou `aucun`.
 *
 * L'ordre est celui de Symfony : un `set<Propriété>()` explicite gagne ; sinon il faut la PAIRE
 * `add`/`remove` bâtie sur un singulier anglais de la propriété ; sinon le champ est sauté.
 */
function cheminDEcriture(ReflectionClass $classe, string $nom, EnglishInflector $inflecteur): string
{
    if ($classe->hasMethod('set' . ucfirst($nom))) {
        return 'setter';
    }

    foreach ($inflecteur->singularize($nom) as $singulier) {
        $suffixe = ucfirst($singulier);
        if ($classe->hasMethod('add' . $suffixe) && $classe->hasMethod('remove' . $suffixe)) {
            return 'adder';
        }
    }

    return 'aucun';
}

// ── LES TÉMOINS ─────────────────────────────────────────────────────────────────────────────────
//
// ⚠ Un détecteur se prouve DANS LES DEUX SENS. Celui qui signale tout est aussi inutile que celui
// qui ne signale rien, et seul le cas qu'il doit ÉPARGNER démasque un contrôle trop large.
$temoins = 0;
$temoinsEpargne = 0;

foreach ([
    // nom de la classe témoin  =>  verdict attendu
    TemoinSansRien::class => 'aucun',
    TemoinAdderFrancais::class => 'aucun',
    TemoinAdderSansRemover::class => 'aucun',
    TemoinSetter::class => 'setter',
    TemoinPaireAnglaise::class => 'adder',
] as $classeTemoin => $attendu) {
    $reflexion = new ReflectionClass($classeTemoin);
    $obtenu = cheminDEcriture($reflexion, 'elements', $inflecteur);
    if ($obtenu !== $attendu) {
        fwrite(STDERR, sprintf(
            "✗ Témoin en échec : %s attendait « %s », a obtenu « %s ».\n"
            . "  Le détecteur ne lit pas ce qu'il prétend lire — ne rien conclure de son verdict.\n",
            (new ReflectionClass($classeTemoin))->getShortName(),
            $attendu,
            $obtenu,
        ));
        exit(2);
    }
    ++$temoins;
    if ($attendu !== 'aucun') {
        ++$temoinsEpargne;
    }
}

// ── LE BALAYAGE ─────────────────────────────────────────────────────────────────────────────────
$iterateur = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(RACINE . '/src', FilesystemIterator::SKIP_DOTS),
);

$classes = [];
foreach ($iterateur as $fichier) {
    if (!$fichier->isFile() || $fichier->getExtension() !== 'php') {
        continue;
    }
    $source = file_get_contents($fichier->getPathname());
    if (!preg_match('/^namespace\s+([^;]+);/m', $source, $ns)) {
        continue;
    }
    if (!preg_match('/^(?:final\s+)?(?:readonly\s+)?class\s+(\w+)/m', $source, $cl)) {
        continue;
    }
    $classes[] = trim($ns[1]) . '\\' . $cl[1];
}
sort($classes);

$muettes = [];
$lues = 0;

foreach ($classes as $nomClasse) {
    if (!class_exists($nomClasse)) {
        continue;
    }
    $classe = new ReflectionClass($nomClasse);

    foreach ($classe->getProperties() as $propriete) {
        $type = $propriete->getType();
        if (!$type instanceof ReflectionNamedType) {
            continue;
        }
        $nomType = $type->getName();
        if ($nomType !== Collection::class && !is_a($nomType, Collection::class, true)) {
            continue;
        }

        $ecriture = groupesDEcriture($propriete);
        if ($ecriture === []) {
            continue;
        }

        ++$lues;
        if (cheminDEcriture($classe, $propriete->getName(), $inflecteur) !== 'aucun') {
            continue;
        }

        $muettes[] = sprintf(
            '%s::$%s  (groupes : %s · singuliers essayés : %s)',
            $classe->getShortName(),
            $propriete->getName(),
            implode(', ', $ecriture),
            implode(', ', $inflecteur->singularize($propriete->getName())),
        );
    }
}

// ⚠ ZÉRO COLLECTION LUE N'EST PAS UN VERT. Une regex de classe qui ne matche plus, un `src`
// déplacé, un autoloader muet — et le contrôle déclarerait tout sain sans avoir rien vu.
if ($lues === 0) {
    fwrite(STDERR, "✗ Collections muettes : ZÉRO collection écrivable lue — le contrôle n'a rien mesuré.\n");
    fwrite(STDERR, "  Le dépôt en porte au moins une douzaine. Vérifier le balayage avant de conclure.\n");
    exit(2);
}

if ($muettes !== []) {
    fwrite(STDERR, "✗ Collections écrivables sans chemin d'écriture — la requête répondra 200 sans rien écrire :\n\n");
    foreach ($muettes as $ligne) {
        fwrite(STDERR, '    ' . $ligne . "\n");
    }
    fwrite(STDERR, "\n  Remède : un `set<Propriété>(iterable)` explicite sur l'entité. Il prend le pas sur la\n");
    fwrite(STDERR, "  recherche d'adder et ne dépend d'aucune règle de langue.\n");
    fwrite(STDERR, "  ⚠ Il doit modifier la collection EN PLACE : lui substituer une nouvelle instance\n");
    fwrite(STDERR, "  ferait perdre à Doctrine le fil des ajouts et des retraits.\n");
    exit(1);
}

printf(
    "Collections muettes : OK — %d collection(s) écrivable(s), toutes avec un chemin d'écriture.\n"
    . "  (%d témoins passés, dont %d qui prouvent ce que le détecteur épargne.)\n",
    $lues,
    $temoins,
    $temoinsEpargne,
);
exit(0);

// ── LES CLASSES TÉMOINS ─────────────────────────────────────────────────────────────────────────
//
// Elles ne sont pas dans `app/src` : le balayage ne les voit pas, et `cheminDEcriture()` est
// interrogée directement dessus. Elles reproduisent les quatre formes rencontrées en vrai.

/** Ni setter, ni adder : la forme la plus nue du défaut. */
final class TemoinSansRien
{
    /** @var Collection<int, object> */
    private Collection $elements;
}

/** Un adder au singulier FRANÇAIS, que l'inflecteur anglais ne trouve pas — le cas de 2026-09-04. */
final class TemoinAdderFrancais
{
    /** @var Collection<int, object> */
    private Collection $elements;

    public function addElementFrancais(object $o): void
    {
    }

    public function removeElementFrancais(object $o): void
    {
    }
}

/** ⚠ Un adder trouvable, mais SANS remover : `PropertyAccessor` exige la paire. */
final class TemoinAdderSansRemover
{
    /** @var Collection<int, object> */
    private Collection $elements;

    public function addElement(object $o): void
    {
    }
}

/** Le remède : un setter explicite. DOIT être épargné. */
final class TemoinSetter
{
    /** @var Collection<int, object> */
    private Collection $elements;

    public function setElements(iterable $elements): void
    {
    }
}

/** Une paire anglaise complète. DOIT être épargnée — sinon le contrôle crie sur tout le dépôt. */
final class TemoinPaireAnglaise
{
    /** @var Collection<int, object> */
    private Collection $elements;

    public function addElement(object $o): void
    {
    }

    public function removeElement(object $o): void
    {
    }
}
