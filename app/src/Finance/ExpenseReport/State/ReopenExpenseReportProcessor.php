<?php

declare(strict_types=1);

namespace App\Finance\ExpenseReport\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Finance\ExpenseReport\Entity\ExpenseReport;
use App\Finance\ExpenseReport\Enum\ExpenseReportStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * POST /finance/expense-reports/{id}/reopen (§0.9 du plan, RG-EXP-07, CA-6) : `status` doit être
 * `rejected` (409 sinon) -> `status = draft`, `rejectionReason = null`, `escalationRequest = null`
 * (la référence à l'ancienne `DemandeEscalade` est **abandonnée**, pas réutilisée — une nouvelle
 * soumission déclenche un cycle complet neuf, sans logique dédiée « ne pas hériter »).
 *
 * @implements ProcessorInterface<ExpenseReport, ExpenseReport>
 */
final class ReopenExpenseReportProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ExpenseReport
    {
        \assert($data instanceof ExpenseReport);

        if ($data->getStatus() !== ExpenseReportStatus::Rejected) {
            throw new ConflictHttpException('Seule une note refusée peut être réouverte (RG-EXP-07).');
        }

        $data->setStatus(ExpenseReportStatus::Draft);
        $data->setRejectionReason(null);
        $data->setEscalationRequest(null);

        $this->em->flush();

        return $data;
    }
}
