<?php

declare(strict_types=1);

namespace App\Group\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Group\Entity\GroupBooking;
use App\Group\Entity\GroupBookingItem;
use App\Group\Entity\GroupGratuite;
use App\Group\Entity\GroupGratuiteContingent;
use App\Group\Entity\GroupParticipant;
use App\Group\Entity\GroupProduct;
use App\Group\Entity\ParticipantGroup;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement des ressources du module `App\Group` (RG-SOCLE-05, patron `PerimetreMuseeExtension`) :
 * on ne voit que ce qui est rattaché à l'établissement **actif** (en-tête `X-Etablissement`). Le
 * `PermissionVoter` a déjà refusé un établissement hors périmètre avant cette requête : ici on filtre,
 * on ne rejuge pas.
 *
 * `GroupParticipant` n'a pas de colonne `etablissement` : il est rattaché par son `group`, d'où la
 * jointure. Toute entité de ce module rattachée à un établissement DOIT figurer ici — sinon elle fuit
 * (garde-fou n°35).
 */
final class GroupScopeExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, list<array{property: string, alias: string}>> */
    private const JOINS = [
        GroupParticipant::class => [['property' => 'group', 'alias' => 'grp']],
        GroupBookingItem::class => [['property' => 'booking', 'alias' => 'bk']],
        GroupGratuite::class => [['property' => 'booking', 'alias' => 'gbk']],
    ];

    /** @var array<class-string, string> Chemin vers `etablissement` une fois les jointures faites. */
    private const CHEMIN_ETABLISSEMENT = [
        ParticipantGroup::class => '{root}.etablissement',
        GroupBooking::class => '{root}.etablissement',
        GroupParticipant::class => 'grp.etablissement',
        GroupProduct::class => '{root}.etablissement',
        GroupBookingItem::class => 'bk.etablissement',
        GroupGratuiteContingent::class => '{root}.etablissement',
        GroupGratuite::class => 'gbk.etablissement',
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
        if (!isset(self::CHEMIN_ETABLISSEMENT[$resourceClass])) {
            return;
        }
        if (!$this->security->getUser() instanceof Utilisateur) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];

        if (isset(self::JOINS[$resourceClass])) {
            foreach (self::JOINS[$resourceClass] as $jointure) {
                $queryBuilder->innerJoin($rootAlias . '.' . $jointure['property'], $jointure['alias']);
            }
        }

        $chemin = str_replace('{root}', $rootAlias, self::CHEMIN_ETABLISSEMENT[$resourceClass]);

        $actif = $this->contexte->idActif();
        if ($actif === null) {
            // Fermeture par défaut : une liste vide se remarque, une liste inter-établissements a
            // seulement l'air plus longue.
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $queryBuilder
            ->andWhere(sprintf('IDENTITY(%s) = :perimetre_group_actif', $chemin))
            ->setParameter('perimetre_group_actif', $actif, 'uuid')
            ->distinct();
    }
}
