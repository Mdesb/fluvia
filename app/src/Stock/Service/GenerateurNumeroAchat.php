<?php

declare(strict_types=1);

namespace App\Stock\Service;

use App\Stock\Entity\CommandeAchat;
use Doctrine\ORM\EntityManagerInterface;

/** Numéro de commande d'achat lisible et traçable (même patron que `App\Vente\Service\GenerateurNumero`). */
final class GenerateurNumeroAchat
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function genererPourCommande(): string
    {
        $nb = (int) $this->em->getRepository(CommandeAchat::class)
            ->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return sprintf('CA-%s-%05d', date('Ymd'), $nb + 1);
    }
}
