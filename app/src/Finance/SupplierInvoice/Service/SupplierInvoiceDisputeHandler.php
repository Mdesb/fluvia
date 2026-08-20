<?php

declare(strict_types=1);

namespace App\Finance\SupplierInvoice\Service;

use App\Finance\SupplierInvoice\Entity\SupplierInvoice;
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
 * Ouverture/clôture d'un litige (RG-SINV-08) : motif obligatoire, gèle les règlements tant qu'ouvert.
 * L'écriture déjà générée n'est **jamais** extournée automatiquement (§4.7 spec) — le litige est un
 * état documentaire, distinct de la comptabilisation, corrigée le cas échéant par un avoir (§4.8).
 */
final class SupplierInvoiceDisputeHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SupplierInvoiceBalanceCalculator $solde,
        private readonly EventBus $eventBus,
    ) {
    }

    public function ouvrir(SupplierInvoice $facture, ?string $motif, ?Utilisateur $acteur): SupplierInvoice
    {
        if (!\in_array($facture->getStatus(), [SupplierInvoiceStatus::ToPay, SupplierInvoiceStatus::PartiallyPaid], true)) {
            throw new ConflictHttpException('Un litige ne peut être ouvert que sur une facture à payer ou partiellement payée (RG-SINV-08).');
        }
        if ($motif === null || trim($motif) === '') {
            throw new UnprocessableEntityHttpException('Le motif du litige est obligatoire (RG-SINV-08, CA-7).');
        }

        $facture->setStatus(SupplierInvoiceStatus::Disputed);
        $facture->setDisputeReason($motif);
        $this->em->flush();

        $this->eventBus->publish(new DomainEvent(
            'supplier_invoice.disputed',
            new EventTenant($facture->getEtablissement()->getId()),
            new EventSubject('SupplierInvoice', (string) $facture->getId()),
            ['reason' => $motif],
            $acteur instanceof Utilisateur ? new EventActor($acteur->getId()) : null,
        ));

        return $facture;
    }

    public function resoudre(SupplierInvoice $facture, ?string $motifResolution): SupplierInvoice
    {
        if ($facture->getStatus() !== SupplierInvoiceStatus::Disputed) {
            throw new ConflictHttpException('Cette facture n\'est pas en litige.');
        }
        if ($motifResolution === null || trim($motifResolution) === '') {
            throw new UnprocessableEntityHttpException('Le motif de résolution du litige est obligatoire (§4.7 spec).');
        }

        $facture->setDisputeResolutionReason($motifResolution);
        $facture->setStatus($this->solde->statutPayableCoherent($facture));
        $this->em->flush();

        return $facture;
    }
}
