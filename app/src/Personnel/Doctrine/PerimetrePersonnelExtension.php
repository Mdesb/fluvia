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
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
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
 * moins un rattachement sur un établissement où l'utilisateur possède une affectation. Pour `Employe`
 * spécifiquement, le cas (a) — employé orphelin — est réservé aux détenteurs de
 * `personnel.gerer_employe` (défaut sinon : invisible) : la visibilité inconditionnelle d'un employé
 * orphelin à quiconque détient `personnel.lire`, sans le moindre lien d'établissement, exposait des
 * fiches RH à des utilisateurs totalement étrangers.
 *
 * `Qualification`/`CreneauTravail`/`AffectationTravail` supportent en outre `personnel.lire_soi`
 * (RG-PERSO §5) : un détenteur de ce seul droit (sans `personnel.lire`) ne voit que les
 * enregistrements liés à son propre `Employe` (`Employe.utilisateur` = utilisateur courant).
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

    /** @var list<class-string> Entités supportant `personnel.lire_soi` (filtrage par employé propriétaire). */
    private const CIBLES_LIRE_SOI = [Qualification::class, CreneauTravail::class, AffectationTravail::class];

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
        private readonly CalculateurDroits $calculateur,
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

        if (\in_array($resourceClass, self::CIBLES_LIRE_SOI, true) && $this->doitFiltrerParEmployeSoi($utilisateur)) {
            $this->restreindreParEmployeSoi($queryBuilder, $resourceClass, $utilisateur);

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

        // ── L'AXE EST L'ÉTABLISSEMENT ACTIF ──────────────────────────────────────────────────
        //
        // Bascule du 28/08. Le filtre portait sur le PÉRIMÈTRE du lecteur : un exploitant affecté à
        // trois sites voyait les données des trois, sous le titre d'un seul. Constaté dans le
        // navigateur — le tableau de bord d'un site créé le matin même annonçait une session de
        // caisse ouverte, celle du voisin, et la pastille « prêt à vendre » s'allumait sur un site
        // sans caisse.
        //
        // L'écran porte un sélecteur d'établissement et titre ses pages du site actif : les données
        // le suivent. Le périmètre dit ce qu'on a le DROIT de voir ; l'actif dit ce qu'on REGARDE.
        // `PermissionVoter` a déjà refusé un établissement hors périmètre avant cette requête : on
        // filtre, on ne rejuge pas.
        $actif = $this->contexte->idActif();
        if ($actif === null) {
            // Fermeture par défaut : une liste vide se remarque, une liste inter-établissements a
            // seulement l'air plus longue.
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $queryBuilder
            ->andWhere(sprintf('IDENTITY(%s) = :%s', $chemin, 'perimetre_personnel_actif'))
            ->setParameter('perimetre_personnel_actif', $actif, 'uuid')
            ->distinct();
    }

    /**
     * Visible si l'employé n'a aucun `RattachementEmploye` (état transitoire/définitif, §1 du plan)
     * OU s'il en a au moins un sur un établissement où l'utilisateur possède une affectation. Pour
     * `Employe`, le cas orphelin est en outre réservé aux détenteurs de `personnel.gerer_employe`.
     */
    private function restreindreViaEmploye(QueryBuilder $queryBuilder, string $resourceClass, Utilisateur $utilisateur): void
    {
        $rootAlias = $queryBuilder->getRootAliases()[0];
        // Expression scalaire (UUID) identifiant l'employé, quel que soit le point de départ :
        // l'identifiant de la racine elle-même pour `Employe`, sinon l'association `employe`.
        $employeIdExpr = $resourceClass === Employe::class ? $rootAlias . '.id' : 'IDENTITY(' . $rootAlias . '.employe)';

        $orphelinVisible = $resourceClass === Employe::class
            ? $this->orphelinReserveAuxGestionnaires($employeIdExpr, $utilisateur)
            : sprintf('NOT EXISTS (SELECT 1 FROM %s pp_rc WHERE IDENTITY(pp_rc.employe) = %s)', RattachementEmploye::class, $employeIdExpr);

        $actif = $this->contexte->idActif();
        if ($actif === null) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $queryBuilder
            ->andWhere(sprintf(
                '(%s)'
                . ' OR EXISTS ('
                . 'SELECT 1 FROM %s pp_rv '
                . 'WHERE IDENTITY(pp_rv.employe) = %s AND IDENTITY(pp_rv.etablissement) = :perimetre_personnel_actif'
                . ')',
                $orphelinVisible,
                RattachementEmploye::class,
                $employeIdExpr,
            ))
            ->setParameter('perimetre_personnel_actif', $actif, 'uuid');
    }

    /**
     * Fragment DQL couvrant le cas « employé orphelin » (`Employe` uniquement) — vrai (visible) si
     * l'employé n'a aucun rattachement **et** que l'utilisateur détient `personnel.gerer_employe`
     * sur son établissement actif ; toujours faux sinon (`1 = 0`, pas de fuite vers un simple
     * lecteur).
     */
    private function orphelinReserveAuxGestionnaires(string $employeIdExpr, Utilisateur $utilisateur): string
    {
        $codes = $this->calculateur->codesEffectifs($utilisateur, $this->contexte->idActif());
        if (!$this->calculateur->autorise($codes, 'personnel', 'gerer_employe')) {
            return '1 = 0';
        }

        return sprintf('NOT EXISTS (SELECT 1 FROM %s pp_rc WHERE IDENTITY(pp_rc.employe) = %s)', RattachementEmploye::class, $employeIdExpr);
    }

    /**
     * Vrai si l'utilisateur détient `personnel.lire_soi` mais **pas** `personnel.lire` sur
     * l'établissement actif (le droit plus large prévaut : filtrage standard, pas de restriction
     * supplémentaire par employé propriétaire).
     */
    private function doitFiltrerParEmployeSoi(Utilisateur $utilisateur): bool
    {
        $codes = $this->calculateur->codesEffectifs($utilisateur, $this->contexte->idActif());

        return !$this->calculateur->autorise($codes, 'personnel', 'lire')
            && $this->calculateur->autorise($codes, 'personnel', 'lire_soi');
    }

    /**
     * Restreint `Qualification`/`AffectationTravail` (association `employe` directe) et
     * `CreneauTravail` (via `AffectationTravail`) aux enregistrements liés à l'`Employe` dont
     * `utilisateur` est l'utilisateur courant. Aucun `Employe` lié → aucun résultat (`1 = 0`).
     */
    private function restreindreParEmployeSoi(QueryBuilder $queryBuilder, string $resourceClass, Utilisateur $utilisateur): void
    {
        $rootAlias = $queryBuilder->getRootAliases()[0];

        $employe = $this->em->getRepository(Employe::class)->findOneBy(['utilisateur' => $utilisateur]);
        if (!$employe instanceof Employe) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        if ($resourceClass === CreneauTravail::class) {
            $queryBuilder
                ->andWhere(sprintf(
                    'EXISTS (SELECT 1 FROM %s pp_at_soi WHERE IDENTITY(pp_at_soi.creneauTravail) = %s.id AND IDENTITY(pp_at_soi.employe) = :perimetre_personnel_soi_employe)',
                    AffectationTravail::class,
                    $rootAlias,
                ))
                ->setParameter('perimetre_personnel_soi_employe', $employe->getId(), 'uuid');

            return;
        }

        $queryBuilder
            ->andWhere(sprintf('IDENTITY(%s.employe) = :perimetre_personnel_soi_employe', $rootAlias))
            ->setParameter('perimetre_personnel_soi_employe', $employe->getId(), 'uuid');
    }
}
