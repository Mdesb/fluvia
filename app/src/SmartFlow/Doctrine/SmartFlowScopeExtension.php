<?php

declare(strict_types=1);

namespace App\SmartFlow\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\SmartFlow\Entity\RescheduleProposal;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement des ressources `App\SmartFlow` (D3/D8, plan-smart-flow.md §0.10, appelée
 * `PerimetreSmartFlowExtension` par le plan — renommée ici pour respecter D5, le garde-fou de nommage
 * anglais refusant « perimetre » dans un fichier neuf) — copie stricte du patron
 * `App\Dms\Doctrine\DmsScopeExtension`/`App\Finance\SupplierInvoice\Doctrine\PerimetreFinanceExtension`
 * (jointure `Affectation` sur `establishment` + utilisateur courant, `Security::getUser()`, jamais un id
 * transmis par le client). S'applique à `GetCollection`/`Get`, y compris les endpoints d'action déclarés
 * `read: true` (`/accept`, `/decline`) puisqu'ils passent par le provider d'item standard — échec fermé
 * (404, jamais 403, RG-SF-15/16).
 *
 * **Cas particulier « own » (`smart_flow.reschedule_read_own`, §0.10 du plan)** : en plus du filtre
 * établissement, quand l'utilisateur connecté ne porte **que** `smart_flow.reschedule_read_own` (pas
 * `reschedule_manage`), une restriction `customerId = :clientLie` est ajoutée — même lecture de
 * `Utilisateur::getClientLie()` que `App\Reservation\State\ReserverProcessor::process()` pour la garde
 * `reservation.reserver_soi`.
 *
 * ⚠ **Écart découvert, à faire trancher par claude-A (non corrigé ici, hors périmètre d'implémentation
 * de ce lot) :** `Utilisateur::getClientLie()` renvoie l'id d'un `App\Crm\Entity\Client`, alors que le
 * `customerId` porté par l'événement `booking.reschedule_requested` (et donc par
 * `RescheduleProposal::customerId`) est l'id du `App\Crm\Entity\Beneficiaire` organisateur de la
 * réservation d'origine (`BasculerNoShowCommand.php:98`/`AnnulerReservationProcessor.php:98`) — deux
 * entités distinctes, `Beneficiaire::$client` n'étant pas l'inverse direct de `Client::$id`. Tel
 * qu'implémenté ici, à la lettre de la spec/du plan §0.10, un client réel ne verrait donc **jamais**
 * ses propres propositions via `smart_flow.reschedule_read_own` en production (comparaison toujours
 * fausse). Documenté pour arbitrage plutôt que corrigé silencieusement.
 */
final class SmartFlowScopeExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, list<string>> Relations à joindre depuis la racine jusqu'à « establishment ». */
    private const CHAINES = [
        RescheduleProposal::class => [],
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
        if (!isset(self::CHAINES[$resourceClass])) {
            return;
        }
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        foreach (self::CHAINES[$resourceClass] as $i => $relation) {
            $nouvelAlias = 'smart_flow_scope_' . $i;
            $queryBuilder->innerJoin($alias . '.' . $relation, $nouvelAlias);
            $alias = $nouvelAlias;
        }

        $queryBuilder
            ->innerJoin(
                Affectation::class,
                'aff_scope_smart_flow',
                Join::WITH,
                sprintf(
                    'IDENTITY(aff_scope_smart_flow.etablissement) = IDENTITY(%s.establishment) AND IDENTITY(aff_scope_smart_flow.utilisateur) = :scope_smart_flow_user',
                    $alias,
                ),
            )
            ->setParameter('scope_smart_flow_user', $utilisateur->getId(), 'uuid')
            ->distinct();

        if (RescheduleProposal::class === $resourceClass) {
            $this->restrictOwn($queryBuilder, $alias, $utilisateur);
        }
    }

    /** §0.10 du plan : restriction « own » quand l'utilisateur ne porte QUE `reschedule_read_own`. */
    private function restrictOwn(QueryBuilder $queryBuilder, string $alias, Utilisateur $utilisateur): void
    {
        if ($this->security->isGranted('PERM', 'smart_flow.reschedule_manage')) {
            return;
        }
        if (!$this->security->isGranted('PERM', 'smart_flow.reschedule_read_own')) {
            return;
        }

        $clientLie = $utilisateur->getClientLie();
        if ($clientLie === null) {
            // Aucun client lié : échec fermé, aucune ligne visible plutôt qu'une fuite.
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $queryBuilder
            ->andWhere($alias . '.customerId = :smart_flow_client_lie')
            ->setParameter('smart_flow_client_lie', $clientLie, 'uuid');
    }
}
