<?php

declare(strict_types=1);

namespace App\Finance\ExpenseReport\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Finance\ExpenseReport\Entity\ExpenseReport;
use App\Finance\ExpenseReport\Enum\ExpenseReportStatus;
use App\Finance\ExpenseReport\Service\ExpenseReportLedgerPoster;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /finance/expense-reports/{id}/post-to-ledger (§0.5 du plan, RG-EXP-06) : rejeu manuel du
 * déversement comptable après correction du mapping de charge (CA-5 cas limite) — aucune nouvelle
 * approbation redemandée à `App\Autorisation` (l'approbation métier reste acquise, seul le déversement
 * était bloqué). Précondition (ici, pas dans `ExpenseReportLedgerPoster`) : `status === Approved &&
 * ledgerEntry === null` -> 409 sinon.
 *
 * @implements ProcessorInterface<ExpenseReport, ExpenseReport>
 */
final class PostToLedgerExpenseReportProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ExpenseReportLedgerPoster $poster,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ExpenseReport
    {
        \assert($data instanceof ExpenseReport);

        if ($data->getStatus() !== ExpenseReportStatus::Approved || $data->getLedgerEntry() !== null) {
            throw new ConflictHttpException('Note non approuvée, ou déjà déversée en comptabilité.');
        }

        $anomalies = $this->poster->poster($data);
        if ($anomalies !== []) {
            throw new UnprocessableEntityHttpException(implode(' ', $anomalies));
        }

        $this->em->flush();

        return $data;
    }
}
