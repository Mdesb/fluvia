<?php

declare(strict_types=1);

namespace App\Patinoire\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Patinoire\Entity\Affutage;
use App\Patinoire\Entity\CautionLocationPatins;
use App\Patinoire\Entity\GrilleRetenue;
use App\Patinoire\Entity\ListeAttentePointure;
use App\Patinoire\Entity\LocationPatins;
use App\Patinoire\Entity\ParcPatins;
use App\Patinoire\Entity\RetenueCaution;
use App\Patinoire\Entity\SaisonEphemere;
use App\Patinoire\Entity\ZonePatinoire;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités des ressources `App\Patinoire` (RG-SOCLE-05), même pattern que
 * `App\Piscine\Doctrine\PerimetrePiscineExtension` (code réel lu) : un utilisateur ne voit que les
 * objets rattachés à un établissement où il possède une affectation.
 */
final class PerimetrePatinoireExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, list<string>> Relations à joindre depuis la racine jusqu'à « etablissement ». */
    private const CHAINES = [
        ParcPatins::class => [],
        LocationPatins::class => [],
        CautionLocationPatins::class => ['location'],
        GrilleRetenue::class => [],
        RetenueCaution::class => ['location'],
        ListeAttentePointure::class => [],
        Affutage::class => [],
        ZonePatinoire::class => [],
        SaisonEphemere::class => [],
    ];

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
        if (!isset(self::CHAINES[$resourceClass])) {
            return;
        }
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        foreach (self::CHAINES[$resourceClass] as $i => $relation) {
            $nouvelAlias = 'patinoire_perimetre_' . $i;
            $queryBuilder->innerJoin($alias . '.' . $relation, $nouvelAlias);
            $alias = $nouvelAlias;
        }

        $queryBuilder
            ->innerJoin(
                Affectation::class,
                'aff_perimetre_patinoire',
                Join::WITH,
                sprintf(
                    'IDENTITY(aff_perimetre_patinoire.etablissement) = IDENTITY(%s.etablissement) AND IDENTITY(aff_perimetre_patinoire.utilisateur) = :perimetre_patinoire_utilisateur',
                    $alias,
                ),
            )
            ->setParameter('perimetre_patinoire_utilisateur', $utilisateur->getId(), 'uuid')
            ->distinct();
    }
}
