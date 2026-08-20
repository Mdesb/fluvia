<?php

declare(strict_types=1);

namespace App\Finance\ExpenseReport\Service;

use App\Compta\Dto\DirectLedgerEntryLine;
use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\LigneEcriture;
use App\Compta\Entity\MoyenPaiement;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Service\DirectLedgerEntryBuilder;
use App\Compta\Service\LettrageHandler;
use App\Compta\Service\PeriodeComptableResolver;
use App\Finance\ExpenseReport\Entity\ExpenseReport;
use App\Finance\ExpenseReport\Entity\Reimbursement;
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
 * Remboursement d'une `ExpenseReport` (RG-EXP-05, §0.6 du plan) — lettrage groupé à deux lignes, un
 * seul coup (pas de remboursement partiel v1, contrairement à `SupplierPaymentHandler` FIN-2) :
 * `Reimbursement.amount` doit égaler **exactement** `report.totalAmount`. Précondition mécanique
 * (§7 point 8 du plan, non énoncée littéralement par la spec) : `status === Approved &&
 * ledgerEntry !== null` — un remboursement ne peut pas se lettrer contre une ligne 421 qui n'existe
 * pas encore.
 */
final class ReimburseExpenseReportHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ResolveurComptesExpenseReport $comptes,
        private readonly PeriodeComptableResolver $periodes,
        private readonly DirectLedgerEntryBuilder $builder,
        private readonly LettrageHandler $lettrage,
        private readonly EventBus $eventBus,
    ) {
    }

    public function rembourser(ExpenseReport $report, \DateTimeImmutable $date, string $montantDecimal, MoyenPaiement $moyenPaiement, ?string $reference, ?Utilisateur $acteur): Reimbursement
    {
        $montantCentimes = $this->centimes($montantDecimal);
        if ($montantCentimes <= 0) {
            throw new UnprocessableEntityHttpException('Le montant du remboursement doit être strictement positif.');
        }

        /** @var Reimbursement $resultat */
        $resultat = $this->em->wrapInTransaction(function () use ($report, $date, $montantCentimes, $moyenPaiement, $reference, $acteur): Reimbursement {
            // Verrou pessimiste en premier (même patron que `SupplierPaymentHandler`) : ferme la course
            // entre deux tentatives concurrentes de remboursement sur la même note.
            $this->em->lock($report, LockMode::PESSIMISTIC_WRITE);

            if ($report->getStatus() !== ExpenseReportStatus::Approved) {
                throw new ConflictHttpException("Cette note de frais n'est pas approuvée : aucun remboursement possible.");
            }
            $ecritureReport = $report->getLedgerEntry();
            if ($ecritureReport === null) {
                throw new ConflictHttpException("Cette note de frais n'est pas encore déversée en comptabilité : aucun remboursement possible (§0.6 du plan).");
            }

            $existant = $this->em->getRepository(Reimbursement::class)->findOneBy(['expenseReport' => $report->getId()]);
            if ($existant !== null) {
                throw new ConflictHttpException('Cette note de frais a déjà un remboursement enregistré (RG-EXP-05, un seul remboursement v1).');
            }

            $totalCentimes = $this->centimes($report->getTotalAmount());
            if ($montantCentimes !== $totalCentimes) {
                throw new UnprocessableEntityHttpException('Le remboursement doit égaler exactement le montant total de la note (RG-EXP-05, pas de remboursement partiel v1).');
            }

            $profil = $report->getBusinessProfile();
            \assert($profil instanceof ProfilExploitant);

            $ligne421Report = $this->ligne421($ecritureReport);
            $employe = $report->getEmployee();
            $libelleEmploye = $employe instanceof Employe ? ($employe->getPrenom() . ' ' . $employe->getNom()) : null;

            $compteEmploye = $this->comptes->compteEmploye($profil);
            $compteTresorerie = $this->comptes->compteTresorerie($profil);
            $journal = $this->comptes->journalReglements($profil);
            $periode = $this->periodes->resoudreOuCreer($profil, $date);

            $lignesDto = [
                new DirectLedgerEntryLine(
                    compte: $compteEmploye,
                    debitCentimes: $montantCentimes,
                    creditCentimes: 0,
                    tauxTva: $ligne421Report->getTauxTva(),
                    libelle: 'Remboursement note de frais',
                    counterpartyType: 'personnel_employe',
                    counterpartyId: $employe?->getId(),
                    counterpartyLabel: $libelleEmploye,
                ),
                new DirectLedgerEntryLine(
                    compte: $compteTresorerie,
                    debitCentimes: 0,
                    creditCentimes: $montantCentimes,
                    tauxTva: $ligne421Report->getTauxTva(),
                    libelle: 'Remboursement note de frais',
                ),
            ];

            $ecritureRemboursement = $this->builder->construire($profil, $journal, $periode, $date, 'Remboursement note de frais', $lignesDto);
            $ligne421Remboursement = $this->ligne421($ecritureRemboursement);

            $remboursement = new Reimbursement();
            $remboursement->setExpenseReport($report);
            $remboursement->setDate($date);
            $remboursement->setAmount(number_format($montantCentimes / 100, 2, '.', ''));
            $remboursement->setMethod($moyenPaiement);
            $remboursement->setReference($reference);
            $remboursement->setLedgerEntry($ecritureRemboursement);
            $remboursement->setCreatedBy($acteur);
            $this->em->persist($remboursement);

            // Exactement 2 lignes, montants strictement égaux par construction (invariant
            // `lettrerGroupe()` trivialement respecté, §0.6 du plan).
            $lettrages = $this->lettrage->lettrerGroupe([$ligne421Report, $ligne421Remboursement], $acteur ?? $this->utilisateurSysteme($report), $date);
            $remboursement->setReconciliationCode($lettrages[0]->getReconciliationCode());

            $report->setStatus(ExpenseReportStatus::Reimbursed);
            $report->setReimbursedAt($date);

            $this->em->flush();

            $etablissement = $report->getEstablishment();
            \assert($etablissement instanceof Etablissement);

            $this->eventBus->publish(new DomainEvent(
                'expense_report.reimbursed',
                new EventTenant($etablissement->getId()),
                new EventSubject('ExpenseReport', (string) $report->getId()),
                [
                    'amountCents' => $montantCentimes,
                    'date' => $date->format('Y-m-d'),
                ],
                $acteur instanceof Utilisateur ? new EventActor($acteur->getId()) : null,
            ));

            return $remboursement;
        });

        return $resultat;
    }

    private function ligne421(EcritureComptable $ecriture): LigneEcriture
    {
        foreach ($ecriture->getLignes() as $ligne) {
            if ($ligne->getCounterpartyType() === 'personnel_employe') {
                return $ligne;
            }
        }

        throw new \LogicException('Écriture sans ligne de compte salarié (421) — invariant violé (RG-M6-13).');
    }

    private function utilisateurSysteme(ExpenseReport $report): Utilisateur
    {
        // `lettrerGroupe()` exige un auteur non-nul (traçabilité RG-M6-14) : à défaut d'acteur HTTP
        // authentifié, on retombe sur le créateur de la note plutôt que de bloquer le lettrage (même
        // patron que `SupplierPaymentHandler`).
        $createur = $report->getCreatedBy();
        \assert($createur instanceof Utilisateur);

        return $createur;
    }

    private function centimes(string $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }
}
