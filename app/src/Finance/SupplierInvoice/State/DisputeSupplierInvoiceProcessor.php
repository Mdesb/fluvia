<?php

declare(strict_types=1);

namespace App\Finance\SupplierInvoice\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Finance\SupplierInvoice\Entity\SupplierInvoice;
use App\Finance\SupplierInvoice\Service\SupplierInvoiceDisputeHandler;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * POST /finance/supplier-invoices/{id}/dispute — corps `{ reason }` (422 si vide, CA-7).
 *
 * @implements ProcessorInterface<SupplierInvoice, SupplierInvoice>
 */
final class DisputeSupplierInvoiceProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly SupplierInvoiceDisputeHandler $handler,
        private readonly LecteurCorps $lecteur,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SupplierInvoice
    {
        \assert($data instanceof SupplierInvoice);
        $corps = $this->lecteur->corps();
        $motif = $corps['reason'] ?? null;
        $acteur = $this->security->getUser();

        return $this->handler->ouvrir($data, \is_string($motif) ? $motif : null, $acteur instanceof Utilisateur ? $acteur : null);
    }
}
