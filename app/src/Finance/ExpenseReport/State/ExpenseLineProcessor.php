<?php

declare(strict_types=1);

namespace App\Finance\ExpenseReport\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Finance\ExpenseReport\Entity\ExpenseLine;
use App\Finance\ExpenseReport\Entity\ExpenseReport;
use App\Finance\ExpenseReport\Enum\ExpenseReportStatus;
use App\Personnel\Security\EmployeSoiVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST/PATCH/DELETE `/expense_lines` (§2/§3 du plan, D8 explicite) : la note de frais référencée doit
 * appartenir au salarié courant (`EMPLOYE_SOI` sur `expenseReport.getEmployee()`, revérifié
 * explicitement — jamais délégué à une extension Doctrine, D8) **et** être encore `draft` (409 sinon,
 * RG-EXP-01.1 « la transition fige les lignes »). Recalcule `ExpenseReport.totalAmount` après chaque
 * écriture (jamais fourni par le client).
 *
 * @implements ProcessorInterface<ExpenseLine, ExpenseLine|null>
 */
final class ExpenseLineProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<ExpenseLine, null> $removeProcessor
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        #[Autowire(service: 'api_platform.doctrine.orm.state.remove_processor')]
        private readonly ProcessorInterface $removeProcessor,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        \assert($data instanceof ExpenseLine);

        $report = $data->getExpenseReport();
        if ($report === null) {
            throw new UnprocessableEntityHttpException('La ligne doit référencer une note de frais.');
        }

        $employe = $report->getEmployee();
        // D8 : échec fermé — un employé qui n'est pas le salarié courant ne doit même pas apprendre
        // que la note existe (404, même patron que `SupplierInvoiceLineProcessor`).
        if ($employe === null || !$this->security->isGranted(EmployeSoiVoter::ATTRIBUTE, $employe)) {
            throw new NotFoundHttpException('Note de frais introuvable.');
        }

        if ($report->getStatus() !== ExpenseReportStatus::Draft) {
            throw new ConflictHttpException('Note de frais scellée : plus aucune ligne modifiable (RG-EXP-01.1).');
        }

        if ($operation instanceof DeleteOperationInterface) {
            $resultat = $this->removeProcessor->process($data, $operation, $uriVariables, $context);
            $this->recalculerTotal($report);

            return $resultat;
        }

        $data->recalculer();
        $this->em->persist($data);
        $this->em->flush();

        $this->recalculerTotal($report);

        return $data;
    }

    private function recalculerTotal(ExpenseReport $report): void
    {
        /** @var list<ExpenseLine> $lignes */
        $lignes = $this->em->getRepository(ExpenseLine::class)->findBy(['expenseReport' => $report->getId()]);

        $total = 0.0;
        foreach ($lignes as $ligne) {
            $total += (float) $ligne->getAmountInclTax();
        }

        $report->setTotalAmount(number_format(round($total, 2), 2, '.', ''));
        $this->em->flush();
    }
}
