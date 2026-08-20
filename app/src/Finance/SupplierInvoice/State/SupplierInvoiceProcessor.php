<?php

declare(strict_types=1);

namespace App\Finance\SupplierInvoice\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Finance\SupplierInvoice\Entity\SupplierInvoice;
use App\Finance\SupplierInvoice\Enum\SupplierInvoiceSource;
use App\Finance\SupplierInvoice\Enum\SupplierInvoiceStatus;
use App\Organisation\Entity\Etablissement;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventActor;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Securite\Entity\Utilisateur;
use App\Stock\Security\PerimetreEtablissementVerificateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST/PATCH `/finance/supplier-invoices` (création/édition en brouillon, §0.2 point 2 du plan, D8
 * explicite).
 *
 * Ne se fie **jamais** au filtrage implicite d'une éventuelle résolution d'IRI (§7 point 6 du plan,
 * hypothèse non vérifiée) : revérifie **explicitement** chaque relation posée par le corps de requête,
 * échec fermé — `establishment` via `PerimetreEtablissementVerificateur` (Stock, réutilisé tel quel,
 * §0.2 point 2), `businessProfile` via `couvre()` (IDOR inter-profils, 404), `supplier`/`purchaseOrder`/
 * `goodsReceipt` doivent appartenir au **même** établissement (404 sinon).
 *
 * Émet `supplier_invoice.recorded` (D6/D7, CA-9) à la fin de la création, dans la même transaction
 * implicite que le `flush()` (le tenant est dérivé de `SupplierInvoice.establishment`, jamais du
 * contexte HTTP).
 *
 * @implements ProcessorInterface<SupplierInvoice, SupplierInvoice>
 */
final class SupplierInvoiceProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PerimetreEtablissementVerificateur $perimetre,
        private readonly Security $security,
        private readonly EventBus $eventBus,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SupplierInvoice
    {
        \assert($data instanceof SupplierInvoice);
        $creation = !isset($uriVariables['id']);

        if (!$creation && $data->getStatus() !== SupplierInvoiceStatus::Draft) {
            throw new ConflictHttpException('Facture scellée : plus aucune modification possible (RG-SINV-05).');
        }

        // D8 — échec fermé (403) : réutilise tel quel le service Stock (§0.2 point 2 du plan).
        $etablissement = $this->perimetre->verifier($data->getEstablishment());

        $profil = $data->getBusinessProfile();
        if ($profil === null || !$profil->couvre($etablissement)) {
            throw new NotFoundHttpException('Profil exploitant introuvable ou hors périmètre.');
        }

        $fournisseur = $data->getSupplier();
        if ($fournisseur === null || !$this->memeEtablissement($fournisseur->getEtablissement(), $etablissement)) {
            throw new NotFoundHttpException('Fournisseur introuvable.');
        }
        if ($creation && !$fournisseur->isActif()) {
            throw new UnprocessableEntityHttpException('Ce fournisseur est inactif : impossible de créer une nouvelle facture (CA-1).');
        }

        $commande = $data->getPurchaseOrder();
        if ($commande !== null && !$this->memeEtablissement($commande->getEtablissement(), $etablissement)) {
            throw new NotFoundHttpException('Commande d\'achat introuvable.');
        }

        $reception = $data->getGoodsReceipt();
        if ($reception !== null && !$this->memeEtablissement($reception->getEtablissement(), $etablissement)) {
            throw new NotFoundHttpException('Réception d\'achat introuvable.');
        }

        $utilisateur = $this->security->getUser();
        $acteur = $utilisateur instanceof Utilisateur ? $utilisateur : null;

        if ($creation) {
            $data->setStatus(SupplierInvoiceStatus::Draft);
            $data->setSource($data->getOcrExtraction() !== null ? SupplierInvoiceSource::Ocr : SupplierInvoiceSource::Manual);
            if ($acteur !== null) {
                $data->setCreatedBy($acteur);
            }
        }

        $this->em->persist($data);
        $this->em->flush();

        if ($creation) {
            $this->publierRecorded($data, $acteur, $etablissement);
        }

        return $data;
    }

    private function publierRecorded(SupplierInvoice $facture, ?Utilisateur $acteur, Etablissement $etablissement): void
    {
        $this->eventBus->publish(new DomainEvent(
            'supplier_invoice.recorded',
            new EventTenant($etablissement->getId()),
            new EventSubject('SupplierInvoice', (string) $facture->getId()),
            [
                'supplierId' => (string) $facture->getSupplier()?->getId(),
                'supplierInvoiceNumber' => $facture->getSupplierInvoiceNumber(),
                'amountInclTaxCents' => (int) round(((float) $facture->getAmountInclTax()) * 100),
                'source' => $facture->getSource()->value,
            ],
            $acteur !== null ? new EventActor($acteur->getId()) : null,
        ));
    }

    private function memeEtablissement(?Etablissement $candidat, Etablissement $etablissement): bool
    {
        return $candidat !== null && $candidat->getId()->equals($etablissement->getId());
    }
}
