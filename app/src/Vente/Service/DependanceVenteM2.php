<?php

declare(strict_types=1);

namespace App\Vente\Service;

use App\Offre\Entity\Produit;
use App\Offre\Port\DependanceVenteInterface;
use App\Vente\Entity\LigneVente;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Implémentation réelle (M2) du port DependanceVenteInterface (stub en L1). Un produit a une
 * dépendance de vente active dès qu'une vente validée (non annulée) porte une ligne le référençant.
 * Permet à M1 (« dépublier ») de réagir correctement : un produit vendu ne peut être dépublié
 * librement. Remplace DependanceVenteStub via le binding de config/services.yaml.
 */
final class DependanceVenteM2 implements DependanceVenteInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function aDependanceActive(Produit $produit): bool
    {
        $nb = (int) $this->em->getRepository(LigneVente::class)
            ->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->innerJoin('l.vente', 'v')
            ->andWhere('l.produit = :produit')
            ->andWhere('v.statut IN (:actifs)')
            ->setParameter('produit', $produit->getId(), 'uuid')
            ->setParameter('actifs', ['validee', 'avoir_emis'])
            ->getQuery()
            ->getSingleScalarResult();

        return $nb > 0;
    }
}
