<?php

declare(strict_types=1);

namespace App\Finance\SupplierInvoice\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Finance\SupplierInvoice\Entity\SupplierInvoiceLine;
use App\Finance\SupplierInvoice\Enum\SupplierInvoiceStatus;
use App\Stock\Security\PerimetreEtablissementVerificateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST/PATCH `/supplier_invoice_lines` (§2 du plan, D8 explicite) : la facture référencée doit être
 * dans le périmètre serveur (revérifié explicitement, jamais délégué à une extension Doctrine, D8) **et**
 * `status == draft` (409 sinon, RG-SINV-05 « scellée, plus aucune ligne modifiable »). Recalcule les
 * totaux du parent après chaque écriture (jamais fournis par le client).
 *
 * @implements ProcessorInterface<SupplierInvoiceLine, SupplierInvoiceLine>
 */
final class SupplierInvoiceLineProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PerimetreEtablissementVerificateur $perimetre,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SupplierInvoiceLine
    {
        \assert($data instanceof SupplierInvoiceLine);

        $facture = $data->getSupplierInvoice();
        if ($facture === null) {
            throw new UnprocessableEntityHttpException('La ligne doit référencer une facture fournisseur.');
        }

        // D8 : revérification explicite, ne se fie pas au filtrage implicite de la résolution d'IRI.
        if (!$this->perimetre->estDansLePerimetre($facture->getEtablissement())) {
            throw new NotFoundHttpException('Facture fournisseur introuvable.');
        }

        if ($facture->getStatus() !== SupplierInvoiceStatus::Draft) {
            throw new ConflictHttpException('Facture scellée : plus aucune ligne modifiable (RG-SINV-05).');
        }

        $taux = $data->getVatRate();
        $profil = $facture->getBusinessProfile();
        if ($taux === null || $profil === null || $taux->getProfilExploitant() === null || !$taux->getProfilExploitant()->getId()->equals($profil->getId())) {
            throw new UnprocessableEntityHttpException('Taux de TVA hors profil exploitant de la facture.');
        }

        $data->recalculer();

        $this->em->persist($data);
        $this->em->flush();

        $this->recalculerTotauxFacture($facture);

        return $data;
    }

    private function recalculerTotauxFacture(\App\Finance\SupplierInvoice\Entity\SupplierInvoice $facture): void
    {
        $lignes = $this->em->getRepository(SupplierInvoiceLine::class)->findBy(['supplierInvoice' => $facture->getId()]);

        $ht = 0.0;
        $ttc = 0.0;
        foreach ($lignes as $ligne) {
            $ht += (float) $ligne->getAmountExclTax();
            $ttc += (float) $ligne->getAmountInclTax();
        }

        $facture->setAmountExclTax(number_format(round($ht, 2), 2, '.', ''));
        $facture->setAmountInclTax(number_format(round($ttc, 2), 2, '.', ''));
        $this->em->flush();
    }
}
