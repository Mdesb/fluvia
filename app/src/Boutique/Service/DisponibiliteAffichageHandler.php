<?php

declare(strict_types=1);

namespace App\Boutique\Service;

use App\Boutique\Entity\LignePanierEnLigne;
use App\Offre\Entity\Produit;
use App\Reservation\Entity\Creneau;
use App\Reservation\Service\JaugeCreneauGuard;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Disponibilité affichée en lecture (RG-M3-08, §1.2 du plan) — jamais un compteur dur : produit
 * simple → `Stock::disponibiliteEffective()` moins les lignes de panier actives non expirées ;
 * timed-entry → `JaugeCreneauGuard::placesRestantes()` moins les mêmes lignes. Non atomique (même
 * niveau de rigueur que `JaugeCreneauGuard`, ⚠ Risque n°2 du plan) : le verrou réel est au paiement.
 */
final class DisponibiliteAffichageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JaugeCreneauGuard $jauge,
    ) {
    }

    public function disponibilitePourCreneau(Creneau $creneau): int
    {
        $reserveEnPanier = (int) $this->em->getRepository(LignePanierEnLigne::class)->createQueryBuilder('l')
            ->select('COALESCE(SUM(l.quantite), 0)')
            ->andWhere('l.creneau = :creneau')
            ->andWhere('l.expirationA > :maintenant')
            ->setParameter('creneau', $creneau->getId(), 'uuid')
            ->setParameter('maintenant', new \DateTimeImmutable())
            ->getQuery()
            ->getSingleScalarResult();

        return max(0, $this->jauge->placesRestantes($creneau) - $reserveEnPanier);
    }

    public function disponibilitePourProduit(Produit $produit): ?int
    {
        $stock = $produit->getStock();
        if ($stock === null) {
            return null; // non géré en stock : jamais bloqué.
        }

        $reserveEnPanier = (int) $this->em->getRepository(LignePanierEnLigne::class)->createQueryBuilder('l')
            ->select('COALESCE(SUM(l.quantite), 0)')
            ->andWhere('l.produit = :produit')
            ->andWhere('l.expirationA > :maintenant')
            ->setParameter('produit', $produit->getId(), 'uuid')
            ->setParameter('maintenant', new \DateTimeImmutable())
            ->getQuery()
            ->getSingleScalarResult();

        return max(0, $stock->disponibiliteEffective() - $reserveEnPanier);
    }

    /** Vrai si le produit porte la facette timed-entry (⚠ HYPOTHÈSE, cf. Risque n°2 du plan). */
    public function estTimedEntry(Produit $produit): bool
    {
        return ($produit->getChampsPerso()['timedEntry'] ?? false) === true;
    }
}
