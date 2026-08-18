<?php

declare(strict_types=1);

namespace App\Boutique\Service;

use App\Boutique\Entity\BilletQrMeta;
use App\Vente\Entity\BilletSupport;
use App\Vente\Entity\Vente;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Liste les billets QR (satellites `App\Boutique\Entity\BilletQrMeta`) d'une `Vente` M2 payée —
 * mutualisé entre `App\Boutique\State\MesBilletsProvider` (titulaire de compte connecté) et
 * `App\Boutique\State\PanierBilletsProvider` (acheteur invité identifié par jeton de panier), pour
 * ne pas dupliquer la projection JSON des billets.
 */
final class BilletsCommandeHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function listerPourVente(Vente $vente): array
    {
        $billets = [];
        foreach ($this->em->getRepository(BilletSupport::class)->findBy(['vente' => $vente]) as $support) {
            \assert($support instanceof BilletSupport);
            $meta = $this->em->getRepository(BilletQrMeta::class)->findOneBy(['billetSupport' => $support]);
            $billets[] = [
                'billetSupport' => (string) $support->getId(),
                'identifiantSupport' => $support->getIdentifiantSupport(),
                'vente' => (string) $vente->getId(),
                'qrDynamique' => $meta?->getQrDynamique(),
                'passWalletDisponible' => $meta?->isPassWalletDisponible() ?? false,
                'repliQr' => $meta?->isRepliQr() ?? true,
                'statutRetraitPhysique' => $meta?->getStatutRetraitPhysique()?->value,
            ];
        }

        return $billets;
    }
}
