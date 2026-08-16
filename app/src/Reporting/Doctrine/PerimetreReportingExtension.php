<?php

declare(strict_types=1);

namespace App\Reporting\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Reporting\Entity\RapportPlanifie;
use App\Reporting\Entity\Trait\RattachementNiveauInterface;
use App\Reporting\Enum\NiveauEntite;
use App\Reporting\Security\PerimetreReportingResolver;
use App\Securite\Entity\Utilisateur;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * Cloisonnement par niveau (§2.2/§4 plan-reporting.md, RG-M7-01/CA-1) : filtre `Mesure`,
 * `ObjectifIndicateur`, `TableauDeBord`, `Export` (via `RattachementNiveauInterface`) au périmètre
 * effectif de l'utilisateur ; filtre `RapportPlanifie` à son créateur, sauf `reporting.configurer`
 * (administrateur, surensemble borné par M8).
 */
final class PerimetreReportingExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    private const UUID_IMPOSSIBLE = '00000000-0000-0000-0000-000000000000';

    public function __construct(
        private readonly Security $security,
        private readonly PerimetreReportingResolver $resolver,
    ) {
    }

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
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];

        if ($resourceClass === RapportPlanifie::class) {
            if ($this->security->isGranted('PERM', 'reporting.configurer')) {
                return; // surensemble borné par M8 (§2.2 plan-reporting.md).
            }
            $queryBuilder
                ->andWhere(sprintf('%s.createur = :perimetre_utilisateur', $rootAlias))
                ->setParameter('perimetre_utilisateur', $utilisateur->getId(), 'uuid');

            return;
        }

        if (!is_subclass_of($resourceClass, RattachementNiveauInterface::class)) {
            return;
        }

        $perimetre = $this->resolver->perimetreEffectif($utilisateur, 'lire');

        $etabs = $this->idsOuImpossible($perimetre->etablissements);
        $regions = $this->idsOuImpossible($perimetre->regions);
        $groupes = $this->idsOuImpossible($perimetre->groupes);

        $queryBuilder
            ->andWhere($queryBuilder->expr()->orX(
                $queryBuilder->expr()->andX(
                    sprintf('%s.niveau = :perimetre_niveau_etab', $rootAlias),
                    $queryBuilder->expr()->in(sprintf('%s.etablissement', $rootAlias), ':perimetre_etabs'),
                ),
                $queryBuilder->expr()->andX(
                    sprintf('%s.niveau = :perimetre_niveau_region', $rootAlias),
                    $queryBuilder->expr()->in(sprintf('%s.region', $rootAlias), ':perimetre_regions'),
                ),
                $queryBuilder->expr()->andX(
                    sprintf('%s.niveau = :perimetre_niveau_groupe', $rootAlias),
                    $queryBuilder->expr()->in(sprintf('%s.groupe', $rootAlias), ':perimetre_groupes'),
                ),
            ))
            ->setParameter('perimetre_niveau_etab', NiveauEntite::Etablissement)
            ->setParameter('perimetre_niveau_region', NiveauEntite::Region)
            ->setParameter('perimetre_niveau_groupe', NiveauEntite::Groupe)
            ->setParameter('perimetre_etabs', $etabs, ArrayParameterType::BINARY)
            ->setParameter('perimetre_regions', $regions, ArrayParameterType::BINARY)
            ->setParameter('perimetre_groupes', $groupes, ArrayParameterType::BINARY);
    }

    /**
     * IN(...) sur une colonne UUID (BINARY(16)) : chaque élément doit être passé au format binaire
     * (`toBinary()`, même représentation que la colonne), le tableau lui-même lié en
     * `ArrayParameterType::BINARY` — passer des objets `Uuid`/le type scalaire `'uuid'` sur un
     * paramètre TABLEAU lève "Could not convert PHP value of type array to type uuid" (Doctrine
     * attend un DBAL array type, pas une conversion élément par élément).
     *
     * @param list<Uuid> $ids
     *
     * @return list<string>
     */
    private function idsOuImpossible(array $ids): array
    {
        if ($ids === []) {
            return [Uuid::fromString(self::UUID_IMPOSSIBLE)->toBinary()];
        }

        return array_map(static fn (Uuid $id): string => $id->toBinary(), $ids);
    }
}
