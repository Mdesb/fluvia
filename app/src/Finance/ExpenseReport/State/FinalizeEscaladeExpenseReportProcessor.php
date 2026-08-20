<?php

declare(strict_types=1);

namespace App\Finance\ExpenseReport\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Finance\ExpenseReport\Entity\ExpenseReport;
use App\Finance\ExpenseReport\Service\EscaladeExpenseReportResolver;
use App\Securite\Entity\Utilisateur;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * POST /finance/expense-reports/{id}/finalize-escalade (§0.3.2 du plan) : confort UX (déclenchement
 * immédiat plutôt que d'attendre le prochain passage de la commande planifiée
 * `finance:expense-reports:resoudre-escalades`, qui reste la garantie de fond) — strictement le même
 * service `EscaladeExpenseReportResolver`, aucune logique dupliquée.
 *
 * @implements ProcessorInterface<ExpenseReport, ExpenseReport>
 */
final class FinalizeEscaladeExpenseReportProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EscaladeExpenseReportResolver $resolver,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ExpenseReport
    {
        \assert($data instanceof ExpenseReport);
        $acteur = $this->security->getUser();

        $this->resolver->resoudre($data, $acteur instanceof Utilisateur ? $acteur : null);

        return $data;
    }
}
