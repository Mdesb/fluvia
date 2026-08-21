<?php

declare(strict_types=1);

namespace App\Finance\Treasury\Service;

use App\Finance\Treasury\Entity\BankAccount;
use App\Finance\Treasury\Entity\BankStatementLine;
use App\Finance\Treasury\Enum\BankStatementLineStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Position de trésorerie (§0.8 du plan, RG-TRE-05, CA-4) — vue calculée, **non persistée** : pour
 * chaque `BankAccount` du périmètre donné (filtré si `$compteUnique` fourni) — `openingBalance +
 * Σ(BankStatementLine.amount)` où **seules** les lignes `status = reconciled` avec
 * `operationDate <= asOfDate` comptent (RG-TRE-05 littéral). Factorisé hors de `TreasuryPositionProvider`
 * (endpoint HTTP) pour être réutilisé tel quel par `CashflowForecastCalculator` (RG-TRE-08).
 */
final class TreasuryPositionCalculator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param list<string> $etablissements identifiants **binaires** (`Uuid::toBinary()`, §
     *                                     `PerimetreEtablissementsResolver`)
     *
     * @return array{asOfDate: string, balanceCents: int, perAccount: list<array{bankAccountId: string, label: string, balanceCents: int}>}
     */
    public function position(array $etablissements, \DateTimeImmutable $asOfDate, ?BankAccount $compteUnique = null): array
    {
        $qb = $this->em->getRepository(BankAccount::class)->createQueryBuilder('c')
            ->andWhere('c.establishment IN (:etablissements)')
            ->setParameter('etablissements', $etablissements, ArrayParameterType::BINARY);

        if ($compteUnique !== null) {
            $qb->andWhere('c.id = :id')->setParameter('id', $compteUnique->getId(), 'uuid');
        }

        /** @var list<BankAccount> $comptes */
        $comptes = $qb->getQuery()->getResult();

        $totalCentimes = 0;
        $parCompte = [];
        foreach ($comptes as $compte) {
            $reconciliees = $this->em->createQueryBuilder()
                ->select('SUM(l.amount)')
                ->from(BankStatementLine::class, 'l')
                ->innerJoin('l.statementImport', 'si')
                ->andWhere('si.bankAccount = :compte')
                ->andWhere('l.status = :statut')
                ->andWhere('l.operationDate <= :asOf')
                ->setParameter('compte', $compte->getId(), 'uuid')
                ->setParameter('statut', BankStatementLineStatus::Reconciled)
                ->setParameter('asOf', $asOfDate, 'date_immutable')
                ->getQuery()->getSingleScalarResult();

            $soldeCompteCentimes = $this->centimes($compte->getOpeningBalance()) + $this->centimes((string) ($reconciliees ?? '0.00'));
            $totalCentimes += $soldeCompteCentimes;

            $parCompte[] = [
                'bankAccountId' => (string) $compte->getId(),
                'label' => $compte->getLabel(),
                'balanceCents' => $soldeCompteCentimes,
            ];
        }

        return [
            'asOfDate' => $asOfDate->format('Y-m-d'),
            'balanceCents' => $totalCentimes,
            'perAccount' => $parCompte,
        ];
    }

    private function centimes(string $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }
}
