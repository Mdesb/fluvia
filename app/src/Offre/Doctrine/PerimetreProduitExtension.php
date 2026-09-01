<?php

declare(strict_types=1);

namespace App\Offre\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Offre\Entity\ComplementaryProduct;
use App\Offre\Entity\ConversionType;
use App\Offre\Entity\GrilleTarifaire;
use App\Offre\Entity\PrixHistorique;
use App\OptionProduit\Entity\OptionProduit;
use App\Offre\Entity\ProductPhoto;
use App\Offre\Entity\Produit;
use App\Offre\Entity\Promotion;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;
use App\Offre\Entity\Categorie;
use App\Offre\Entity\TypeTarif;
use App\Platform\Scoping\ScopedReferenceQuery;
use App\Securite\Service\ContexteEtablissement;
use App\Offre\Entity\Saison;
use App\OptionProduit\Entity\GroupeOption;
use App\OptionProduit\Entity\ValeurOption;
use App\Offre\Entity\TrancheQuotientFamilial;

/**
 * Cloisonnement multi-entités des produits (RG-SOCLE-05) : on voit les produits de l'établissement
 * **actif**, plus les produits **sans établissement** — le socle partagé.
 *
 * **CE QUE CETTE EXTENSION A CESSÉ DE FAIRE, LE 27/08, ET POURQUOI.**
 *
 * Elle filtrait sur les **affectations de l'utilisateur** — toutes, quel que soit l'établissement
 * actif — par une jointure **interne** sur `produit.etablissements`. Deux conséquences, constatées le
 * même jour sur la préproduction, sur le même écran :
 *
 * - une administratrice affectée à Piscine A **et** Patinoire B, travaillant sur Piscine A, voyait le
 *   produit de Patinoire B dans son catalogue — et **l'a encaissé** sur la caisse de Piscine A, où il
 *   est entré dans une chaîne de scellement NF525 qui est tenue *par point de vente* ;
 * - la jointure étant interne, **un produit sans établissement n'était visible de personne** : les
 *   quatorze produits du socle avaient disparu, et le catalogue de Piscine A n'affichait plus que
 *   l'unique produit du voisin.
 *
 * Le raisonnement est déjà écrit dans `ScopedReferenceQuery`, pour les référentiels tarifaires :
 * *un responsable affecté à A et à B qui vend au guichet de A ne doit pas voir ce qui vient de B — il
 * poserait un prix sur un tarif qui n'existe pas là où il encaisse, et le défaut ne se verrait qu'à la
 * facture.* Ce qui vaut pour un type de tarif vaut a fortiori pour le produit qu'il tarife.
 *
 * **L'en-tête `X-Etablissement` n'est pas vérifié ici, et il n'a pas à l'être.** `ContexteEtablissement`
 * le lit sans contrôler l'appartenance ; c'est `PermissionVoter` qui ferme la porte, en calculant les
 * droits comme *l'union des permissions des affectations de l'utilisateur **sur l'établissement
 * actif***. Un en-tête forgé vers un site où l'on n'est pas affecté ne rend aucun droit, donc aucune
 * opération. Le contrôle est ailleurs, il est réel, et le dupliquer ici créerait le second patron que
 * D51 interdit.
 *
 * **Ce que ce fichier ne peut pas faire, et qu'il faut savoir :** il filtre les *lectures de collection*
 * d'API Platform. Un `find()` direct dans un service le court-circuite — c'est exactement pour ça que
 * `OptionsDisponiblesProvider` refait le contrôle à la main, et c'est son refus qui a révélé la fuite.
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

        // Ajoutee le 28/08, meme raison que ses trois voisines : elle tient son perimetre du
        // produit et rien ne le lui appliquait. Les options d'un produit disent la composition
        // d'une offre concurrente.
        OptionProduit::class => 'produit',
        // Posee AVANT l'ouverture de la ressource : l'entite n'a pas encore d'operations d'API, et
        // c'est precisement le bon moment. Une entite exposee sans cloisonnement ne produit pas
        // d'erreur, elle produit des lignes en trop.
        // ⚠ CHAINE CORRIGEE : elle disait `produit`, la propriete se nomme `product`.
        //
        // DEUX REGLES DE NOMMAGE COHABITENT DANS CETTE LIGNE, ET LES CONFONDRE PRODUIT L'ERREUR
        // INVERSE. Le segment est un CHEMIN DE PROPRIETE vers le produit porteur : la propriete
        // d'une entite neuve se nomme en anglais (D5). Seul le champ FINAL, sur lequel cette
        // extension ecrit `etablissement` en dur, reste francais.
        //
        // Signale par allaccess-c2 en ouvrant l'API de ComplementaryProduct : la jointure
        // portait sur un chemin inexistant. Le defaut etait LATENT — l'extension ne s'executait
        // jamais sur une entite sans operation — et il s'est reveille a la premiere route.
        //
        // Le garde-fou n°28 ne verifiait alors que le champ final ; il a ete elargi aux SEGMENTS
        // le meme jour, en reponse a ce signalement. Un segment faux ne lui echappe plus.
        ComplementaryProduct::class => 'product',

        // Ajoutee le 28/08 avec les photos : elle tient son perimetre du produit, comme ses
        // voisines. Une photo visible d un produit qui ne l est pas montrerait le visuel d une
        // offre que personne n a encore annoncee.
        ProductPhoto::class => 'produit',
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

        // ⚠ AJOUTE LE 31/08. `GroupeOption` ne portait aucune relation sortante : il etait
        // atteint depuis le pivot `OptionProduit`, jamais l'inverse — donc aucune carte de
        // chemins ne pouvait l'exprimer. La colonne `etablissement` posee par la migration
        // `Version20260831220000` lui donne enfin un proprietaire, et il rejoint le patron
        // que son propre en-tete revendiquait deja (« comme TypeTarif/Saison en M1 »).
        //
        // Mesure : un groupe cree sur un etablissement etait visible depuis l'autre, et
        // `ValeurOption` expose l'impact tarifaire de chaque option. `OptionsPartageesTest`.
        GroupeOption::class,
    ];

    /**
     * ⚠ REFERENTIELS CLOISONNES PAR LA RELATION QU'ILS PORTENT, ET NON PAR UNE COLONNE.
     *
     * `ValeurOption` n'a pas d'etablissement : elle appartient a son groupe, qui en a un depuis le
     * 31/08. Le garde-fou « ecriture transfrontiere » a signale ce cas des que `GroupeOption` a ete
     * cloisonne — avant, il n'y avait pas de frontiere a traverser.
     *
     * ⚠ PAR LE GROUPE, PAS PAR `articleStock`. Elle porte les deux, et `ArticleStock` est cloisonne
     * — la jointure serait donc tentante. Mais une valeur d'option n'appartient pas a l'article de
     * stock qu'elle consomme : elle appartient au groupe qui la contient. Cloisonner par le stock
     * rendrait invisibles toutes les valeurs sans article, qui sont la majorite.
     *
     * @var array<class-string, string>
     */
    private const REFERENTIELS_CLOISONNES_VIA = [
        ValeurOption::class => 'groupeOption',
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

        if (isset(self::REFERENTIELS_CLOISONNES_VIA[$resourceClass])) {
            $actif = $this->contexte->idActif();
            $alias = $queryBuilder->getRootAliases()[0];

            if ($actif === null) {
                // Meme fermeture par defaut que ci-dessus : rien plutot que tout.
                $queryBuilder->andWhere('1 = 0');

                return;
            }

            $queryBuilder
                ->innerJoin($alias . '.' . self::REFERENTIELS_CLOISONNES_VIA[$resourceClass], 'ref_via')
                ->andWhere('IDENTITY(ref_via.etablissement) = :perimetre_ref_via')
                ->setParameter('perimetre_ref_via', $actif, 'uuid');

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

        $actif = $this->contexte->idActif();

        // LA JOINTURE EST EXTERNE, ET C'EST TOUT LE CORRECTIF.
        //
        // En interne, un produit sans établissement ne satisfait aucune ligne : il n'était visible de
        // personne. Or « sans établissement » est précisément la façon dont ce dépôt écrit « socle,
        // partagé par tous » pour cette entité — elle n'a ni discriminant `portee` ni colonne
        // `etablissement`, donc ni l'un ni l'autre des deux patrons de D51.
        $queryBuilder->leftJoin($aliasProduit . '.etablissements', 'perim_etab');

        if ($actif === null) {
            // Fermeture par défaut, même règle que `ScopedReferenceQuery` : sans établissement actif,
            // le socle et rien d'autre. Montrer « tout » serait la seule erreur irrattrapable ici.
            $queryBuilder
                ->andWhere(sprintf('SIZE(%s.etablissements) = 0', $aliasProduit))
                ->distinct();

            return;
        }

        // `perim_etab.id = :param` typé `'uuid'` explicitement : sur un identifiant à type
        // personnalisé, une comparaison sans type ne compte rien **et ne lève pas** (D58). Ici, elle
        // ne rendrait pas « moins » — elle rendrait *le socle seul*, ce qui ressemble à une
        // configuration incomplète bien plus qu'à un bug.
        $queryBuilder
            ->andWhere(sprintf('(perim_etab.id = :perim_actif OR SIZE(%s.etablissements) = 0)', $aliasProduit))
            ->setParameter('perim_actif', $actif, 'uuid')
            ->distinct();
    }
}
