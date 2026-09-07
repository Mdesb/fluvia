<?php

declare(strict_types=1);

namespace App\Sport\Repository;

use App\Sport\Entity\AbonnementFitness;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Depot des abonnements fitness.
 *
 * @extends ServiceEntityRepository<AbonnementFitness>
 */
class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AbonnementFitness::class);
    }

    /**
     * L'abonnement cree par une ligne de vente donnee, s'il existe.
     *
     * Sert deux besoins a partir d'un seul lien : l'idempotence de la creation au guichet (ne pas
     * recreer si la validation est rejouee) et le lien retour Vente vers abonnement pour la
     * revocation au remboursement.
     */
    public function findOneBySourceSaleLine(Uuid $lineId): ?AbonnementFitness
    {
        return $this->findOneBy(['sourceSaleLineId' => $lineId]);
    }
}
