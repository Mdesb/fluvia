<?php

declare(strict_types=1);

namespace App\Calendar\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Calendar\Entity\IcsSubscription;
use App\Calendar\Entity\CalendarEvent;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement de l'agenda : l'établissement actif, ET la propriété.
 *
 * ── DEUX BORNES, PAS UNE ────────────────────────────────────────────────────────────────────────
 *
 * L'établissement seul ne suffit pas. Un agenda porte des événements PERSONNELS — un rendez-vous
 * médical noté « indisponible », une formation qu'on n'a pas encore annoncée. Les rendre lisibles à
 * tous les collègues du site parce qu'ils partagent un établissement serait une fuite, et une fuite
 * dont personne ne se plaindrait avant qu'il ne soit trop tard.
 *
 * La clause est donc : `etablissement = actif ET (proprietaire EST NULL OU proprietaire = moi)`.
 *
 * ⚠ Aucune permission ne lève cette borne, pas même l'administration. Un administrateur a besoin de
 * voir l'agenda DU SITE, jamais celui d'une personne ; lui ouvrir les deux « au cas où » ferait de
 * l'agenda personnel un espace où plus personne n'écrit rien de vrai.
 */
final readonly class CalendarScopeExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(
        private ContexteEtablissement $contexte,
        private Security $security,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->restreindre($queryBuilder, $resourceClass);
    }

    /**
     * @param array<string, mixed> $identifiers
     * @param array<string, mixed> $context
     */
    public function applyToItem(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        array $identifiers,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->restreindre($queryBuilder, $resourceClass);
    }

    private function restreindre(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        if (!\in_array($resourceClass, [CalendarEvent::class, IcsSubscription::class], true)) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $actif = $this->contexte->idActif();
        $utilisateur = $this->security->getUser();

        // Fermeture par défaut : sans contexte ou sans compte, liste VIDE et non liste complète.
        if ($actif === null || !$utilisateur instanceof Utilisateur) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        // Le type `'uuid'` du troisième argument n'est pas décoratif (D58) : sans lui, la requête
        // rend zéro ligne sans lever — un agenda qui paraît vide alors qu'il est plein.
        $queryBuilder
            ->andWhere(sprintf('IDENTITY(%s.establishment) = :calendar_active', $alias))
            ->setParameter('calendar_active', $actif, 'uuid');

        if ($resourceClass === IcsSubscription::class) {
            $queryBuilder
                ->andWhere(sprintf('IDENTITY(%s.user) = :calendar_owner', $alias))
                ->setParameter('calendar_owner', $utilisateur->getId(), 'uuid');

            return;
        }

        // Les parenthèses sont écrites à la main POUR LE LECTEUR, pas comme garde-fou : Doctrine
        // les ajoute de toute façon. `Expr\Composite::processQueryPart` enveloppe toute partie
        // contenant ' OR ' ou ' AND ' (correctif DDC-1237). Vérifié en les retirant — le DQL produit
        // reste `... = :calendar_active AND (owner IS NULL OR IDENTITY(owner) = :calendar_owner)`.
        //
        // ⚠ CORRECTION D'UN AVERTISSEMENT QUE J'AVAIS ÉCRIT ICI. Il annonçait que leur chute ferait
        // tomber le cloisonnement en silence. C'était faux, et un commentaire faux coûte plus cher
        // qu'un commentaire absent : il oriente la relecture vers un danger inexistant, donc il en
        // détourne. Ce qui porte le cloisonnement, c'est la borne d'ÉTABLISSEMENT juste au-dessus —
        // vérifié en la retirant, `testMonPropreEvenementChezLeVoisinResteInvisible` rougit.
        $queryBuilder
            ->andWhere(sprintf('(%s.owner IS NULL OR IDENTITY(%s.owner) = :calendar_owner)', $alias, $alias))
            ->setParameter('calendar_owner', $utilisateur->getId(), 'uuid')
            ->distinct();
    }
}
