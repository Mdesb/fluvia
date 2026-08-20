<?php

declare(strict_types=1);

namespace App\Finance\ExpenseReport\Service;

use App\Compta\Dto\DirectLedgerEntryLine;
use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Compta\Service\DirectLedgerEntryBuilder;
use App\Compta\Service\ExpenseAccountMappingGuard;
use App\Compta\Service\PeriodeComptableResolver;
use App\Finance\ExpenseReport\Entity\ExpenseLine;
use App\Finance\ExpenseReport\Entity\ExpenseReport;
use App\Personnel\Entity\Employe;

/**
 * Déversement comptable d'une `ExpenseReport` approuvée (RG-EXP-06, §0.5 du plan) — **découplé** de
 * l'approbation métier (déjà actée par `App\Autorisation`) : un mapping de charge incomplet bloque le
 * déversement, jamais l'approbation. `poster()` ne lève **jamais** d'exception (même contrat de
 * dégradation propre que `ExpenseAccountMappingGuard::anomalies()`) : liste d'anomalies, vide = succès.
 *
 * Aucun `flush()` ici (patron constant du dépôt) : l'appelant (`SubmitExpenseReportHandler`,
 * `EscaladeExpenseReportResolver`, `PostToLedgerExpenseReportProcessor`) porte la transaction/le
 * `flush()`.
 */
final class ExpenseReportLedgerPoster
{
    public function __construct(
        private readonly ResolveurComptesExpenseReport $comptes,
        private readonly ExpenseAccountMappingGuard $mappingGuard,
        private readonly PeriodeComptableResolver $periodes,
        private readonly DirectLedgerEntryBuilder $builder,
    ) {
    }

    /**
     * @return list<string> anomalies (vide = succès, écriture construite et affectée à `$report`)
     */
    public function poster(ExpenseReport $report): array
    {
        $profil = $report->getBusinessProfile();
        \assert($profil instanceof ProfilExploitant);

        // Tout ou rien (§0.5 point 2 du plan) : un seul mapping introuvable/inactif -> anomalies non
        // vides, aucune écriture construite, retour anticipé.
        $anomalies = [];
        $compteParLigne = [];
        foreach ($report->getLines() as $ligne) {
            \assert($ligne instanceof ExpenseLine);
            $mapping = $this->mappingGuard->resoudre($profil, $ligne->getExpenseNatureCode());
            if ($mapping === null) {
                $anomalies[] = sprintf(
                    'Ligne « %s » : mapping de charge incomplet pour la nature « %s » (RG-EXP-06, CA-5).',
                    $ligne->getDescription() ?? (string) $ligne->getId(),
                    $ligne->getExpenseNatureCode(),
                );

                continue;
            }
            $compteParLigne[(string) $ligne->getId()] = $mapping->getExpenseAccount();
        }
        if ($anomalies !== []) {
            return $anomalies;
        }

        $compteEmploye = $this->comptes->compteEmploye($profil);
        $compteTvaDeductible = $this->comptes->compteTvaDeductible($profil);
        $journal = $this->comptes->journalNotesDeFrais($profil);
        $tauxHorsChamp = $this->comptes->tauxHorsChamp($profil);

        $date = new \DateTimeImmutable();
        $periode = $this->periodes->resoudreOuCreer($profil, $date);

        [$lignesDto, $totalTtcCentimes] = $this->construireLignes($report, $compteEmploye, $compteTvaDeductible, $compteParLigne, $tauxHorsChamp);

        $employe = $report->getEmployee();
        \assert($employe instanceof Employe);
        $libelle = sprintf('Note de frais — %s %s', $employe->getPrenom(), $employe->getNom());

        $ecriture = $this->builder->construire($profil, $journal, $periode, $date, $libelle, $lignesDto);
        $report->setLedgerEntry($ecriture);

        return [];
    }

    /**
     * @param array<string, CompteComptable> $compteParLigne
     *
     * @return array{0: list<DirectLedgerEntryLine>, 1: int}
     */
    private function construireLignes(ExpenseReport $report, CompteComptable $compteEmploye, CompteComptable $compteTvaDeductible, array $compteParLigne, TauxTva $tauxHorsChamp): array
    {
        /** @var array<string, array{compte: CompteComptable, montant: int, taux: TauxTva}> $parCompteCharge */
        $parCompteCharge = [];
        /** @var array<string, array{taux: TauxTva, montant: int}> $parTauxTva */
        $parTauxTva = [];
        $totalTtcCentimes = 0;

        foreach ($report->getLines() as $ligne) {
            \assert($ligne instanceof ExpenseLine);
            $compteCharge = $compteParLigne[(string) $ligne->getId()];
            // §0.5 point 3 : `vatRate` absent -> aucune TVA « empruntée » au mapping, la totalité TTC
            // débite le compte de charge — le taux « hors champ » ne sert ici qu'à qualifier la ligne
            // (aucun montant de TVA associé).
            $taux = $ligne->getVatRate() ?? $tauxHorsChamp;

            $ttcCentimes = $this->centimes($ligne->getAmountInclTax());
            $htCentimes = $this->centimes($ligne->getAmountExclTax());
            $tvaCentimes = $this->centimes($ligne->getVatAmount());
            $totalTtcCentimes += $ttcCentimes;

            $clefCompte = (string) $compteCharge->getId();
            if (!isset($parCompteCharge[$clefCompte])) {
                $parCompteCharge[$clefCompte] = ['compte' => $compteCharge, 'montant' => 0, 'taux' => $taux];
            }
            $parCompteCharge[$clefCompte]['montant'] += $htCentimes;

            if ($tvaCentimes > 0) {
                $clefTaux = (string) $taux->getId();
                if (!isset($parTauxTva[$clefTaux])) {
                    $parTauxTva[$clefTaux] = ['taux' => $taux, 'montant' => 0];
                }
                $parTauxTva[$clefTaux]['montant'] += $tvaCentimes;
            }
        }

        $lignesDto = [];
        foreach ($parCompteCharge as $entree) {
            $lignesDto[] = new DirectLedgerEntryLine(
                compte: $entree['compte'],
                debitCentimes: $entree['montant'],
                creditCentimes: 0,
                tauxTva: $entree['taux'],
                libelle: 'Charge — note de frais',
            );
        }
        foreach ($parTauxTva as $entree) {
            $lignesDto[] = new DirectLedgerEntryLine(
                compte: $compteTvaDeductible,
                debitCentimes: $entree['montant'],
                creditCentimes: 0,
                tauxTva: $entree['taux'],
                libelle: 'TVA déductible — note de frais',
            );
        }

        $employe = $report->getEmployee();
        $libelleEmploye = $employe instanceof Employe ? ($employe->getPrenom() . ' ' . $employe->getNom()) : null;
        $lignesDto[] = new DirectLedgerEntryLine(
            compte: $compteEmploye,
            debitCentimes: 0,
            creditCentimes: $totalTtcCentimes,
            tauxTva: $tauxHorsChamp,
            libelle: 'Note de frais — ' . ($libelleEmploye ?? ''),
            counterpartyType: 'personnel_employe',
            counterpartyId: $employe?->getId(),
            counterpartyLabel: $libelleEmploye,
        );

        return [$lignesDto, $totalTtcCentimes];
    }

    private function centimes(string $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }
}
