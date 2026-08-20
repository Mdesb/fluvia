<?php

declare(strict_types=1);

namespace App\Finance\ExpenseReport\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Finance\ExpenseReport\Entity\ExpenseReport;
use App\Finance\ExpenseReport\Service\SubmitExpenseReportHandler;
use App\Securite\Entity\Utilisateur;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * POST /finance/expense-reports/{id}/submit — `read: true` : `$data` déjà résolu et filtré par
 * `PerimetreFinanceExtension` via le provider d'item standard (§0.2 point 1 du plan).
 *
 * @implements ProcessorInterface<ExpenseReport, ExpenseReport>
 */
final class SubmitExpenseReportProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly SubmitExpenseReportHandler $handler,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ExpenseReport
    {
        \assert($data instanceof ExpenseReport);
        $acteur = $this->security->getUser();

        return $this->handler->soumettre($data, $acteur instanceof Utilisateur ? $acteur : null);
    }
}
