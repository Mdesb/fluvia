<?php

declare(strict_types=1);

namespace App\Finance\ExpenseReport\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Finance\ExpenseReport\Entity\ExpenseLine;
use App\Finance\ExpenseReport\Entity\ExpenseReport;
use App\Finance\ExpenseReport\Entity\Reimbursement;
use App\Personnel\Entity\Employe;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Cloisonnement multi-entités de `App\Finance\ExpenseReport` (D3/D8, §0.2 point 2 du plan) — copie
 * stricte du **patron** déjà posé par FIN-2 pour `SupplierInvoice` (nouvel exemplaire dans ce
 * namespace, pas la même classe), combiné par **analogie** (pas par appel direct, modules différents)
 * au double mode « soi »/large de `App\Personnel\Doctrine\PerimetrePersonnelExtension` :
 *
 * - Titulaire d'au moins une permission « large » (`finance.read`,
 *   `finance.expense_report_post_to_ledger`, `finance.manage`) -> visibilité établissement standard
 *   (jointure `Affectation`, comme `PerimetreFinanceExtension` de FIN-2).
 * - Titulaire de **seulement** `finance.expense_report_read_own` -> filtré strictement sur
 *   `IDENTITY(root.employee) = <Employe dont utilisateur = courant>` (aucun résultat si le courant n'a
 *   pas de fiche `Employe`, CA-7).
 */
final class PerimetreFinanceExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /** @var array<class-string, list<string>> Relations à joindre depuis la racine jusqu'à « establishment »/« employee ». */
    private const CHAINES = [
        ExpenseReport::class => [],
        ExpenseLine::class => ['expenseReport'],
        Reimbursement::class => ['expenseReport'],
    ];

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
        if (!isset(self::CHAINES[$resourceClass])) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        foreach (self::CHAINES[$resourceClass] as $i => $relation) {
            $nouvelAlias = 'exp_report_perimetre_' . $i;
            $queryBuilder->innerJoin($alias . '.' . $relation, $nouvelAlias);
            $alias = $nouvelAlias;
        }

        if ($this->doitFiltrerParEmployeSoi($utilisateur)) {
            $this->restreindreParEmployeSoi($queryBuilder, $alias, $utilisateur);

            return;
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
            ->andWhere(sprintf('IDENTITY(%s.establishment) = :exp_report_perimetre_actif', $alias))
            ->setParameter('exp_report_perimetre_actif', $actif, 'uuid')
            ->distinct();
    }

    /**
     * Vrai si l'utilisateur détient `finance.expense_report_read_own` mais **aucune** permission large
     * (`finance.read`/`finance.expense_report_post_to_ledger`/`finance.manage`) — le droit plus large
     * prévaut toujours : filtrage établissement standard, pas de restriction par employé propriétaire.
     */
    private function doitFiltrerParEmployeSoi(Utilisateur $utilisateur): bool
    {
        $codes = $this->calculateur->codesEffectifs($utilisateur, $this->contexte->idActif());

        $large = $this->calculateur->autorise($codes, 'finance', 'read')
            || $this->calculateur->autorise($codes, 'finance', 'expense_report_post_to_ledger')
            || $this->calculateur->autorise($codes, 'finance', 'manage');

        return !$large && $this->calculateur->autorise($codes, 'finance', 'expense_report_read_own');
    }

    private function restreindreParEmployeSoi(QueryBuilder $queryBuilder, string $alias, Utilisateur $utilisateur): void
    {
        $employe = $this->em->getRepository(Employe::class)->findOneBy(['utilisateur' => $utilisateur]);
        if (!$employe instanceof Employe) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $queryBuilder
            ->andWhere(sprintf('IDENTITY(%s.employee) = :exp_report_perimetre_soi_employe', $alias))
            ->setParameter('exp_report_perimetre_soi_employe', $employe->getId(), 'uuid');
    }
}
