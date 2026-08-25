<?php

declare(strict_types=1);

namespace App\Offre\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Offre\Entity\ConversionType;
use App\Offre\Entity\GrilleTarifaire;
use App\Offre\Entity\PrixHistorique;
use App\Offre\Entity\Produit;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités des produits (RG-SOCLE-05, réutilise la stratégie du socle) :
 * un utilisateur ne voit que les produits rattachés à un établissement où il a une affectation.
 * Complète PerimetreEtablissementExtension du socle pour l'entité App\Offre\Entity\Produit.
 */
final class PerimetreProduitExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /**
     * Chemin d'association (relatif a l'alias racine) menant au `Produit` porteur des
     * etablissements — `null` quand la ressource EST le produit.
     *
     * Les trois entites satellites ne portent pas d'etablissement : elles le tiennent de leur
     * produit. Sans ces chemins, leurs collections etaient lisibles d'un etablissement a l'autre —
     * on lisait la grille tarifaire d'un voisin, son historique de prix, et jusqu'a ses conversions
     * de type. C'est le classement que `claude-C` avait deja pose dans la ligne de base (groupes A
     * et A2, chemins `produit` et `grille.produit`) : il n'y avait qu'a l'appliquer.
     *
     * @var array<class-string, string|null>
     */
    private const CHEMINS = [
        Produit::class => null,
        ConversionType::class => 'produit',
        GrilleTarifaire::class => 'produit',
        PrixHistorique::class => 'grille.produit',
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
        if (!\array_key_exists($resourceClass, self::CHEMINS)) {
            return;
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];

        // Remonte jusqu'au produit, segment par segment : `grille.produit` demande deux jointures.
        $aliasProduit = $rootAlias;
        $chemin = self::CHEMINS[$resourceClass];
        if ($chemin !== null) {
            $rang = 0;
            foreach (explode('.', $chemin) as $segment) {
                $suivant = 'perim_vers_produit_' . $rang++;
                $queryBuilder->innerJoin($aliasProduit . '.' . $segment, $suivant);
                $aliasProduit = $suivant;
            }
        }

        $queryBuilder
            ->innerJoin($aliasProduit . '.etablissements', 'perim_etab')
            ->innerJoin(
                Affectation::class,
                'perim_aff',
                Join::WITH,
                'IDENTITY(perim_aff.etablissement) = perim_etab.id AND IDENTITY(perim_aff.utilisateur) = :perim_utilisateur'
            )
            ->setParameter('perim_utilisateur', $utilisateur->getId(), 'uuid')
            ->distinct();
    }
}
