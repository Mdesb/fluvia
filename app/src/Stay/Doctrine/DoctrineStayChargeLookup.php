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
 * **Les paramètres sont liés par `IDENTITY(...)` et le type `uuid` explicite, jamais en passant
 * l'entité.** Ce n'est pas une préférence de style : lier l'entité directement sur un identifiant
 * `BINARY(16)` produit une comparaison qui **ne remonte aucune ligne, sans lever la moindre erreur**.
 * Le symptôme est une note vide et une idempotence muette — la pire forme d'échec, puisque tout a
 * l'air de fonctionner. Découvert le 24/08 par le test d'intégration : les tests unitaires ne
 * pouvaient pas le voir, ils passent par un double de `StayChargeLookup`. C'est aussi l'idiome des
 * extensions de périmètre du dépôt (`setParameter(..., $id, 'uuid')`).
 *
 * **Les deux requêtes filtrent sur `establishment` en plus du séjour**, alors que `stay_id` suffirait
 * fonctionnellement. C'est délibéré (D8) : si un séjour était un jour résolu depuis une entrée client
 * sans contrôle de périmètre, ce second filtre empêcherait la fuite de se propager jusqu'aux lignes.
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
             WHERE IDENTITY(c.stay) = :stay
               AND IDENTITY(c.establishment) = :establishment
               AND c.sourceEvent = :sourceEvent
               AND c.sourceSubjectId = :sourceSubjectId',
        )
            ->setParameter('stay', $stay->getId(), 'uuid')
            ->setParameter('establishment', $stay->getEstablishment()->getId(), 'uuid')
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
             WHERE IDENTITY(c.stay) = :stay AND IDENTITY(c.establishment) = :establishment
             ORDER BY c.occurredAt ASC',
        )
            ->setParameter('stay', $stay->getId(), 'uuid')
            ->setParameter('establishment', $stay->getEstablishment()->getId(), 'uuid')
            ->getArrayResult();

        return array_map(static fn (array $ligne): string => $ligne['amount'], $lignes);
    }

    /** @return list<array{label: string, amount: string, occurredAt: \DateTimeImmutable, sourceModule: string}> */
    public function linesOf(Stay $stay): array
    {
        /** @var list<array{label: string, amount: string, occurredAt: \DateTimeImmutable, sourceModule: string}> $lignes */
        $lignes = $this->em->createQuery(
            'SELECT c.label AS label, c.amount AS amount, c.occurredAt AS occurredAt, c.sourceModule AS sourceModule
             FROM ' . StayCharge::class . ' c
             WHERE IDENTITY(c.stay) = :stay AND IDENTITY(c.establishment) = :establishment
             ORDER BY c.occurredAt ASC',
        )
            ->setParameter('stay', $stay->getId(), 'uuid')
            ->setParameter('establishment', $stay->getEstablishment()->getId(), 'uuid')
            ->getArrayResult();

        return $lignes;
    }
}
