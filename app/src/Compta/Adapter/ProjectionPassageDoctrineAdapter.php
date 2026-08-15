<?php

declare(strict_types=1);

namespace App\Compta\Adapter;

use App\Acces\Entity\Passage;
use App\Acces\Entity\Support;
use App\Acces\Enum\ResultatPassage;
use App\Compta\Port\ProjectionPassageInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Seul point de lecture d'Accès (L3) pour la reprise PCA « au passage » (RG-M6-03, §7.3 du plan) :
 * n'expose que le **cumul de passages autorisés**, jamais la jauge FMI (RG-ACC-04 « FMI ≠ cumul »).
 * Le rapprochement `Support(Accès).identifiant` ↔ `BilletSupport(M2).identifiantSupport` est un
 * simple appariement par identifiant (miroir documenté côté Accès), sans FK dure entre modules.
 */
final class ProjectionPassageDoctrineAdapter implements ProjectionPassageInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function compterPassagesAutorises(string $identifiantSupport, \DateTimeImmutable $depuis): int
    {
        if ($identifiantSupport === '') {
            return 0;
        }

        $support = $this->em->getRepository(Support::class)->findOneBy(['identifiant' => $identifiantSupport]);
        if ($support === null) {
            return 0;
        }

        return (int) $this->em->getRepository(Passage::class)->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('IDENTITY(p.support) = :support')
            ->andWhere('p.resultat = :autorise')
            ->andWhere('p.horodatage >= :depuis')
            ->setParameter('support', $support->getId(), 'uuid')
            ->setParameter('autorise', ResultatPassage::Valide)
            ->setParameter('depuis', $depuis)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
