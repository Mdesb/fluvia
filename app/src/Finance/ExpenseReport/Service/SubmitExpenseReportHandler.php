<?php

declare(strict_types=1);

namespace App\Finance\ExpenseReport\Service;

use App\Autorisation\Enum\ResultatDecision;
use App\Autorisation\Service\RequeteAutorisation;
use App\Autorisation\Service\ServiceAutorisation;
use App\Finance\ExpenseReport\Entity\ExpenseLine;
use App\Finance\ExpenseReport\Entity\ExpenseReport;
use App\Finance\ExpenseReport\Enum\ExpenseReportStatus;
use App\Organisation\Entity\Etablissement;
use App\Personnel\Entity\Employe;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventActor;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Securite\Entity\Utilisateur;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Soumission d'une `ExpenseReport` (`draft -> submitted`, §0.3 du plan, RG-EXP-01.1/04) : point
 * d'intégration exact avec `App\Autorisation` — **jamais** `EscaladeRequiseException` (divergence
 * délibérée par rapport au seul autre consommateur, M2, §0.3.1) : une escalade requise laisse la note
 * `submitted`, ce n'est pas un échec de la requête.
 *
 * D6/D7 explicites : tenant dérivé de `ExpenseReport.establishment` (jamais du contexte HTTP),
 * `expense_report.submitted` émis **avant** l'appel à `ServiceAutorisation`, dans la **même**
 * transaction que la transition de statut (`wrapInTransaction()`, patron `SupplierInvoiceApprovalHandler`
 * FIN-2) — un abonné qui lève annule la soumission, aucune note orpheline.
 */
