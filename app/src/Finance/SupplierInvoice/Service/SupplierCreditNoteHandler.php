<?php

declare(strict_types=1);

namespace App\Finance\SupplierInvoice\Service;

use App\Compta\Dto\DirectLedgerEntryLine;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Service\DirectLedgerEntryBuilder;
use App\Compta\Service\PeriodeComptableResolver;
use App\Finance\SupplierInvoice\Entity\SupplierInvoice;
use App\Finance\SupplierInvoice\Enum\SupplierInvoiceNature;
use App\Finance\SupplierInvoice\Enum\SupplierInvoiceStatus;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Avoir fournisseur — seule voie de correction d'une facture déjà validée (RG-SINV-09, §0.9 du plan).
 * Réutilise le moteur générique d'écritures (`DirectLedgerEntryBuilder`) et le champ **déjà existant**
 * `EcritureComptable::pieceExtourneDe` — **pas** `App\Compta\State\ExtourneEcritureProcessor` (couplé au
 * régime de vente et total uniquement, alors que RG-SINV-09 exige total **ou partiel**).
 *
 * Simplification assumée pour ce lot (à l'instar de `AvoirFactureHandler::genererAvoir()`, « avoir total
 * … simplification assumée ») : le montant partiel est une **proportion globale de la facture**
 * (`amount` TTC optionnel dans le corps, absent = avoir total), appliquée uniformément à chaque ligne de
 * l'écriture d'origine — pas une sélection ligne à ligne. Un écart d'arrondi éventuel est absorbé par la
 * ligne 401 pour garantir l'équilibre strict (`DirectLedgerEntryBuilder::construire()` le revérifie).
 */
final class SupplierCreditNoteHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PeriodeComptableResolver $periodes,
        private readonly DirectLedgerEntryBuilder $builder,
    ) {
    }

    public function creerAvoir(SupplierInvoice $origine, ?string $montantTtcDecimal, string $motif, ?Utilisateur $acteur): SupplierInvoice
    {
        if (\in_array($origine->getStatus(), [SupplierInvoiceStatus::Draft, SupplierInvoiceStatus::Cancelled], true)) {
            throw new ConflictHttpException('Un avoir corrige une facture déjà validée, pas un brouillon ni une facture annulée (RG-SINV-09).');
        }
        if ($origine->getNature() === SupplierInvoiceNature::CreditNote) {
            throw new ConflictHttpException('Un avoir ne peut pas lui-même recevoir un avoir.');
        }
        $ecritureOrigine = $origine->getLedgerEntry();
        if ($ecritureOrigine === null) {
            throw new ConflictHttpException('Facture non comptabilisée : aucun avoir possible.');
        }
        if (trim($motif) === '') {
            throw new UnprocessableEntityHttpException('Le motif de l\'avoir est obligatoire.');
        }

        $profil = $origine->getBusinessProfile();
        \assert($profil instanceof ProfilExploitant);

        $montantOrigineCentimes = $this->centimes($origine->getAmountInclTax());
        $montantAvoirCentimes = $montantTtcDecimal !== null ? $this->centimes($montantTtcDecimal) : $montantOrigineCentimes;
        if ($montantAvoirCentimes <= 0 || $montantAvoirCentimes > $montantOrigineCentimes) {
            throw new UnprocessableEntityHttpException('Montant d\'avoir invalide : doit être positif et ne peut excéder le montant de la facture d\'origine.');
        }

        $ratio = $montantAvoirCentimes / $montantOrigineCentimes;

        $lignesDto = [];
        $totalDebit = 0;
        $totalCredit = 0;
        $indexLigne401 = null;
        foreach ($ecritureOrigine->getLignes() as $ligne) {
            $debitAvoir = $this->proportion($ligne->getCreditCentimes(), $ratio);
            $creditAvoir = $this->proportion($ligne->getDebitCentimes(), $ratio);

            $lignesDto[] = new DirectLedgerEntryLine(
                compte: $ligne->getCompte(),
                debitCentimes: $debitAvoir,
                creditCentimes: $creditAvoir,
                tauxTva: $ligne->getTauxTva(),
                libelle: 'Avoir — ' . $ligne->getLibelle(),
                counterpartyType: $ligne->getCounterpartyType(),
                counterpartyId: $ligne->getCounterpartyId(),
                counterpartyLabel: $ligne->getCounterpartyLabel(),
            );
            if ($ligne->getCounterpartyType() === 'stock_fournisseur') {
                $indexLigne401 = array_key_last($lignesDto);
            }
            $totalDebit += $debitAvoir;
            $totalCredit += $creditAvoir;
        }

        // Absorption de l'écart d'arrondi (ratio < 1) sur la ligne 401, pour garantir Σdébit = Σcrédit
        // strictement (défense en profondeur : `DirectLedgerEntryBuilder::construire()` le revérifie).
        if ($totalDebit !== $totalCredit && $indexLigne401 !== null) {
            $ecart = $totalDebit - $totalCredit;
            $ligne401 = $lignesDto[$indexLigne401];
            $lignesDto[$indexLigne401] = new DirectLedgerEntryLine(
                compte: $ligne401->compte,
                debitCentimes: $ligne401->debitCentimes - $ecart,
                creditCentimes: $ligne401->creditCentimes,
                tauxTva: $ligne401->tauxTva,
                libelle: $ligne401->libelle,
                counterpartyType: $ligne401->counterpartyType,
                counterpartyId: $ligne401->counterpartyId,
                counterpartyLabel: $ligne401->counterpartyLabel,
            );
        }

        $date = new \DateTimeImmutable();
        $periode = $this->periodes->resoudreOuCreer($profil, $date);
        $libelle = 'Avoir — facture fournisseur ' . $origine->getSupplierInvoiceNumber();

        $journalOrigine = $ecritureOrigine->getJournal();
        \assert($journalOrigine !== null);
        $ecritureAvoir = $this->builder->construire($profil, $journalOrigine, $periode, $date, $libelle, $lignesDto);
        $ecritureAvoir->setPieceExtourneDe($ecritureOrigine);

        $avoir = new SupplierInvoice();
        $avoir->setEstablishment($origine->getEtablissement());
        $avoir->setBusinessProfile($profil);
        $avoir->setSupplier($origine->getSupplier());
        $avoir->setNature(SupplierInvoiceNature::CreditNote);
        $avoir->setSupplierInvoiceNumber('AVOIR-' . $origine->getSupplierInvoiceNumber());
        $avoir->setInvoiceDate($date);
        $avoir->setDueDate($date);
        $avoir->setCorrectsInvoice($origine);
        $avoir->setDisputeResolutionReason($motif);
        $avoir->setLedgerEntry($ecritureAvoir);
        $avoir->setStatus(SupplierInvoiceStatus::ToPay);
        $avoir->setAmountInclTax(number_format($montantAvoirCentimes / 100, 2, '.', ''));
        $avoirExclTaxCentimes = $this->proportion($this->centimes($origine->getAmountExclTax()), $ratio);
        $avoir->setAmountExclTax(number_format($avoirExclTaxCentimes / 100, 2, '.', ''));
        $avoir->setCreatedBy($acteur);

        $this->em->persist($avoir);
        $this->em->flush();

        return $avoir;
    }

    private function proportion(int $centimes, float $ratio): int
    {
        return $ratio === 1.0 ? $centimes : (int) round($centimes * $ratio);
    }

    private function centimes(string $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }
}
