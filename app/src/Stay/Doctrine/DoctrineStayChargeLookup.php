<?php

declare(strict_types=1);

namespace App\Stay\Doctrine;

use App\Stay\Entity\Stay;
use App\Stay\Entity\StayCharge;
use App\Stay\Service\StayChargeLookup;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Implémentation Doctrine de {@see StayChargeLookup}.
 *
 * **Les deux requêtes filtrent sur `establishment` en plus du séjour**, alors que `stay_id` suffirait
 * fonctionnellement. C'est délibéré (D8) : si un séjour était un jour résolu depuis une entrée client
 * sans contrôle de périmètre, ce second filtre empêcherait la fuite de se propager jusqu'aux lignes.
 * Un cloisonnement qui ne tient que par la couche du dessus n'en est pas un.
 */
final class DoctrineStayChargeLookup implements StayChargeLookup
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function findBySource(Stay $stay, string $sourceEvent, string $sourceSubjectId): ?StayCharge
    {
        return $this->em->createQuery(
            'SELECT c FROM ' . StayCharge::class . ' c
             WHERE c.stay = :stay
               AND c.establishment = :establishment
               AND c.sourceEvent = :sourceEvent
               AND c.sourceSubjectId = :sourceSubjectId',
        )
            ->setParameter('stay', $stay)
            ->setParameter('establishment', $stay->getEstablishment())
            ->setParameter('sourceEvent', $sourceEvent)
            ->setParameter('sourceSubjectId', $sourceSubjectId)
            ->setMaxResults(1)
            ->getOneOrNullResult();
    }

    /** @return list<string> */
    public function amountsOf(Stay $stay): array
    {
        /** @var list<array{amount: string}> $lignes */
        $lignes = $this->em->createQuery(
            'SELECT c.amount AS amount FROM ' . StayCharge::class . ' c
             WHERE c.stay = :stay AND c.establishment = :establishment
             ORDER BY c.occurredAt ASC',
        )
            ->setParameter('stay', $stay)
            ->setParameter('establishment', $stay->getEstablishment())
            ->getArrayResult();

        return array_map(static fn (array $ligne): string => $ligne['amount'], $lignes);
    }
}
