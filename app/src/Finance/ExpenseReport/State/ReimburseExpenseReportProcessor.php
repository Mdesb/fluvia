<?php

declare(strict_types=1);

namespace App\Finance\ExpenseReport\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\Entity\MoyenPaiement;
use App\Finance\ExpenseReport\Entity\ExpenseReport;
use App\Finance\ExpenseReport\Entity\Reimbursement;
use App\Finance\ExpenseReport\Service\ReimburseExpenseReportHandler;
use App\Securite\Entity\Utilisateur;
use App\Stock\Security\PerimetreEtablissementVerificateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /finance/expense-reports/{id}/reimbursements (§0.6 du plan, D8 explicite) : `{id}` désigne la
 * note de frais parente (résolue manuellement depuis `uriVariables`, `read: false` — même patron que
 * `App\Support\State\MessageTicketProcessor`), revérifiée dans le périmètre serveur
 * (`PerimetreEtablissementVerificateur`, D8) avant tout traitement.
 *
 * @implements ProcessorInterface<Reimbursement, Reimbursement>
 */
final class ReimburseExpenseReportProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PerimetreEtablissementVerificateur $perimetre,
        private readonly ReimburseExpenseReportHandler $handler,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Reimbursement
    {
        \assert($data instanceof Reimbursement);

        $reportId = $uriVariables['id'] ?? null;
        $report = (\is_string($reportId) || $reportId instanceof \Stringable)
            ? $this->em->getRepository(ExpenseReport::class)->find((string) $reportId)
            : null;
        if (!$report instanceof ExpenseReport) {
            throw new NotFoundHttpException('Note de frais introuvable.');
        }

        // D8 : revérification explicite du périmètre serveur (résolution manuelle par id d'URL).
        $this->perimetre->verifier($report->getEstablishment());

        $moyenPaiement = $data->getMethod();
        if (!$moyenPaiement instanceof MoyenPaiement) {
            throw new UnprocessableEntityHttpException('Le moyen de paiement est obligatoire.');
        }

        $date = $data->getDate() ?? new \DateTimeImmutable();
        $acteur = $this->security->getUser();

        return $this->handler->rembourser(
            $report,
            $date,
            $data->getAmount(),
            $moyenPaiement,
            $data->getReference(),
            $acteur instanceof Utilisateur ? $acteur : null,
        );
    }
}
