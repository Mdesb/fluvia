<?php

declare(strict_types=1);

namespace App\Reporting\Projection\Doctrine;

use App\Reporting\Projection\ProjectionRecouvrementInterface;
use App\Reporting\ValueObject\Periode;
use App\Recouvrement\Entity\IncidentImpaye;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Adaptateur Doctrine par défaut de `ProjectionRecouvrementInterface` (§2.1 plan-reporting.md).
 * Lit `IncidentImpaye` (module Recouvrement) — jamais d'écriture.
 */
final class ProjectionRecouvrementDoctrine implements ProjectionRecouvrementInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function impayes(Uuid $etablissementId, Periode $periode): array
    {
        $resultat = $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(i.montantCentimes), 0) AS montant', 'COUNT(i.id) AS nombre')
            ->from(IncidentImpaye::class, 'i')
            ->where('i.etablissement = :etablissement')
            ->andWhere('i.dateRejet >= :debut')
            ->andWhere('i.dateRejet <= :fin')
            ->setParameter('etablissement', $etablissementId, 'uuid')
            ->setParameter('debut', $periode->debut)
            ->setParameter('fin', $periode->fin)
            ->getQuery()
            ->getSingleResult();

        return [
            'montant' => number_format(((int) $resultat['montant']) / 100, 2, '.', ''),
            'nombre' => (int) $resultat['nombre'],
        ];
    }
}
