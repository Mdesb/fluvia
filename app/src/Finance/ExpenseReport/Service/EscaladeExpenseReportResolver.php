<?php

declare(strict_types=1);

namespace App\Finance\ExpenseReport\Service;

use App\Autorisation\Entity\DemandeEscalade;
use App\Autorisation\Enum\ResultatDecision;
use App\Autorisation\Enum\StatutEscalade;
use App\Autorisation\Service\RequeteAutorisation;
use App\Autorisation\Service\ServiceAutorisation;
use App\Finance\ExpenseReport\Entity\ExpenseReport;
use App\Finance\ExpenseReport\Enum\ExpenseReportStatus;
use App\Organisation\Entity\Etablissement;
use App\Personnel\Entity\Employe;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finalisation d'une `ExpenseReport` après décision du superviseur sur son `escalationRequest`
 * (§0.3.2 du plan, le point délicat de ce lot) — `ServiceAutorisation::evaluerRejeu()` est conçue pour
 * un rejeu **côté client** (le seul patron documenté par `App\Autorisation`, M2) ; ce resolver en est
 * le **premier consommateur non-HTTP** (commande planifiée + endpoint de confort), un point
 * d'intégration à confirmer avec le propriétaire du module `App\Autorisation` avant merge (§7 point 1
 * du plan — signalé à l'intégrateur, pas un blocage technique : la branche rejeu ne dépend ni de
 * `Security::isGranted()` ni de `ContexteEtablissement`, vérifié dans le code).
 *
 * Deux déclencheurs, même service, aucune logique dupliquée : la commande
 * `finance:expense-reports:resoudre-escalades` (garantie de fond) et l'endpoint
 * `POST .../finalize-escalade` (confort UX).
 */
final class EscaladeExpenseReportResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ServiceAutorisation $serviceAutorisation,
        private readonly SubmitExpenseReportHandler $submitHandler,
    ) {
    }

    public function resoudre(ExpenseReport $report, ?Utilisateur $acteur): void
    {
        $demande = $report->getEscalationRequest();
        if ($demande === null || $report->getStatus() !== ExpenseReportStatus::Submitted) {
            // No-op — idempotent par construction (§0.3.2) : une note déjà finalisée (approved/rejected)
            // ne repasse jamais par cette branche, quel que soit le nombre d'appels ultérieurs (jeton
            // déjà consommé, `dateRejeu` déjà posé côté `App\Autorisation`).
            return;
        }

        $employe = $report->getEmployee();
        \assert($employe instanceof Employe);
        $utilisateurEmploye = $employe->getUtilisateur();
        \assert($utilisateurEmploye instanceof Utilisateur);

        $etablissement = $report->getEstablishment();
        \assert($etablissement instanceof Etablissement);

        $this->em->wrapInTransaction(function () use ($report, $acteur, $demande, $utilisateurEmploye, $etablissement): void {
            match ($demande->getStatut()) {
                StatutEscalade::Approuvee => $this->finaliserApprobation($report, $acteur, $demande, $utilisateurEmploye, $etablissement),
                StatutEscalade::Rejetee => $report->setStatus(ExpenseReportStatus::Rejected)->setRejectionReason($demande->getMotifRejet()),
                // ⚠ HYPOTHÈSE (§7 point 9 du plan, non couverte littéralement par la spec) : une
                // escalade expirée sans décision du superviseur est traitée comme un rejet.
                StatutEscalade::Expiree => $report->setStatus(ExpenseReportStatus::Rejected)->setRejectionReason('Escalade expirée sans décision du superviseur.'),
                StatutEscalade::EnAttente => null,
            };

            $this->em->flush();
        });
    }

    private function finaliserApprobation(ExpenseReport $report, ?Utilisateur $acteur, DemandeEscalade $demande, Utilisateur $utilisateurEmploye, Etablissement $etablissement): void
    {
        $decision = $this->serviceAutorisation->evaluer(new RequeteAutorisation(
            operationCode: 'finance.expense_report_approve',
            utilisateur: $utilisateurEmploye,
            montant: $report->getTotalAmount(),
            cibleType: 'ExpenseReport',
            cibleId: $report->getId(),
            cibleEtablissementId: $etablissement->getId(),
            jetonRejeu: $demande->getJeton(),
        ));

        if ($decision->resultat !== ResultatDecision::Autorise) {
            // Ne devrait pas se produire (§0.3.2) : la demande est déjà « approuvée » côté
            // `App\Autorisation` au moment où ce service est invoqué. Défense en profondeur — aucune
            // transition, la note reste `submitted`.
            return;
        }

        $this->submitHandler->marquerApprouve($report, $acteur);
    }
}
