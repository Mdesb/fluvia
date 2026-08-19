<?php

declare(strict_types=1);

namespace App\Caisse\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Caisse\Entity\AlerteEcartCaisse;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités de `AlerteEcartCaisse` (RG-SOCLE-05, RG-CAISSEZ-06) : un utilisateur ne
 * voit que les alertes rattachées à un établissement où il possède au moins une affectation. Même
 * mécanisme que `App\Vente\Doctrine\PerimetreVenteExtension` (non modifiée, hors périmètre de ce
 * lot — cf. plan §1.4/§4 : ce lot introduit sa propre extension, scopée à `App\Caisse`, plutôt que
 * de toucher au fichier partagé `App\Vente`), le champ `etablissement` étant direct sur
 * `AlerteEcartCaisse` (pas de jointure intermédiaire nécessaire).
 */
final class PerimetreAlerteEcartExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
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
        if ($resourceClass !== AlerteEcartCaisse::class) {
            return;
        }
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];

        $queryBuilder
            ->innerJoin(
                Affectation::class,
                'aff_perimetre_alerte_ecart',
                Join::WITH,
                sprintf(
                    'IDENTITY(aff_perimetre_alerte_ecart.etablissement) = IDENTITY(%s.etablissement) AND IDENTITY(aff_perimetre_alerte_ecart.utilisateur) = :perimetre_alerte_ecart_utilisateur',
                    $rootAlias,
                ),
            )
            ->setParameter('perimetre_alerte_ecart_utilisateur', $utilisateur->getId(), 'uuid')
            ->distinct();
    }
}
