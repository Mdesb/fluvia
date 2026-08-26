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
use App\Offre\Entity\Promotion;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;
use App\Offre\Entity\Categorie;
use App\Offre\Entity\TypeTarif;
use App\Platform\Scoping\ScopedReferenceQuery;
use App\Securite\Service\ContexteEtablissement;
use App\Offre\Entity\Saison;
use App\Offre\Entity\TrancheQuotientFamilial;

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
        Promotion::class => null,
        ConversionType::class => 'produit',
        GrilleTarifaire::class => 'produit',
        PrixHistorique::class => 'grille.produit',
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

    /**
     * Referentiels « socle + ajout local » (D51) : la lecture voit le socle PLUS les ajouts des
     * etablissements de l utilisateur, jamais ceux d un autre.
     *
     * Le filtre n est pas ecrit ici : `ScopedReferenceQuery` le porte une seule fois, parce que le
     * filtre faux — `etablissement = :courant` — se lit comme une evidence et fait DISPARAITRE TOUT
     * LE SOCLE, donc tous les tarifs de base, donc tous les prix.
     *
     * @var list<class-string>
     */
    private const REFERENTIELS_A_SOCLE = [
        TypeTarif::class,
        Categorie::class,
    ];

    /**
     * Referentiels sans socle : la liste entiere appartient a l etablissement (D51).
     *
     * @var list<class-string>
     */
    private const REFERENTIELS_CLOISONNES = [
        Saison::class,
        TrancheQuotientFamilial::class,
    ];

    private function restreindre(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        // D51 — referentiels ENTIEREMENT cloisonnes : pas de socle, donc pas de discriminant. Ici le
        // filtre « etablissement = actif » est le bon, precisement parce qu il n y a rien a partager :
        // une saison est decidee par l exploitant, une tranche de quotient familial par la commune ou
        // la CAF, et un tarif calcule sur la mauvaise grille est une erreur de facturation opposable.
        //
        // Une ligne sans etablissement — anterieure au cloisonnement — n est visible de personne. La
        // migration les laisse orphelines plutot que de leur inventer un proprietaire.
        if (\in_array($resourceClass, self::REFERENTIELS_CLOISONNES, true)) {
            $actif = $this->contexte->idActif();
            $alias = $queryBuilder->getRootAliases()[0];

            if ($actif === null) {
                // Fermeture par defaut : sans etablissement actif, rien. Une liste vide se remarque ;
                // une liste inter-etablissements a seulement l air plus longue.
                $queryBuilder->andWhere('1 = 0');

                return;
            }

            $queryBuilder
                ->andWhere(sprintf('IDENTITY(%s.etablissement) = :perimetre_ref_cloisonne', $alias))
                ->setParameter('perimetre_ref_cloisonne', $actif, 'uuid');

            return;
        }

        if (\in_array($resourceClass, self::REFERENTIELS_A_SOCLE, true)) {
            ScopedReferenceQuery::restreindre($queryBuilder, $this->contexte->idActif());

            return;
        }

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
