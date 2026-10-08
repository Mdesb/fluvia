<?php

declare(strict_types=1);

namespace App\Boutique\Service;

use App\Offre\Entity\Produit;
use App\Offre\Enum\Canal;
use App\Offre\Port\PublicationPrerequisite;
use App\Reservation\Entity\Creneau;
use App\Reservation\Enum\StatutCreneau;
use Doctrine\ORM\EntityManagerInterface;

/**
 * UN PRODUIT VENDU À L'HORAIRE NE SE MET PAS EN VENTE SANS UN CRÉNEAU À VENIR.
 *
 * `AjouterLignePanierProcessor` refuse un produit `timedEntry` sans créneau : publié sans créneau
 * ouvert, il s'affiche en boutique et ne peut pas y être acheté. Mesuré en préprod le 08/10 :
 * « Visite guidée (créneau) », publiée, n'en a aucun à venir.
 *
 * « Ouvert » se lit comme `CreneauxProduitProvider` le propose en ligne : activité active qui désigne
 * ce produit, sur l'un de ses sites, créneau planifié, à venir, ni en arbitrage ni à public réservé.
 * La jointure sur `p.etablissements` compare des clés en SQL, sans paramètre `IN` (D58).
 */
final class TimedEntryPublicationPrerequisite implements PublicationPrerequisite
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DisponibiliteAffichageHandler $availability,
    ) {
    }

    public function missing(Produit $product): array
    {
        // Seul le panier en ligne exige un créneau : au guichet, rien ne lit `timedEntry`. Un produit
        // horaire vendu au seul guichet n'a donc rien à prouver ici.
        if (!$this->availability->estTimedEntry($product) || !$product->aCanal(Canal::EnLigne)) {
            return [];
        }

        $open = (int) $this->em->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(Creneau::class, 'c')
            ->join('c.activite', 'a')
            ->join('a.produitTarifReference', 'p')
            ->join('p.etablissements', 'e', 'WITH', 'e = a.etablissement')
            ->andWhere('p.id = :product')
            ->andWhere('a.actif = true')
            ->andWhere('c.statut = :planned')
            ->andWhere('c.enAttenteArbitrage = false')
            ->andWhere("(c.publicReserve IS NULL OR c.publicReserve = '')")
            ->andWhere('c.debut >= :now')
            ->setParameter('product', $product->getId(), 'uuid')
            ->setParameter('planned', StatutCreneau::Planifie->value)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getSingleScalarResult();

        return $open > 0 ? [] : ['creneau' => "Ce produit se vend à l'horaire : programmez au moins un créneau à venir. Sans créneau, il ne peut pas être acheté en ligne."];
    }
}