final class SubmitExpenseReportHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ServiceAutorisation $serviceAutorisation,
        private readonly ExpenseReportLedgerPoster $poster,
        private readonly EventBus $eventBus,
    ) {
    }

    public function soumettre(ExpenseReport $report, ?Utilisateur $acteur): ExpenseReport
    {
        if ($report->getStatus() !== ExpenseReportStatus::Draft) {
            throw new ConflictHttpException('Note de frais déjà soumise ou dans un état non soumissible.');
        }
        if ($report->getLines()->isEmpty()) {
            throw new UnprocessableEntityHttpException('Une note de frais sans ligne ne peut pas être soumise.');
        }

        // CA-1, RG-EXP-02.1 : justificatif obligatoire par ligne — bloquant à la soumission, jamais à
        // la création/édition en brouillon (RG-EXP-02).
        $anomalies = [];
        foreach ($report->getLines() as $ligne) {
            \assert($ligne instanceof ExpenseLine);
            $url = $ligne->getReceiptUrl();
            if ($url === null || trim($url) === '') {
                $anomalies[] = sprintf(
                    'Ligne « %s » : justificatif obligatoire avant soumission (RG-EXP-02.1, CA-1).',
                    $ligne->getDescription() ?? (string) $ligne->getId(),
                );
            }
        }
        if ($anomalies !== []) {
            throw new UnprocessableEntityHttpException(implode(' ', $anomalies));
        }

        $employe = $report->getEmployee();
        \assert($employe instanceof Employe);
        $utilisateurEmploye = $employe->getUtilisateur();
        if ($utilisateurEmploye === null) {
            // Garanti normalement par `EMPLOYE_SOI` à la création (§4.3 spec) — défense en profondeur :
            // un salarié sans compte Utilisateur ne peut techniquement pas être « soi-même » côté
            // sécurité, ce garde-fou ne devrait jamais se déclencher en usage normal.
            throw new ConflictHttpException('Ce salarié ne dispose pas de compte utilisateur : il ne peut pas soumettre lui-même une note de frais.');
        }

        $etablissement = $report->getEstablishment();
        \assert($etablissement instanceof Etablissement);

        /** @var ExpenseReport $resultat */
        $resultat = $this->em->wrapInTransaction(function () use ($report, $acteur, $utilisateurEmploye, $etablissement): ExpenseReport {
            $this->em->lock($report, LockMode::PESSIMISTIC_WRITE);
            if ($report->getStatus() !== ExpenseReportStatus::Draft) {
                throw new ConflictHttpException('Note de frais déjà soumise ou dans un état non soumissible.');
            }

            $report->setStatus(ExpenseReportStatus::Submitted);
            $report->setSubmittedAt(new \DateTimeImmutable());
            $this->em->flush();

            // RG-EXP-01.1 : émis AVANT l'appel à `ServiceAutorisation` — la transition fige les lignes,
            // émet `expense_report.submitted`, ET déclenche l'évaluation.
            $this->eventBus->publish(new DomainEvent(
                'expense_report.submitted',
                new EventTenant($etablissement->getId()),
                new EventSubject('ExpenseReport', (string) $report->getId()),
                [
                    'employeeId' => (string) $report->getEmployee()?->getId(),
                    'amountCents' => $this->centimes($report->getTotalAmount()),
                ],
                $acteur instanceof Utilisateur ? new EventActor($acteur->getId()) : null,
            ));

            $decision = $this->serviceAutorisation->evaluer(new RequeteAutorisation(
                operationCode: 'finance.expense_report_approve',
                utilisateur: $utilisateurEmploye,
                montant: $report->getTotalAmount(),
                cibleType: 'ExpenseReport',
                cibleId: $report->getId(),
                cibleEtablissementId: $etablissement->getId(),
            ));

            match ($decision->resultat) {
                ResultatDecision::Autorise => $this->marquerApprouve($report, $acteur, null),
                ResultatDecision::EscaladeRequise => $report->setEscalationRequest($decision->demandeEscalade),
                ResultatDecision::Refuse => $report->setStatus(ExpenseReportStatus::Rejected)->setRejectionReason($decision->motif),
            };

            $this->em->flush();

            return $report;
        });

        return $resultat;
    }

    /**
     * Effets « note approuvée » (branche `Autorise` de la soumission directe, §0.3, **ou** branche
     * `Approuvee` après escalade, `EscaladeExpenseReportResolver`, §0.3.2) — service partagé, aucune
     * logique dupliquée. Ne gère ni transaction ni `flush()` : l'appelant porte les deux (patron
     * constant du dépôt).
     *
     * `$acteur` = qui a déclenché la transition (EventActor, peut être le salarié ou une commande CLI).
     * `$approbateur` = qui a réellement autorisé (payload `approverId`, §0.7) : **`null` en auto-approbation
     * sous plafond** (aucun humain n'a validé), **le superviseur** en sortie d'escalade — jamais l'appelant
     * HTTP/CLI, sous peine de trace d'audit trompeuse (RG-AUTZ-13 interdit l'auto-approbation).
     */
    public function marquerApprouve(ExpenseReport $report, ?Utilisateur $acteur, ?Utilisateur $approbateur): void
    {
        $report->setStatus(ExpenseReportStatus::Approved);
        $report->setApprovedAt(new \DateTimeImmutable());

        // RG-EXP-06/CA-5 : le déversement peut échouer (mapping incomplet) sans annuler l'approbation
        // métier déjà actée par `App\Autorisation` (§0.5) — anomalies ignorées ici volontairement,
        // rejouable via `POST .../post-to-ledger`.
        $this->poster->poster($report);

        $etablissement = $report->getEstablishment();
        \assert($etablissement instanceof Etablissement);

        $this->eventBus->publish(new DomainEvent(
            'expense_report.approved',
            new EventTenant($etablissement->getId()),
            new EventSubject('ExpenseReport', (string) $report->getId()),
            [
                'amountCents' => $this->centimes($report->getTotalAmount()),
                'approverId' => $approbateur instanceof Utilisateur ? (string) $approbateur->getId() : null,
            ],
            $acteur instanceof Utilisateur ? new EventActor($acteur->getId()) : null,
        ));
    }

    private function centimes(string $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }
}
