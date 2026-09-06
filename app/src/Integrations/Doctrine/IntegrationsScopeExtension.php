<?php

declare(strict_types=1);

namespace App\Integrations\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Integrations\Entity\OutboundEndpoint;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement des destinations sortantes — même patron que `SocialScopeExtension`.
 *
 * ⚠ **AUCUN ÉTABLISSEMENT ACTIF SIGNIFIE AUCUNE LIGNE, PAS TOUTES LES LIGNES.** Sans cette borne,
 * une requête sans en-tête `X-Etablissement` rendrait les destinations de toute la plateforme. Le
 * `1 = 0` est la position fermée, et c'est la seule acceptable quand la portée est inconnue.
 *
 * ⚠ Et l'absence est traitée en 404, jamais en 403 : distinguer « existe, pas à toi » de « n'existe
 * pas » donnerait de quoi énumérer les intégrations des autres établissements — savoir qu'un
 * concurrent a branché un canal est déjà une information.
 */
final class IntegrationsScopeExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    /** @param array<string, mixed> $context */
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
        if ($resourceClass !== OutboundEndpoint::class) {
            return;
        }
        if (!$this->security->getUser() instanceof Utilisateur) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $actif = $this->contexte->idActif();

        if ($actif === null) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $queryBuilder
            ->andWhere(sprintf('IDENTITY(%s.establishment) = :integrations_scope_actif', $alias))
            ->setParameter('integrations_scope_actif', $actif, 'uuid');
    }
}
