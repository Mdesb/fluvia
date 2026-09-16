<?php

declare(strict_types=1);

namespace App\Membership\Repository;

use App\Membership\Entity\Membership;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Dépôt des abonnements (`Membership`).
 *
 * @extends ServiceEntityRepository<Membership>
 */
class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Membership::class);
    }

    /**
     * L'abonnement créé par une ligne de vente donnée, s'il existe.
     *
     * Sert deux besoins à partir d'un seul lien : l'idempotence de la création au comptoir (ne pas
     * recréer si la validation ou la reprise est rejouée) et le lien retour Vente vers abonnement.
     */
    public function findOneBySourceSaleLine(Uuid $lineId): ?Membership
    {
        return $this->findOneBy(['sourceSaleLineId' => $lineId]);
    }
}
