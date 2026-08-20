<?php

declare(strict_types=1);

namespace App\Finance\SupplierInvoice\Service;

use App\Compta\Dto\DirectLedgerEntryLine;
use App\Compta\Entity\MoyenPaiement;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Service\DirectLedgerEntryBuilder;
use App\Compta\Service\LettrageHandler;
use App\Compta\Service\PeriodeComptableResolver;
use App\Finance\SupplierInvoice\Entity\SupplierInvoice;
use App\Finance\SupplierInvoice\Entity\SupplierPayment;
use App\Finance\SupplierInvoice\Enum\SupplierInvoiceStatus;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventActor;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Enregistrement d'un règlement fournisseur (RG-SINV-07, §0.8 du plan) — lettrage **différé** : chaque
 * règlement génère sa propre écriture (débit 401/crédit 512), le lettrage groupé avec la ligne 401
 * d'origine n'est déclenché qu'au règlement qui solde **exactement** la facture.
 */
final class SupplierPaymentHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ResolveurComptesSupplierInvoice $comptes,
        private readonly PeriodeComptableResolver $periodes,
        private readonly DirectLedgerEntryBuilder $builder,
        private readonly LettrageHandler $lettrage,
        private readonly SupplierInvoiceBalanceCalculator $solde,
        private readonly EventBus $eventBus,
    ) {
    }

    public function enregistrer(SupplierInvoice $facture, \DateTimeImmutable $date, string $montantDecimal, MoyenPaiement $moyenPaiement, ?string $reference, ?Utilisateur $acteur): SupplierPayment
    {
        if (!\in_array($facture->getStatus(), [SupplierInvoiceStatus::ToPay, SupplierInvoiceStatus::PartiallyPaid], true)) {
            throw new ConflictHttpException('Cette facture n\'est pas dans un état payable (litige/brouillon/annulée/déjà soldée, RG-SINV-08).');
        }

        $ecritureFacture = $facture->getLedgerEntry();
        if ($ecritureFacture === null) {
            throw new ConflictHttpException('Facture non comptabilisée : aucun règlement possible.');
        }

        $montantCentimes = $this->solde->centimes($montantDecimal);
        if ($montantCentimes <= 0) {
            throw new UnprocessableEntityHttpException('Le montant du règlement doit être strictement positif.');
        }

        $soldeCentimes = $this->solde->soldeCentimes($facture);
        if ($montantCentimes > $soldeCentimes) {
            throw new UnprocessableEntityHttpException('Montant du règlement supérieur au solde restant dû.');
        }

        $profil = $facture->getBusinessProfile();
        \assert($profil instanceof ProfilExploitant);

        $ligne401Facture = $this->solde->ligne401($ecritureFacture);
        $fournisseur = $facture->getSupplier();

        $compteFournisseur = $this->comptes->compteFournisseur($profil);
        $compteTresorerie = $this->comptes->compteTresorerie($profil);
        $journal = $this->comptes->journalReglements($profil);
        $periode = $this->periodes->resoudreOuCreer($profil, $date);

        $lignesDto = [
            new DirectLedgerEntryLine(
                compte: $compteFournisseur,
                debitCentimes: $montantCentimes,
                creditCentimes: 0,
                tauxTva: $ligne401Facture->getTauxTva(),
                libelle: 'Règlement facture fournisseur ' . $facture->getSupplierInvoiceNumber(),
                counterpartyType: 'stock_fournisseur',
                counterpartyId: $fournisseur?->getId(),
                counterpartyLabel: $fournisseur?->getRaisonSociale(),
            ),
            new DirectLedgerEntryLine(
                compte: $compteTresorerie,
                debitCentimes: 0,
                creditCentimes: $montantCentimes,
                tauxTva: $ligne401Facture->getTauxTva(),
                libelle: 'Règlement facture fournisseur ' . $facture->getSupplierInvoiceNumber(),
            ),
        ];

        $ecritureReglement = $this->builder->construire($profil, $journal, $periode, $date, 'Règlement fournisseur ' . $facture->getSupplierInvoiceNumber(), $lignesDto);
        $this->em->flush();

        $ligne401Reglement = $this->solde->ligne401($ecritureReglement);

        $reglement = new SupplierPayment();
        $reglement->setSupplierInvoice($facture);
        $reglement->setDate($date);
        $reglement->setAmount(number_format($montantCentimes / 100, 2, '.', ''));
        $reglement->setPaymentMethod($moyenPaiement);
        $reglement->setReference($reference);
        $reglement->setLedgerEntry($ecritureReglement);
        $reglement->setCreatedBy($acteur);
        $this->em->persist($reglement);

        $nouveauSoldeCentimes = $soldeCentimes - $montantCentimes;

        if ($nouveauSoldeCentimes === 0) {
            $lignes401 = [$ligne401Facture];
            foreach ($this->solde->reglements($facture) as $reglementPrecedent) {
                $ecriturePrecedente = $reglementPrecedent->getLedgerEntry();
                if ($ecriturePrecedente !== null) {
                    $lignes401[] = $this->solde->ligne401($ecriturePrecedente);
                }
            }
            $lignes401[] = $ligne401Reglement;

            $lettrages = $this->lettrage->lettrerGroupe($lignes401, $acteur ?? $this->utilisateurSysteme($facture), $date);
            $reglement->setReconciliationCode($lettrages[0]->getReconciliationCode());
            $facture->setStatus(SupplierInvoiceStatus::Paid);
        } else {
            $facture->setStatus(SupplierInvoiceStatus::PartiallyPaid);
        }

        $this->em->flush();

        if ($facture->getStatus() === SupplierInvoiceStatus::Paid) {
            $this->eventBus->publish(new DomainEvent(
                'supplier_invoice.paid',
                new EventTenant($facture->getEtablissement()->getId()),
                new EventSubject('SupplierInvoice', (string) $facture->getId()),
                [
                    'amountInclTaxCents' => $this->solde->centimes($facture->getAmountInclTax()),
                    'date' => $date->format('Y-m-d'),
                    'paymentMethod' => $moyenPaiement->getCode(),
                ],
                $acteur instanceof Utilisateur ? new EventActor($acteur->getId()) : null,
            ));
        }

        return $reglement;
    }

    private function utilisateurSysteme(SupplierInvoice $facture): Utilisateur
    {
        // `lettrerGroupe()` exige un auteur non-nul (traçabilité RG-M6-14) : à défaut d'acteur HTTP
        // authentifié (ne devrait pas arriver en usage normal, la route exige une permission), on
        // retombe sur le créateur de la facture plutôt que de bloquer le lettrage.
        $createur = $facture->getCreatedBy();
        \assert($createur instanceof Utilisateur);

        return $createur;
    }
}
