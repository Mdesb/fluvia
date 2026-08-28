<?php

declare(strict_types=1);

namespace App\Finance\SupplierInvoice\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Finance\SupplierInvoice\Entity\ReconciliationSettings;
use App\Finance\SupplierInvoice\Entity\SupplierInvoice;
use App\Finance\SupplierInvoice\Entity\SupplierInvoiceLine;
use App\Finance\SupplierInvoice\Entity\SupplierPayment;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités de `App\Finance\SupplierInvoice` (D3/D8, §0.2 point 1 du plan) —
 * **copie stricte du patron** `App\Stock\Doctrine\PerimetreStockExtension` : jointure jusqu'à
 * `.establishment` + `Affectation` de l'utilisateur courant. Protège aussi les endpoints d'action
 * déclarés `read: true` (`/approve`, `/dispute`, `/resolve-dispute`, `/cancel`, `/credit-note`,
 * `/reconciliation`) puisqu'ils passent par le provider d'item standard.
 *
 * `ReconciliationSettings` (correctif revue de cohérence, défaut 2) ne porte pas d'`establishment`
 * direct/atteignable par une jointure simple — seulement un `businessProfile` — d'où le second patron
 * `RESOURCES_VIA_PROFIL`, **copie stricte** de `App\Facturation\Doctrine\PerimetreFacturationExtension`
 * (rattachement via `ProfilExploitant::etablissementPrincipal` OU l'un de ses `etablissementsRattaches`).
 * Avant ce correctif, `GetCollection`/`Get` de `ReconciliationSettings` n'étaient protégés que par
 * `finance.read` (permission), sans filtre de périmètre : fuite cross-tenant en lecture (D8).
 */
final class PerimetreFinanceExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, list<string>> Relations à joindre depuis la racine jusqu'à « establishment ». */
    private const CHAINES = [
        SupplierInvoice::class => [],
        SupplierInvoiceLine::class => ['supplierInvoice'],
        SupplierPayment::class => ['supplierInvoice'],
    ];

    /** @var list<class-string> Ressources cloisonnées via `{root}.businessProfile` (principal ou rattachés). */
    private const RESOURCES_VIA_PROFIL = [ReconciliationSettings::class];

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

        $viaProfil = \in_array($resourceClass, self::RESOURCES_VIA_PROFIL, true);
        if (!isset(self::CHAINES[$resourceClass]) && !$viaProfil) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];

        $actif = $this->contexte->idActif();
        if ($actif === null) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        if ($viaProfil) {
            // Pas de champ `establishment` atteignable : rattachement via `businessProfile`, principal
            // OU rattaché (même patron que `PerimetreFacturationExtension`).
            $queryBuilder
                ->innerJoin($alias . '.businessProfile', 'finance_profil_perimetre')
                ->leftJoin('finance_profil_perimetre.etablissementsRattaches', 'finance_etab_rattache_perimetre');

            $condition = '(IDENTITY(finance_profil_perimetre.etablissementPrincipal) = :finance_perimetre_actif'
                . ' OR finance_etab_rattache_perimetre.id = :finance_perimetre_actif)';
        } else {
            foreach (self::CHAINES[$resourceClass] as $i => $relation) {
                $nouvelAlias = 'finance_perimetre_' . $i;
                $queryBuilder->innerJoin($alias . '.' . $relation, $nouvelAlias);
                $alias = $nouvelAlias;
            }

            $condition = sprintf(
                'IDENTITY(%s.establishment) = :finance_perimetre_actif',
                $alias,
            );
        }

        $queryBuilder
            ->andWhere($condition)
            ->setParameter('finance_perimetre_actif', $actif, 'uuid')
            ->distinct();
    }
}
