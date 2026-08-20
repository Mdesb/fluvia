<?php

declare(strict_types=1);

namespace App\Finance\SupplierInvoice\Service;

use App\Finance\SupplierInvoice\Dto\PurchaseReconciliationGap;
use App\Finance\SupplierInvoice\Entity\ReconciliationSettings;
use App\Finance\SupplierInvoice\Entity\SupplierInvoice;
use App\Stock\Entity\LigneReceptionAchat;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Rapprochement 3 voies (commande / réception / facture, RG-SINV-03, §4.3 spec) : compare, ligne à
 * ligne (quand `stockArticle` est renseigné), la quantité et le prix unitaire facturés à ceux de la
 * **réception** (`ReceptionAchat`, source de vérité privilégiée — « ce qui a été physiquement reçu »).
 * Une facture sans `goodsReceipt` rattaché n'a **aucun** écart calculé (§4.3, cas limite). Un écart
 * au-delà du seuil paramétrable est **signalé, jamais bloquant** (CA-3).
 */
final class PurchaseReconciliationCalculator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return list<PurchaseReconciliationGap> */
    public function calculer(SupplierInvoice $facture): array
    {
        $reception = $facture->getGoodsReceipt();
        if ($reception === null) {
            return [];
        }

        $profil = $facture->getBusinessProfile();
        $seuil = $profil !== null
            ? (float) ($this->reglages($profil)?->getToleranceThresholdPercent() ?? ReconciliationSettings::DEFAULT_THRESHOLD_PERCENT)
            : (float) ReconciliationSettings::DEFAULT_THRESHOLD_PERCENT;

        /** @var array<string, LigneReceptionAchat> $lignesReceptionParArticle */
        $lignesReceptionParArticle = [];
        foreach ($reception->getLignes() as $ligneReception) {
            $article = $ligneReception->getArticleStock();
            if ($article !== null) {
                $lignesReceptionParArticle[(string) $article->getId()] = $ligneReception;
            }
        }

        $ecarts = [];
        foreach ($facture->getLines() as $ligneFacture) {
            $article = $ligneFacture->getStockArticle();
            if ($article === null) {
                continue;
            }

            $ligneReception = $lignesReceptionParArticle[(string) $article->getId()] ?? null;
            if ($ligneReception === null) {
                continue;
            }

            $quantiteFacturee = (float) $ligneFacture->getQuantity();
            $quantiteRecue = (float) $ligneReception->getQuantiteRecue();
            $prixFacture = (float) $ligneFacture->getUnitPriceExclTax();
            $prixReception = (float) $ligneReception->getPrixAchatUnitaireHT();

            $ecartQuantite = $quantiteFacturee - $quantiteRecue;
            $ecartPrix = $prixFacture - $prixReception;
            $ecartPrixPourcent = $prixReception > 0.0 ? ($ecartPrix / $prixReception) * 100 : 0.0;
            $depasse = abs($ecartPrixPourcent) > $seuil;

            $ecarts[] = new PurchaseReconciliationGap(
                lineId: (string) $ligneFacture->getId(),
                quantityGap: number_format(round($ecartQuantite, 3), 3, '.', ''),
                unitPriceGap: number_format(round($ecartPrix, 2), 2, '.', ''),
                unitPriceGapPercent: number_format(round($ecartPrixPourcent, 2), 2, '.', ''),
                thresholdExceeded: $depasse,
            );
        }

        return $ecarts;
    }

    private function reglages(\App\Compta\Entity\ProfilExploitant $profil): ?ReconciliationSettings
    {
        return $this->em->getRepository(ReconciliationSettings::class)->findOneBy(['businessProfile' => $profil->getId()]);
    }
}
