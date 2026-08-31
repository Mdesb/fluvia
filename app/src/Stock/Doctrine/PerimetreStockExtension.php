<?php

declare(strict_types=1);

namespace App\Stock\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Stock\Entity\ArticleStock;
use App\Stock\Entity\CatalogueFournisseur;
use App\Stock\Entity\CommandeAchat;
use App\Stock\Entity\Fournisseur;
use App\Stock\Entity\ImputationLotStock;
use App\Stock\Entity\Inventaire;
use App\Stock\Entity\LigneCommandeAchat;
use App\Stock\Entity\LigneInventaire;
use App\Stock\Entity\LigneReceptionAchat;
use App\Stock\Entity\LotStock;
use App\Stock\Entity\MouvementStock;
use App\Stock\Entity\ParametrageStock;
use App\Stock\Entity\ReceptionAchat;
use App\Stock\Entity\TransfertStock;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités de `App\Stock` (RG-SOCLE-05, RG-STOCK-13) : une seule classe, `match`
 * sur `$resourceClass`, même patron que `PerimetreProduitExtension`/`PerimetreCautionExtension`.
 * `TransfertStock` n'a pas de colonne établissement directe : filtré sur `articleStockSource.
 * etablissement OR articleStockDestination.etablissement` (§1.6/§5 du plan).
 */
final class PerimetreStockExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, list<string>> Relations à joindre depuis la racine jusqu'à « etablissement ». */
    private const CHAINES = [
        Fournisseur::class => [],
        CatalogueFournisseur::class => ['articleStock'],
        ArticleStock::class => [],
        ParametrageStock::class => [],
        CommandeAchat::class => [],
        LigneCommandeAchat::class => ['commandeAchat'],
        ReceptionAchat::class => [],
        LigneReceptionAchat::class => ['reception'],
        LotStock::class => [],
        MouvementStock::class => [],
        ImputationLotStock::class => ['mouvementStock'],
        Inventaire::class => [],
        LigneInventaire::class => ['inventaire'],
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
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        if ($resourceClass === TransfertStock::class) {
            $this->restreindreTransfert($queryBuilder);

            return;
        }

        if (!isset(self::CHAINES[$resourceClass])) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        foreach (self::CHAINES[$resourceClass] as $i => $relation) {
            $nouvelAlias = 'stock_perimetre_' . $i;
            $queryBuilder->innerJoin($alias . '.' . $relation, $nouvelAlias);
            $alias = $nouvelAlias;
        }

        // ── L'AXE EST L'ÉTABLISSEMENT ACTIF ──────────────────────────────────────────────────
        //
        // Bascule du 28/08. Le filtre portait sur le PÉRIMÈTRE du lecteur : un exploitant affecté à
        // plusieurs sites voyait les données de tous, sous le titre d'un seul. Constaté à l'écran —
        // un site créé le matin même, sans caisse, annonçait une session de caisse ouverte, celle
        // du voisin, et sa pastille « prêt à vendre » s'allumait.
        //
        // L'écran porte un sélecteur d'établissement et titre ses pages du site actif : les données
        // le suivent. Le périmètre dit ce qu'on a le DROIT de voir ; l'actif dit ce qu'on REGARDE.
        //
        // Le droit reste vérifié ailleurs, et c'est ce qui rend la bascule sûre :
        // `ContexteEtablissement::idActif()` ne fait que lire l'en-tête — c'est un sélecteur, pas
        // une preuve — mais `CalculateurDroits::codesEffectifs()` ne retient que les affectations
        // portant SUR cet établissement, donc un en-tête hors périmètre ne donne aucun droit et le
        // voter refuse avant que cette requête n'existe. Éprouvé par `AxeEtablissementActifTest`.
        $actif = $this->contexte->idActif();
        if ($actif === null) {
            // Fermeture par défaut : une liste vide se remarque, une liste inter-établissements a
            // seulement l'air plus longue.
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $queryBuilder
            ->andWhere(sprintf('IDENTITY(%s.etablissement) = :stock_perimetre_actif', $alias))
            ->setParameter('stock_perimetre_actif', $actif, 'uuid')
            ->distinct();
    }

    /**
     * UN TRANSFERT APPARTIENT AUX DEUX ETABLISSEMENTS, PAS A UN SEUL.
     *
     * Il part d'un site et arrive dans un autre. Le rendre invisible depuis l'un des deux ferait
     * disparaitre du stock sans trace pour celui qui l'attend -- et un ecart de stock qu'on ne peut
     * pas expliquer se solde toujours par un inventaire. La regle « source OU destination » est donc
     * conservee telle quelle ; seul le terme de comparaison change, du perimetre vers l'actif.
     */
    private function restreindreTransfert(QueryBuilder $queryBuilder): void
    {
        $actif = $this->contexte->idActif();
        if ($actif === null) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $queryBuilder
            ->innerJoin($alias . '.articleStockSource', 'stock_transfert_source')
            ->innerJoin($alias . '.articleStockDestination', 'stock_transfert_destination')
            ->andWhere(
                'IDENTITY(stock_transfert_source.etablissement) = :stock_transfert_actif'
                . ' OR IDENTITY(stock_transfert_destination.etablissement) = :stock_transfert_actif',
            )
            ->setParameter('stock_transfert_actif', $actif, 'uuid')
            ->distinct();
    }
}
