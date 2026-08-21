<?php

declare(strict_types=1);

namespace App\Finance\Treasury\Service;

use App\Facturation\Entity\Facture;
use App\Facturation\Enum\StatutFacture;
use App\Finance\SupplierInvoice\Entity\SupplierInvoice;
use App\Finance\SupplierInvoice\Enum\SupplierInvoiceStatus;
use App\Finance\SupplierInvoice\Service\SupplierInvoiceBalanceCalculator;
use App\Sepa\Entity\RemiseSepa;
use App\Sepa\Enum\StatutRemiseSepa;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Échéancier consolidé (§0.8 du plan, RG-TRE-06/07, CA-5) — vue calculée, **non persistée**. Factorisé
 * hors de `PaymentScheduleProvider` (endpoint HTTP) pour être réutilisé tel quel par
 * `CashflowForecastCalculator` (RG-TRE-08).
 *
 * Chaque section est une requête indépendante : une base sans `Facture`/`RemiseSepa` correspondante
 * renvoie simplement une liste vide pour cette section (CA-5), jamais une exception — aucune dépendance
 * à `ModuleAccess`/`ModuleManifest` (absents de `App\Sepa`/`App\Facturation` aujourd'hui, §0.8 du plan).
 */
final class PaymentScheduleCalculator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SupplierInvoiceBalanceCalculator $soldeFournisseur,
    ) {
    }

    /**
     * @param list<string> $etablissements identifiants **binaires** (`Uuid::toBinary()`, §
     *                                     `PerimetreEtablissementsResolver`)
     *
     * @return array{exits: list<array{date: ?string, amountCents: int, source: string, sourceId: string}>, entries: list<array{date: ?string, amountCents: int, source: string, sourceId: string}>}
     */
    public function echeancier(array $etablissements, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $exits = [];
        /** @var list<SupplierInvoice> $facturesFournisseurs */
        $facturesFournisseurs = $this->em->createQueryBuilder()
            ->select('f')->from(SupplierInvoice::class, 'f')
            ->andWhere('f.establishment IN (:etablissements)')
            ->andWhere('f.status IN (:statuts)')
            ->andWhere('f.dueDate BETWEEN :from AND :to')
            ->setParameter('etablissements', $etablissements, ArrayParameterType::BINARY)
            ->setParameter('statuts', [SupplierInvoiceStatus::ToPay, SupplierInvoiceStatus::PartiallyPaid])
            ->setParameter('from', $from, 'date_immutable')
            ->setParameter('to', $to, 'date_immutable')
            ->getQuery()->getResult();

        foreach ($facturesFournisseurs as $facture) {
            $exits[] = [
                'date' => $facture->getDueDate()?->format('Y-m-d'),
                'amountCents' => $this->soldeFournisseur->soldeCentimes($facture),
                'source' => 'supplier_invoice',
                'sourceId' => (string) $facture->getId(),
            ];
        }

        $entries = [];
        /** @var list<Facture> $facturesClients */
        $facturesClients = $this->em->createQueryBuilder()
            ->select('f')->from(Facture::class, 'f')
            ->andWhere('f.etablissement IN (:etablissements)')
            ->andWhere('f.statut IN (:statuts)')
            ->andWhere('f.dateEcheance BETWEEN :from AND :to')
            ->setParameter('etablissements', $etablissements, ArrayParameterType::BINARY)
            ->setParameter('statuts', [StatutFacture::EnAttentePaiement, StatutFacture::PartiellementReglee])
            ->setParameter('from', $from, 'date_immutable')
            ->setParameter('to', $to, 'date_immutable')
            ->getQuery()->getResult();

        foreach ($facturesClients as $facture) {
            $entries[] = [
                'date' => $facture->getDateEcheance()?->format('Y-m-d'),
                'amountCents' => $this->centimes($facture->getSoldeDu()),
                'source' => 'invoice',
                'sourceId' => (string) $facture->getId(),
            ];
        }

        /** @var list<RemiseSepa> $remises */
        $remises = $this->em->createQueryBuilder()
            ->select('r')->from(RemiseSepa::class, 'r')
            ->andWhere('r.etablissement IN (:etablissements)')
            ->andWhere('r.statut != :transmise')
            ->andWhere('r.dateCollecte BETWEEN :from AND :to')
            ->setParameter('etablissements', $etablissements, ArrayParameterType::BINARY)
            ->setParameter('transmise', StatutRemiseSepa::Transmise)
            ->setParameter('from', $from, 'date_immutable')
            ->setParameter('to', $to, 'date_immutable')
            ->getQuery()->getResult();

        foreach ($remises as $remise) {
            $entries[] = [
                'date' => $remise->getDateCollecte()->format('Y-m-d'),
                'amountCents' => $remise->getCtrlSumCentimes(),
                'source' => 'sepa_remise',
                'sourceId' => (string) $remise->getId(),
            ];
        }

        return ['exits' => $exits, 'entries' => $entries];
    }

    private function centimes(string $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }
}
