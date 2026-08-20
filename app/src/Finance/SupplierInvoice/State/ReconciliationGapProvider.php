<?php

declare(strict_types=1);

namespace App\Finance\SupplierInvoice\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Finance\SupplierInvoice\Entity\SupplierInvoice;
use App\Finance\SupplierInvoice\Service\PurchaseReconciliationCalculator;
use App\Stock\Security\PerimetreEtablissementVerificateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Uid\Uuid;

/**
 * GET /finance/supplier-invoices/{id}/reconciliation (§4.3 spec, non persisté) — retourne
 * `list<PurchaseReconciliationGap>`. Résolution + revérification explicite du périmètre (D8, belt-and-
 * suspenders : ce endpoint n'a pas d'`uriVariables` liant `{id}` au provider standard de `SupplierInvoice`).
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class ReconciliationGapProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PurchaseReconciliationCalculator $calculator,
        private readonly PerimetreEtablissementVerificateur $perimetre,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $id = $uriVariables['id'] ?? null;
        if (!\is_string($id) || !Uuid::isValid($id)) {
            return new JsonResponse([]);
        }

        $facture = $this->em->find(SupplierInvoice::class, Uuid::fromString($id));
        // Échec fermé (D8) : ne distingue jamais « n'existe pas » de « hors périmètre ».
        $this->perimetre->verifier($facture?->getEtablissement(), 'Facture fournisseur introuvable.');
        \assert($facture instanceof SupplierInvoice);

        $ecarts = $this->calculator->calculer($facture);

        return new JsonResponse(array_map(static fn ($ecart) => $ecart->toArray(), $ecarts));
    }
}
