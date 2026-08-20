<?php

declare(strict_types=1);

namespace App\Finance\SupplierInvoice\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Finance\SupplierInvoice\Entity\SupplierInvoice;
use App\Finance\SupplierInvoice\Service\SupplierCreditNoteHandler;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /finance/supplier-invoices/{id}/credit-note — corps `{ amount?: string, reason: string }`
 * (`amount` absent = avoir total, §0.9 du plan — simplification proportionnelle globale documentée dans
 * `SupplierCreditNoteHandler`).
 *
 * @implements ProcessorInterface<SupplierInvoice, SupplierInvoice>
 */
final class CreditNoteSupplierInvoiceProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly SupplierCreditNoteHandler $handler,
        private readonly LecteurCorps $lecteur,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SupplierInvoice
    {
        \assert($data instanceof SupplierInvoice);
        $corps = $this->lecteur->corps();

        $motif = $corps['reason'] ?? null;
        if (!\is_string($motif) || trim($motif) === '') {
            throw new UnprocessableEntityHttpException('Le motif de l\'avoir est obligatoire.');
        }

        $montant = $corps['amount'] ?? null;
        $acteur = $this->security->getUser();

        return $this->handler->creerAvoir($data, \is_string($montant) ? $montant : null, $motif, $acteur instanceof Utilisateur ? $acteur : null);
    }
}
