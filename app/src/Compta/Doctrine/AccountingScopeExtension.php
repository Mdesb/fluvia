<?php

declare(strict_types=1);

namespace App\Compta\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Compta\Entity\BordereauVersement;
use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\DeclarationEReporting;
use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\EtalementPca;
use App\Compta\Entity\ExpenseAccountMapping;
use App\Compta\Entity\ExportComptable;
use App\Compta\Entity\Journal;
use App\Compta\Entity\MappingComptable;
use App\Compta\Entity\MouvementPca;
use App\Compta\Entity\PeriodeComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\Rad;
use App\Compta\Entity\RegieRecettes;
use App\Compta\Entity\TauxTva;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement des ressources comptables (RG-SOCLE-05, D3/D8).
 *
 * **Pourquoi ce fichier existe.** Le module `Compta` était le seul des vingt-six modules exposant des
 * ressources API à n'avoir **aucune** extension de périmètre — le répertoire `Doctrine/` était absent,
 * pas vide. Conséquence mesurée avant correction : `GET /ecritures-comptables`, protégé par la seule
 * permission `compta.lire`, renvoyait le **grand livre de tous les établissements** — montants,
 * comptes, journaux, périodes.
 *
 * Cette exposition est d'une autre nature que les sept IDOR du projet : un IDOR exige de connaître un
 * identifiant, ici il suffisait d'appeler la route. Trouvée par claude-C en dépistant les 235 entités
 * exposées par l'API.
 *
 * **Le rattachement existait déjà**, seule l'extension qui l'emprunte manquait :
 * `EcritureComptable::getEtablissement()` résolvait de longue date par
 * `profilExploitant.etablissementPrincipal`. C'était un oubli, pas une impasse de conception.
 *
 * **Ce qui est couvert, et ce qui ne l'est pas.** Les quinze entités ci-dessous ont un chemin
 * **vérifié** vers l'établissement. Six autres entités exposées du module n'en ont pas d'évident —
 * `BordereauPayFiP`, `FactureB2G`, `LettrageEcriture`, `MoyenPaiement`, `QualificationEquipement`,
 * `VenteImpayeeRegie` — et ne sont **délibérément pas** traitées ici : certaines sont probablement
 * globales à dessein (un moyen de paiement, une qualification d'équipement), et claude-C a établi que
 * `VenteImpayeeRegie` l'est par conception. Inventer un chemin non vérifié produirait un cloisonnement
 * qui filtre à côté — pire qu'une absence de filtre, parce qu'il rassure.
 */
final class AccountingScopeExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /**
     * Propriété menant au `ProfilExploitant`, par classe de ressource. `ExpenseAccountMapping` porte le
     * nom anglais (D5) : le module est historique et francophone, ses ajouts suivent la règle en vigueur.
     *
     * @var array<class-string, string>
     */
    private const VIA_PROFIL = [
        CompteComptable::class => 'profilExploitant',
        DeclarationEReporting::class => 'profilExploitant',
        EcritureComptable::class => 'profilExploitant',
        EtalementPca::class => 'profilExploitant',
        ExpenseAccountMapping::class => 'businessProfile',
        ExportComptable::class => 'profilExploitant',
        Journal::class => 'profilExploitant',
        MappingComptable::class => 'profilExploitant',
        PeriodeComptable::class => 'profilExploitant',
        Rad::class => 'profilExploitant',
        RegieRecettes::class => 'profilExploitant',
        TauxTva::class => 'profilExploitant',
    ];

    /**
     * Rattachement indirect : une jointure intermédiaire mène au porteur du `ProfilExploitant`.
     *
     * @var array<class-string, string>
     */
    private const VIA_RELATION = [
        BordereauVersement::class => 'regie',
        MouvementPca::class => 'ecritureLiee',
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
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return;
        }

        $racine = $queryBuilder->getRootAliases()[0];

        if ($resourceClass === ProfilExploitant::class) {
            // Seule entité du module portant l'établissement directement.
            $chemin = $racine . '.etablissementPrincipal';
        } elseif (isset(self::VIA_PROFIL[$resourceClass])) {
            $queryBuilder->innerJoin($racine . '.' . self::VIA_PROFIL[$resourceClass], 'pe_scope');
            $chemin = 'pe_scope.etablissementPrincipal';
        } elseif (isset(self::VIA_RELATION[$resourceClass])) {
            $queryBuilder
                ->innerJoin($racine . '.' . self::VIA_RELATION[$resourceClass], 'rel_scope')
                ->innerJoin('rel_scope.profilExploitant', 'pe_scope');
            $chemin = 'pe_scope.etablissementPrincipal';
        } else {
            return;
        }

        // Même patron que `PerimetreVenteExtension` : l'utilisateur doit posséder une affectation sur
        // l'établissement de la ressource. La jointure vaut filtre — une ressource sans affectation
        // correspondante disparaît du résultat, elle n'est pas signalée comme interdite.
        $queryBuilder
            ->innerJoin(
                Affectation::class,
                'aff_accounting_scope',
                Join::WITH,
                sprintf(
                    'IDENTITY(aff_accounting_scope.etablissement) = IDENTITY(%s) AND IDENTITY(aff_accounting_scope.utilisateur) = :accounting_scope_user',
                    $chemin,
                ),
            )
            ->setParameter('accounting_scope_user', $utilisateur->getId(), 'uuid')
            ->distinct();
    }
}
