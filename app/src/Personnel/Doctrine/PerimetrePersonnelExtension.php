<?php

declare(strict_types=1);

namespace App\Personnel\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Personnel\Entity\Absence;
use App\Personnel\Entity\AffectationTravail;
use App\Personnel\Entity\BadgeStaff;
use App\Personnel\Entity\CreneauTravail;
use App\Personnel\Entity\Employe;
use App\Personnel\Entity\PorteeAccesEmploye;
use App\Personnel\Entity\Qualification;
use App\Personnel\Entity\RattachementEmploye;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités des ressources Personnel (RG-SOCLE-05), patron
 * `App\Acces\Doctrine\PerimetreAccesExtension`. `RattachementEmploye`/`CreneauTravail`/`BadgeStaff`
 * portent un établissement direct : restreints par jointure classique. `AffectationTravail`/
 * `PorteeAccesEmploye` héritent de l'établissement de leur parent (`creneauTravail`/`badgeStaff`).
 *
 * `Employe`/`Qualification`/`Absence` sont **multi-site** (§1 du plan) — un Employé peut exister
 * **sans aucun `RattachementEmploye`** (état transitoire à la création, ou définitif s'il n'est
 * rattaché à aucun site, RG-PERSO-09). La restriction stricte par jointure INNER rendrait un tel
 * Employé invisible à **tout le monde**, y compris son créateur (chicken-and-egg bloquant CA-1) —
 * ces 3 entités sont donc visibles si (a) l'employé n'a **aucun** rattachement, **ou** (b) il a au
 * moins un rattachement sur un établissement où l'utilisateur possède une affectation.
 */
final class PerimetrePersonnelExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, string> Chemin (relatif à l'alias racine) vers l'établissement, pour les entités à établissement direct/hérité. */
    private const CHEMINS_DIRECTS = [
        RattachementEmploye::class => '{root}.etablissement',
        CreneauTravail::class => '{root}.etablissement',
        BadgeStaff::class => '{root}.etablissement',
        AffectationTravail::class => 'pp_ct.etablissement',
        PorteeAccesEmploye::class => 'pp_bs.etablissement',
    ];

    /** @var list<class-string> Entités multi-site restreintes via l'employé (visibilité conditionnelle). */
    private const CIBLES_VIA_EMPLOYE = [Employe::class, Qualification::class, Absence::class];

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

        if (isset(self::CHEMINS_DIRECTS[$resourceClass])) {
            $this->restreindreDirect($queryBuilder, $resourceClass, $utilisateur);

            return;
        }

        if (\in_array($resourceClass, self::CIBLES_VIA_EMPLOYE, true)) {
            $this->restreindreViaEmploye($queryBuilder, $resourceClass, $utilisateur);
        }
    }

    private function restreindreDirect(QueryBuilder $queryBuilder, string $resourceClass, Utilisateur $utilisateur): void
    {
        $rootAlias = $queryBuilder->getRootAliases()[0];

        if ($resourceClass === AffectationTravail::class) {
            $queryBuilder->innerJoin($rootAlias . '.creneauTravail', 'pp_ct');
        } elseif ($resourceClass === PorteeAccesEmploye::class) {
            $queryBuilder->innerJoin($rootAlias . '.badgeStaff', 'pp_bs');
        }

        $chemin = str_replace('{root}', $rootAlias, self::CHEMINS_DIRECTS[$resourceClass]);

        $queryBuilder
            ->innerJoin(
                Affectation::class,
                'aff_perimetre_personnel',
                Join::WITH,
                sprintf(
                    'IDENTITY(aff_perimetre_personnel.etablissement) = IDENTITY(%s) AND IDENTITY(aff_perimetre_personnel.utilisateur) = :perimetre_personnel_utilisateur',
                    $chemin,
                ),
            )
            ->setParameter('perimetre_personnel_utilisateur', $utilisateur->getId(), 'uuid')
            ->distinct();
    }

    /**
     * Visible si l'employé n'a aucun `RattachementEmploye` (état transitoire/définitif, §1 du plan)
     * OU s'il en a au moins un sur un établissement où l'utilisateur possède une affectation.
     */
    private function restreindreViaEmploye(QueryBuilder $queryBuilder, string $resourceClass, Utilisateur $utilisateur): void
    {
        $rootAlias = $queryBuilder->getRootAliases()[0];
        // Expression scalaire (UUID) identifiant l'employé, quel que soit le point de départ :
        // l'identifiant de la racine elle-même pour `Employe`, sinon l'association `employe`.
        $employeIdExpr = $resourceClass === Employe::class ? $rootAlias . '.id' : 'IDENTITY(' . $rootAlias . '.employe)';

        $queryBuilder
            ->andWhere(sprintf(
                '(NOT EXISTS (SELECT 1 FROM %s pp_rc WHERE IDENTITY(pp_rc.employe) = %s))'
                . ' OR EXISTS ('
                . 'SELECT 1 FROM %s pp_rv '
                . 'INNER JOIN %s aff_pv WITH IDENTITY(aff_pv.etablissement) = IDENTITY(pp_rv.etablissement) '
                . 'WHERE IDENTITY(pp_rv.employe) = %s AND IDENTITY(aff_pv.utilisateur) = :perimetre_personnel_utilisateur'
                . ')',
                RattachementEmploye::class,
                $employeIdExpr,
                RattachementEmploye::class,
                Affectation::class,
                $employeIdExpr,
            ))
            ->setParameter('perimetre_personnel_utilisateur', $utilisateur->getId(), 'uuid');
    }
}
