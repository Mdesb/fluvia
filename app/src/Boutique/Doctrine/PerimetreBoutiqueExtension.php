<?php

declare(strict_types=1);

namespace App\Boutique\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Boutique\Entity\AllocationQuotaOTA;
use App\Boutique\Entity\CompteClient;
use App\Boutique\Entity\DemandeRemboursement;
use App\Boutique\Entity\PartenaireOTA;
use App\Boutique\Entity\RetraitClickCollect;
use App\Boutique\Entity\ReversementOTA;
use App\Boutique\Entity\Vitrine;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités des ressources back-office Boutique (RG-SOCLE-05, patron
 * `PerimetreReservationExtension`) : un agent staff ne voit que les objets rattachés à un
 * établissement où il possède au moins une affectation. Le tunnel public (panier, tunnel de
 * commande) n'est PAS filtré ici : établissement résolu depuis la `Vitrine`/le `Panier` eux-mêmes,
 * jamais depuis l'en-tête `X-Etablissement` d'un visiteur anonyme (§3 du plan).
 */
final class PerimetreBoutiqueExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, string> */
    private const CHEMINS = [
        Vitrine::class => '{root}.etablissement',
        CompteClient::class => '{root}.etablissement',
        DemandeRemboursement::class => '{root}.etablissement',
        RetraitClickCollect::class => '{root}.etablissement',
        PartenaireOTA::class => '{root}.etablissement',
        AllocationQuotaOTA::class => '{root}.etablissement',
        ReversementOTA::class => '{root}.etablissement',
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
        if (!isset(self::CHEMINS[$resourceClass])) {
            return;
        }
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $chemin = str_replace('{root}', $rootAlias, self::CHEMINS[$resourceClass]);

        $queryBuilder
            ->innerJoin(
                Affectation::class,
                'aff_perimetre_boutique',
                Join::WITH,
                sprintf(
                    'IDENTITY(aff_perimetre_boutique.etablissement) = IDENTITY(%s) AND IDENTITY(aff_perimetre_boutique.utilisateur) = :perimetre_boutique_utilisateur',
                    $chemin,
                ),
            )
            ->setParameter('perimetre_boutique_utilisateur', $utilisateur->getId(), 'uuid')
            ->distinct();
    }
}
