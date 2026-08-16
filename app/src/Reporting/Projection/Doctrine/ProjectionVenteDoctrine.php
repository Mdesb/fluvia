<?php

declare(strict_types=1);

namespace App\Reporting\Projection\Doctrine;

use App\Reporting\Projection\ProjectionVenteInterface;
use App\Reporting\ValueObject\Periode;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutVente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Adaptateur Doctrine par défaut de `ProjectionVenteInterface` (§2.1 plan-reporting.md). Lit
 * uniquement `App\Vente\Entity\Vente` — jamais d'écriture.
 */
final class ProjectionVenteDoctrine implements ProjectionVenteInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function caEncaisse(Uuid $etablissementId, Periode $periode): string
    {
        $resultat = $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(v.total), 0)')
            ->from(Vente::class, 'v')
            ->where('v.etablissement = :etablissement')
            ->andWhere('v.statut IN (:statutsScelles)')
            ->andWhere('v.date >= :debut')
            ->andWhere('v.date <= :fin')
            ->setParameter('etablissement', $etablissementId, 'uuid')
            ->setParameter('statutsScelles', [StatutVente::Validee, StatutVente::AvoirEmis])
            ->setParameter('debut', $periode->debut)
            ->setParameter('fin', $periode->fin)
            ->getQuery()
            ->getSingleScalarResult();

        return number_format((float) $resultat, 2, '.', '');
    }
}
