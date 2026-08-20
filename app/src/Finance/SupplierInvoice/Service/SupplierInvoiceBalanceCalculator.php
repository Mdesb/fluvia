<?php

declare(strict_types=1);

namespace App\Finance\SupplierInvoice\Service;

use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\LigneEcriture;
use App\Finance\SupplierInvoice\Entity\SupplierInvoice;
use App\Finance\SupplierInvoice\Entity\SupplierPayment;
use App\Finance\SupplierInvoice\Enum\SupplierInvoiceNature;
use App\Finance\SupplierInvoice\Enum\SupplierInvoiceStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Calcule le solde restant dû d'une facture fournisseur validée (§0.8 du plan) — factorisé pour être
 * réutilisé identiquement par `SupplierPaymentHandler` (enregistrement d'un règlement) et
 * `SupplierInvoiceDisputeHandler` (clôture d'un litige, retour au statut payable adéquat).
 *
 * Correctif revue de cohérence (défaut 5) : `soldeCentimes()` fait désormais participer les avoirs déjà
 * émis (`SupplierCreditNoteHandler`, RG-SINV-09) — sans quoi un règlement pouvait dépasser
 * (facture − avoirs), aboutissant à un trop-payé silencieux.
 */
final class SupplierInvoiceBalanceCalculator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** La ligne d'écriture « compte fournisseur » (401) portée par l'écriture donnée (facture ou avoir). */
    public function ligne401(EcritureComptable $ecriture): LigneEcriture
    {
        foreach ($ecriture->getLignes() as $ligne) {
            if ($ligne->getCounterpartyType() === 'stock_fournisseur') {
                return $ligne;
            }
        }

        throw new \LogicException('Écriture sans ligne de compte fournisseur (401) — invariant violé (RG-M6-13).');
    }

    /** @return list<SupplierPayment> */
    public function reglements(SupplierInvoice $facture): array
    {
        return $this->em->getRepository(SupplierPayment::class)->findBy(['supplierInvoice' => $facture->getId()]);
    }

    public function montantRegleCentimes(SupplierInvoice $facture): int
    {
        $total = 0;
        foreach ($this->reglements($facture) as $reglement) {
            $total += $this->centimes($reglement->getAmount());
        }

        return $total;
    }

    /** @return list<SupplierInvoice> avoirs (nature `credit_note`) qui corrigent la facture donnée (RG-SINV-09). */
    public function avoirs(SupplierInvoice $facture): array
    {
        return $this->em->getRepository(SupplierInvoice::class)->findBy([
            'correctsInvoice' => $facture->getId(),
            'nature' => SupplierInvoiceNature::CreditNote,
        ]);
    }

    /** Somme des lignes 401 (miroir, débit) des avoirs déjà émis sur cette facture. */
    public function montantAvoirsCentimes(SupplierInvoice $facture): int
    {
        $total = 0;
        foreach ($this->avoirs($facture) as $avoir) {
            $ecritureAvoir = $avoir->getLedgerEntry();
            if ($ecritureAvoir === null) {
                continue;
            }
            $total += $this->ligne401($ecritureAvoir)->getDebitCentimes();
        }

        return $total;
    }

    public function soldeCentimes(SupplierInvoice $facture): int
    {
        $ecriture = $facture->getLedgerEntry();
        if ($ecriture === null) {
            return 0;
        }

        $montantFacture = $this->ligne401($ecriture)->getCreditCentimes();

        return $montantFacture - $this->montantRegleCentimes($facture) - $this->montantAvoirsCentimes($facture);
    }

    /** Statut payable cohérent avec le solde actuel — `ToPay` si rien n'est réglé, `PartiallyPaid` sinon. */
    public function statutPayableCoherent(SupplierInvoice $facture): SupplierInvoiceStatus
    {
        return $this->montantRegleCentimes($facture) > 0 ? SupplierInvoiceStatus::PartiallyPaid : SupplierInvoiceStatus::ToPay;
    }

    public function centimes(string $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }
}
