<?php

declare(strict_types=1);

namespace App\Vente\Nf525;

use App\Caisse\Entity\PointDeVente;
use App\Vente\Nf525\Entity\OperationScellee;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Orchestre le scellement d'une opération dans la même transaction que sa validation (§2 du plan).
 * Récupère le dernier maillon de la chaîne du point de vente, délègue le calcul au SignataireOperation
 * (enfichable) et persiste le nouveau maillon. Le verrou d'unicité (pointDeVente, numeroSequence)
 * sérialise l'attribution de séquence (pas de trou, pas de doublon).
 */
final class ScellementHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SignataireOperation $signataire,
    ) {
    }

    /**
     * Scelle l'opération et persiste le maillon (sans flush : la transaction est portée par l'appelant).
     */
    public function sceller(OperationAScellerDto $op): OperationScellee
    {
        $precedente = $this->dernierMaillon($op->pointDeVente);
        $operation = $this->signataire->scelle($op, $precedente);
        $this->em->persist($operation);

        return $operation;
    }

    public function dernierMaillon(PointDeVente $pointDeVente): ?OperationScellee
    {
        return $this->em->getRepository(OperationScellee::class)
            ->createQueryBuilder('o')
            ->andWhere('o.pointDeVente = :pdv')
            ->setParameter('pdv', $pointDeVente->getId(), 'uuid')
            ->orderBy('o.numeroSequence', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Chaîne complète d'un point de vente, ordonnée par séquence (pour verifieChaine).
     *
     * @return list<OperationScellee>
     */
    public function chaine(PointDeVente $pointDeVente): array
    {
        /** @var list<OperationScellee> $ops */
        $ops = $this->em->getRepository(OperationScellee::class)
            ->createQueryBuilder('o')
            ->andWhere('o.pointDeVente = :pdv')
            ->setParameter('pdv', $pointDeVente->getId(), 'uuid')
            ->orderBy('o.numeroSequence', 'ASC')
            ->getQuery()
            ->getResult();

        return $ops;
    }
}
