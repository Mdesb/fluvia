<?php

declare(strict_types=1);

namespace App\Kiosk\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Kiosk\Entity\Kiosk;
use App\Kiosk\Entity\KioskOrder;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement des ressources du module `App\Kiosk` (RG-SOCLE-05, patron `GroupScopeExtension`) : on ne
 * voit que les bornes de l'établissement ACTIF. `KioskOrder` n'est pas exposée ; elle figure ici pour
 * que le jour où elle le serait, elle ne fuie pas (garde-fou n°35).
 *
 * Les routes de l'APPAREIL (`^/api/kiosk/`) ne passent pas par ici : elles n'ont pas d'utilisateur, et
 * chaque requête y est bornée à SA borne par `KioskCheckout`.
 */
final class KioskScopeExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, list<array{property: string, alias: string}>> */
    private const JOINS = [
        KioskOrder::class => [['property' => 'kiosk', 'alias' => 'kio']],
    ];

    /** @var array<class-string, string> */
    private const ESTABLISHMENT_PATH = [
        Kiosk::class => '{root}.etablissement',
        KioskOrder::class => 'kio.etablissement',
    ];

    public function __construct(
        private readonly Security $security,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->restrict($queryBuilder, $resourceClass);
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
        $this->restrict($queryBuilder, $resourceClass);
    }

    private function restrict(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        if (!isset(self::ESTABLISHMENT_PATH[$resourceClass])) {
            return;
        }
        if (!$this->security->getUser() instanceof Utilisateur) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        foreach (self::JOINS[$resourceClass] ?? [] as $join) {
            $queryBuilder->innerJoin($rootAlias . '.' . $join['property'], $join['alias']);
        }
        $path = str_replace('{root}', $rootAlias, self::ESTABLISHMENT_PATH[$resourceClass]);

        $active = $this->contexte->idActif();
        if ($active === null) {
            // Fermeture par défaut : une liste vide se remarque, une liste inter-établissements non.
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $queryBuilder
            ->andWhere(sprintf('IDENTITY(%s) = :kiosk_scope_active', $path))
            ->setParameter('kiosk_scope_active', $active, 'uuid');
    }
}
