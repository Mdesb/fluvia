<?php

declare(strict_types=1);

namespace App\Securite\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Securite\Entity\DelegationDroit;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement des délégations (§4 plan-backoffice.md) : visibles pour `securite.gerer`/`lire`
 * (gestion/consultation complète) OU si l'utilisateur courant est le bénéficiaire OU le délégant
 * (visibilité sur sa propre fiche/ses propres délégations émises), même patron que
 * `PerimetreEtablissementExtension`.
 */
final class PerimetreDelegationExtension implements QueryCollectionExtensionInterface
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        if ($resourceClass !== DelegationDroit::class) {
            return;
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        if ($this->security->isGranted('PERM', 'securite.gerer') || $this->security->isGranted('PERM', 'securite.lire')) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $queryBuilder
            ->andWhere(sprintf(
                'IDENTITY(%1$s.beneficiaire) = :perimetre_delegation_utilisateur OR IDENTITY(%1$s.delegant) = :perimetre_delegation_utilisateur',
                $rootAlias
            ))
            ->setParameter('perimetre_delegation_utilisateur', $utilisateur->getId(), 'uuid');
    }
}
