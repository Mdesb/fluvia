<?php

declare(strict_types=1);

namespace App\Finance\SupplierInvoice\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\Entity\MoyenPaiement;
use App\Finance\SupplierInvoice\Entity\SupplierPayment;
use App\Finance\SupplierInvoice\Service\SupplierPaymentHandler;
use App\Securite\Entity\Utilisateur;
use App\Stock\Security\PerimetreEtablissementVerificateur;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /supplier_payments (§0.8 du plan, D8 explicite) : `supplierInvoice` référencé par le corps est
 * revérifié dans le périmètre serveur (D8) avant tout traitement, `paymentMethod` doit être fourni.
 *
 * @implements ProcessorInterface<SupplierPayment, SupplierPayment>
 */
final class SupplierPaymentProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly SupplierPaymentHandler $handler,
        private readonly PerimetreEtablissementVerificateur $perimetre,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SupplierPayment
    {
        \assert($data instanceof SupplierPayment);

        $facture = $data->getSupplierInvoice();
        if ($facture === null) {
            throw new UnprocessableEntityHttpException('Le règlement doit référencer une facture fournisseur.');
        }

        // D8 : revérification explicite, ne se fie pas au filtrage implicite de la résolution d'IRI.
        if (!$this->perimetre->estDansLePerimetre($facture->getEtablissement())) {
            throw new NotFoundHttpException('Facture fournisseur introuvable.');
        }

        $moyenPaiement = $data->getPaymentMethod();
        if (!$moyenPaiement instanceof MoyenPaiement) {
            throw new UnprocessableEntityHttpException('Le moyen de paiement est obligatoire.');
        }

        $date = $data->getDate() ?? new \DateTimeImmutable();
        $acteur = $this->security->getUser();

        return $this->handler->enregistrer(
            $facture,
            $date,
            $data->getAmount(),
            $moyenPaiement,
            $data->getReference(),
            $acteur instanceof Utilisateur ? $acteur : null,
        );
    }
}
