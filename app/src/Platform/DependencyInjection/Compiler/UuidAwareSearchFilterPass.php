<?php

declare(strict_types=1);

namespace App\Platform\DependencyInjection\Compiler;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use App\Platform\Filter\UuidAwareSearchFilter;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * REMPLACER CHAQUE `SearchFilter` PAR SA VERSION QUI SAIT COMPARER UN `Uuid`.
 *
 * `#[ApiFilter(SearchFilter::class, …)]` fait naître un service par entité, nommé
 * `annotated_<entite>_api_platform_doctrine_orm_filter_search_filter`. Il y en a 111 dans ce dépôt,
 * et 145 des propriétés qu'ils déclarent rendent une liste vide en silence — voir
 * {@see UuidAwareSearchFilter} pour la mesure et la cause.
 *
 * On ne peut pas hériter de `SearchFilter` : la classe est `final`. On décore donc.
 *
 * ── CE QUE LE CONTENEUR CONTIENT VRAIMENT, ET QUI N'EST PAS CE QU'ON CROIT ──────────────────────
 *
 * Ces 111 services ne portent PAS la classe `SearchFilter`. Ce sont des `ChildDefinition` de classe
 * **nulle**, filles de l'unique service abstrait `api_platform.doctrine.orm.search_filter`, et leur
 * seul argument est `$properties`. Une première version de cette passe comparait `getClass()` à
 * `SearchFilter::class` : elle n'a donc attrapé que le parent abstrait, l'a remplacé par le
 * décorateur, et les 111 filles ont hérité d'une classe à qui l'on passait un `$properties`
 * qu'elle ne connaît pas. Symfony refusait de construire le conteneur.
 *
 * ⚠ CE N'EST PAS LA PASSE QUI L'A DIT, C'EST UN VIDAGE. Trois hypothèses successives — opcache,
 * arguments nommés survivants, autoconfiguration — étaient toutes fausses, et chacune se racontait
 * bien. Ce qui a tranché, c'est une passe jetable qui a écrit sur disque la classe et le type réels
 * de chaque définition. Ne pas déduire la forme d'un conteneur : la lire.
 *
 * On remonte donc la filiation jusqu'à trouver une classe, et l'on décore les définitions dont la
 * classe EFFECTIVE est `SearchFilter` — le parent abstrait restant intact, puisque c'est de lui que
 * les filles tiennent tout le reste de leur câblage.
 *
 * ⚠ ON REPÈRE PAR LA CLASSE, PAS PAR LE NOM DU SERVICE. Le motif `annotated_…_search_filter` est une
 * convention d'API Platform, pas un contrat ; un renommage en amont ferait échouer la substitution
 * en silence — et un filtre non substitué se manifeste par une liste vide, c'est-à-dire par rien.
 */
final class UuidAwareSearchFilterPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // ⚠ LE DÉCORATEUR EST EXCLU DU GLOB `App\` DANS `config/services.yaml`, ET IL DOIT L'ÊTRE.
        // Il n'est pas un service à lui seul : ses deux arguments sont posés ci-dessous, un jeu par
        // filtre décoré. Enregistré par le glob, il est autoconfiguré comme filtre, ramassé par le
        // localisateur d'API Platform, et le conteneur refuse de se construire. Deux tentatives plus
        // douces ont échoué — `abstract: true`, puis `autowire: false` : l'autoconfiguration
        // continuait de l'étiqueter. Seule l'exclusion au chargement l'empêche d'exister.

        foreach ($container->getDefinitions() as $identifiant => $definition) {
            // Les définitions à point sont les gabarits `_instanceof` de Symfony ; le parent
            // abstrait, lui, doit survivre tel quel : les filles en héritent leur câblage.
            if (str_starts_with($identifiant, '.') || $definition->isAbstract()) {
                continue;
            }
            if ($this->classeEffective($container, $definition) !== SearchFilter::class) {
                continue;
            }

            $interne = $identifiant.'.sans_uuid';

            $definitionInterne = clone $definition;
            // Les étiquettes désignent le service que le conteneur expose comme filtre : elles
            // restent sur l'identifiant public. Sans ce nettoyage, API Platform verrait deux filtres
            // là où l'entité n'en déclare qu'un.
            $definitionInterne->clearTags();
            $definitionInterne->setPublic(false);
            $container->setDefinition($interne, $definitionInterne);

            // ⚠ UNE DÉFINITION NEUVE, PAS UNE MUTATION DE L'ANCIENNE. Celle d'origine est une fille
            // qui hérite classe et câblage de son parent ; lui changer la classe en place laisserait
            // la filiation derrière, et l'argument `$properties` avec.
            $decorateur = new Definition(
                UuidAwareSearchFilter::class,
                [new Reference($interne), new Reference('doctrine')],
            );
            $decorateur->setTags($definition->getTags());
            $decorateur->setPublic($definition->isPublic());
            $decorateur->setAutoconfigured(false);
            $decorateur->setAutowired(false);

            $container->setDefinition($identifiant, $decorateur);
        }
    }

    /**
     * La classe d'une `ChildDefinition` est nulle tant qu'on ne remonte pas à l'ancêtre qui la porte.
     * La borne de profondeur ne protège pas d'un cycle réel — Symfony le refuserait avant nous — mais
     * d'une boucle infinie pendant une reconstruction où la filiation est encore incomplète.
     */
    private function classeEffective(ContainerBuilder $container, Definition $definition): ?string
    {
        for ($profondeur = 0; $profondeur < 8; ++$profondeur) {
            $classe = $definition->getClass();
            if ($classe !== null) {
                return $classe;
            }
            if (!$definition instanceof ChildDefinition) {
                return null;
            }

            // ⚠ `has()` ET `findDefinition()`, JAMAIS `hasDefinition()`. API Platform crée ses
            // filles avec `new ChildDefinition($parent->getClass())` : le parent est désigné par un
            // NOM DE CLASSE, qui n'est ici qu'un alias vers `api_platform.doctrine.orm.search_filter`.
            // `hasDefinition()` ignore les alias — il rendait faux pour les 111 services, et seul
            // celui dont le parent est nommé directement était décoré. Une seule substitution sur
            // 112, et rien pour le signaler.
            $parent = $definition->getParent();
            if (!$container->has($parent)) {
                return null;
            }
            $definition = $container->findDefinition($parent);
        }

        return null;
    }
}
