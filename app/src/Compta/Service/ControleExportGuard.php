<?php

declare(strict_types=1);

namespace App\Compta\Service;

use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\StatutEcriture;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Contrôle pré-export (RG-EXPORT-07, CA-10/CA-11) : équilibre + statut validée uniquement, sur la
 * période bornée. Échec → liste d'anomalies, export bloqué.
 */
final class ControleExportGuard
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return list<EcritureComptable> */
    public function ecrituresValidees(ProfilExploitant $profil, \DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        return $this->em->getRepository(EcritureComptable::class)->createQueryBuilder('e')
            ->andWhere('IDENTITY(e.profilExploitant) = :profil')
            ->andWhere('e.dateEcriture >= :debut')
            ->andWhere('e.dateEcriture <= :fin')
            ->andWhere('e.statut IN (:statuts)')
            ->setParameter('profil', $profil->getId(), 'uuid')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->setParameter('statuts', [StatutEcriture::Validee, StatutEcriture::Exportee])
            ->getQuery()
            ->getResult();
    }

    /** @return list<string> anomalies (vide = contrôle OK) */
    public function anomalies(ProfilExploitant $profil, \DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $toutes = $this->em->getRepository(EcritureComptable::class)->createQueryBuilder('e')
            ->andWhere('IDENTITY(e.profilExploitant) = :profil')
            ->andWhere('e.dateEcriture >= :debut')
            ->andWhere('e.dateEcriture <= :fin')
            ->setParameter('profil', $profil->getId(), 'uuid')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getResult();

        $anomalies = [];
        foreach ($toutes as $ecriture) {
            \assert($ecriture instanceof EcritureComptable);
            if (!$ecriture->estEquilibree()) {
                $anomalies[] = sprintf('Écriture %s déséquilibrée.', $ecriture->getId());
            }
            if (!\in_array($ecriture->getStatut(), [StatutEcriture::Validee, StatutEcriture::Exportee], true)) {
                $anomalies[] = sprintf('Écriture %s non validée (statut « %s »).', $ecriture->getId(), $ecriture->getStatut()->value);
            }
        }

        return $anomalies;
    }
}
