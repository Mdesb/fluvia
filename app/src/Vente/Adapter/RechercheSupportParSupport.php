<?php

declare(strict_types=1);

namespace App\Vente\Adapter;

use App\Vente\Entity\BilletSupport;
use App\Vente\Port\RechercheSupportInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Implémentation réelle du port `RechercheSupportInterface` (§2.3 plan-crm.md, CA-1) : recherche du
 * client rattaché à la vente porteuse d'un n° de support/carte donné.
 */
final class RechercheSupportParSupport implements RechercheSupportInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function clientPourSupport(string $identifiant): ?Uuid
    {
        $identifiant = trim($identifiant);
        if ($identifiant === '') {
            return null;
        }

        $support = $this->em->getRepository(BilletSupport::class)->findOneBy(['identifiantSupport' => $identifiant]);
        if (!$support instanceof BilletSupport) {
            return null;
        }

        return $support->getVente()?->getClient();
    }
}
