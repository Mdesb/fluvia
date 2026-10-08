<?php

declare(strict_types=1);

namespace App\Vente\Service;

use App\Organisation\Entity\Etablissement;
use App\Vente\Port\SaleFiscalContextInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Une ligne sans taux de TVA résolu est refusée à sa création, avant tout paiement (D123, Q-B3 ; G-9).
 *
 * C'est le créateur de la ligne qui refuse, pas `LineLabelStamper` : un écouteur `prePersist` grave, il
 * ne peut pas refuser proprement. Chaque créateur appelle ce garde avant d'écrire quoi que ce soit :
 * la caisse (`AjoutLigneHandler`, sauf le rejeu hors ligne), la réservation et le no-show
 * (`VenteReservationHandler`), la boutique (`ConfirmerCommandeHandler`) et l'abonnement en ligne
 * (`SouscriptionAbonnementEnLigneHandler`). Jamais de 0 % en silence.
 */
final readonly class LineVatRateGuard
{
    public function __construct(
        private SaleFiscalContextInterface $fiscalContext,
    ) {
    }

    public function assertResolvable(Uuid $productId, ?Etablissement $site): void
    {
        $resolution = $this->fiscalContext->vatRateFor($productId, $site);
        if (!$resolution->isResolved()) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Vente refusée : %s. Une ligne ne part jamais sans taux de TVA ; renseignez la correspondance comptable (Comptabilité) avant de vendre ce produit.',
                $resolution->refusal,
            ));
        }
    }
}
