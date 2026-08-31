<?php

declare(strict_types=1);

namespace App\Compta\Repository;

use App\Compta\Entity\LegalVatRate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LegalVatRate>
 */
class LegalVatRateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LegalVatRate::class);
    }

    /**
     * Les taux en vigueur d'un pays a une date donnee.
     *
     * ⚠ LA DATE EST UN PARAMETRE, PAS « MAINTENANT ». Expliquer une facture de 2025 demande les taux
     * de 2025 ; un referentiel qui ne saurait rendre que l'etat du jour serait faux sur tout le passe
     * des le premier decret, et personne ne s'en apercevrait puisque les montants, eux, sont figes.
     *
     * @return list<LegalVatRate>
     */
    public function inForce(string $country, \DateTimeImmutable $on): array
    {
        /** @var list<LegalVatRate> $resultat */
        $resultat = $this->createQueryBuilder('t')
            ->andWhere('t.country = :pays')->setParameter('pays', strtoupper($country))
            ->andWhere('t.validFrom <= :date')->setParameter('date', $on)
            ->andWhere('t.validUntil IS NULL OR t.validUntil >= :date')
            ->orderBy('t.rate', 'DESC')
            ->getQuery()
            ->getResult();

        return $resultat;
    }
}
