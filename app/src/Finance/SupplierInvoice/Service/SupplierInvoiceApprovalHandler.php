<?php

declare(strict_types=1);

namespace App\Finance\SupplierInvoice\Service;

use App\Compta\Dto\DirectLedgerEntryLine;
use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Compta\Service\DirectLedgerEntryBuilder;
use App\Compta\Service\ExpenseAccountMappingGuard;
use App\Compta\Service\PeriodeComptableResolver;
use App\Finance\SupplierInvoice\Entity\SupplierInvoice;
use App\Finance\SupplierInvoice\Entity\SupplierInvoiceLine;
use App\Finance\SupplierInvoice\Enum\SupplierInvoiceStatus;
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
 * Validation d'une facture fournisseur (`draft -> to_pay`, RG-SINV-05) : **le fait générateur
 * comptable** (§0.7 du plan). Même patron que `App\Facturation\Service\EmettreFactureDirecteHandler` :
 * résolution des comptes/mapping **avant** la transaction (fail-fast), verrou pessimiste posé en tout
 * premier dans la transaction, garde revérifiée après verrou (idempotence, CA-4).
 */
final class SupplierInvoiceApprovalHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ResolveurComptesSupplierInvoice $comptes,
        private readonly ExpenseAccountMappingGuard $mappingGuard,
        private readonly PeriodeComptableResolver $periodes,
        private readonly DirectLedgerEntryBuilder $builder,
        private readonly EventBus $eventBus,
    ) {
    }

    public function approuver(SupplierInvoice $facture, ?Utilisateur $acteur): SupplierInvoice
    {
        if ($facture->getStatus() !== SupplierInvoiceStatus::Draft || $facture->getLedgerEntry() !== null) {
            throw new ConflictHttpException('Facture déjà validée ou dans un état non validable (RG-SINV-05, CA-4).');
        }
        if ($facture->getLines()->isEmpty()) {
            throw new UnprocessableEntityHttpException('Une facture sans ligne ne peut pas être validée.');
        }

        $profil = $facture->getBusinessProfile();
        \assert($profil instanceof ProfilExploitant);

        // Résolution AVANT toute écriture (fail-fast, §0.7 point 1) : compte fournisseur, TVA
        // déductible, journal, ET mapping de charge par ligne (CA-5) — un mapping incomplet bloque
        // la validation, la facture reste `draft`, aucune écriture n'est même tentée.
        $compteFournisseur = $this->comptes->compteFournisseur($profil);
        $compteTvaDeductible = $this->comptes->compteTvaDeductible($profil);
        $journal = $this->comptes->journalAchats($profil);

        $anomalies = [];
        $compteParLigne = [];
        foreach ($facture->getLines() as $ligne) {
            $mapping = $this->mappingGuard->resoudre($profil, $ligne->getExpenseNatureCode());
            if ($mapping === null) {
                $anomalies[] = sprintf('Ligne « %s » : mapping de charge incomplet pour la nature « %s » (CA-5).', $ligne->getDescription(), $ligne->getExpenseNatureCode());

                continue;
            }
            $compteParLigne[(string) $ligne->getId()] = $mapping->getExpenseAccount();
        }
        if ($anomalies !== []) {
            throw new UnprocessableEntityHttpException(implode(' ', $anomalies));
        }

        /** @var SupplierInvoice $resultat */
        $resultat = $this->em->wrapInTransaction(function () use ($facture, $profil, $compteFournisseur, $compteTvaDeductible, $journal, $compteParLigne, $acteur): SupplierInvoice {
            $this->em->lock($facture, LockMode::PESSIMISTIC_WRITE);
            if ($facture->getStatus() !== SupplierInvoiceStatus::Draft || $facture->getLedgerEntry() !== null) {
                throw new ConflictHttpException('Facture déjà validée ou dans un état non validable (RG-SINV-05, CA-4).');
            }

            $date = new \DateTimeImmutable();
            $periode = $this->periodes->resoudreOuCreer($profil, $date);

            [$lignesDto, $totalTtcCentimes, $premierTaux] = $this->construireLignes($facture, $compteFournisseur, $compteTvaDeductible, $compteParLigne);

            $libelle = sprintf('Facture fournisseur %s — %s', $facture->getSupplierInvoiceNumber(), $facture->getSupplier()?->getRaisonSociale());
            $ecriture = $this->builder->construire($profil, $journal, $periode, $date, $libelle, $lignesDto);

            $facture->setLedgerEntry($ecriture);
            $facture->setStatus(SupplierInvoiceStatus::ToPay);
            $this->em->flush();

            $this->eventBus->publish(new DomainEvent(
                'supplier_invoice.approved',
                new EventTenant($facture->getEtablissement()->getId()),
                new EventSubject('SupplierInvoice', (string) $facture->getId()),
                [
                    'amountInclTaxCents' => $totalTtcCentimes,
                    'dueDate' => $facture->getDueDate()?->format('Y-m-d'),
                ],
                $acteur instanceof Utilisateur ? new EventActor($acteur->getId()) : null,
            ));

            return $facture;
        });

        return $resultat;
    }

    /**
     * @param array<string, CompteComptable> $compteParLigne
     *
     * @return array{0: list<DirectLedgerEntryLine>, 1: int, 2: ?TauxTva}
     */
    private function construireLignes(SupplierInvoice $facture, CompteComptable $compteFournisseur, CompteComptable $compteTvaDeductible, array $compteParLigne): array
    {
        /** @var array<string, array{compte: CompteComptable, montant: int, taux: TauxTva}> $parCompteCharge */
        $parCompteCharge = [];
        /** @var array<string, array{taux: TauxTva, montant: int}> $parTauxTva */
        $parTauxTva = [];
        $totalTtcCentimes = 0;
        $premierTaux = null;

        foreach ($facture->getLines() as $ligne) {
            \assert($ligne instanceof SupplierInvoiceLine);
            $compteCharge = $compteParLigne[(string) $ligne->getId()];
            $taux = $ligne->getVatRate();
            \assert($taux instanceof TauxTva);
            $premierTaux ??= $taux;

            $htCentimes = $this->centimes($ligne->getAmountExclTax());
            $tvaCentimes = $this->centimes($ligne->getVatAmount());
            $ttcCentimes = $this->centimes($ligne->getAmountInclTax());
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
                libelle: 'Charge — facture fournisseur ' . $facture->getSupplierInvoiceNumber(),
            );
        }
        foreach ($parTauxTva as $entree) {
            $lignesDto[] = new DirectLedgerEntryLine(
                compte: $compteTvaDeductible,
                debitCentimes: $entree['montant'],
                creditCentimes: 0,
                tauxTva: $entree['taux'],
                libelle: 'TVA déductible — facture fournisseur ' . $facture->getSupplierInvoiceNumber(),
            );
        }

        $fournisseur = $facture->getSupplier();
        $lignesDto[] = new DirectLedgerEntryLine(
            compte: $compteFournisseur,
            debitCentimes: 0,
            creditCentimes: $totalTtcCentimes,
            tauxTva: $premierTaux,
            libelle: 'Facture fournisseur ' . $facture->getSupplierInvoiceNumber(),
            counterpartyType: 'stock_fournisseur',
            counterpartyId: $fournisseur?->getId(),
            counterpartyLabel: $fournisseur?->getRaisonSociale(),
        );

        return [$lignesDto, $totalTtcCentimes, $premierTaux];
    }

    private function centimes(string $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }
}
