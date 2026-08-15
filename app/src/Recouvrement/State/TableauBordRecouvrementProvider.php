<?php

declare(strict_types=1);

namespace App\Recouvrement\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Organisation\Entity\Etablissement;
use App\Recouvrement\ApiResource\TableauBordRecouvrement;
use App\Recouvrement\Entity\IncidentImpaye;
use App\Recouvrement\Enum\CanalResolutionImpaye;
use App\Recouvrement\Enum\StatutIncidentImpaye;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;

/**
 * GET /recouvrement/tableau-bord. Agrège par établissement actif (RG-SOCLE-05).
 *
 * @implements ProviderInterface<TableauBordRecouvrement>
 */
final class TableauBordRecouvrementProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TableauBordRecouvrement
    {
        $vue = new TableauBordRecouvrement();
        $etablissement = $this->contexte->etablissementActif();
        if (!$etablissement instanceof Etablissement) {
            return $vue;
        }

        $vue->nbEnRepresentation = $this->compterIncidents($etablissement, StatutIncidentImpaye::Representation);
        $vue->nbEnRecouvrement = $this->compterIncidents($etablissement, StatutIncidentImpaye::Recouvrement);
        $nbResolus = $this->compterIncidents($etablissement, StatutIncidentImpaye::Resolu);

        $vue->nbAccesBloques = (int) $this->em->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(IncidentImpaye::class, 'i')
            ->andWhere('IDENTITY(i.etablissement) = :etab')
            ->andWhere('i.accesBloque = true')
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            ->getQuery()->getSingleScalarResult();

        $totalIncidents = $vue->nbEnRepresentation + $vue->nbEnRecouvrement + $nbResolus;
        $nbApp1Clic = (int) $this->em->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(IncidentImpaye::class, 'i')
            ->andWhere('IDENTITY(i.etablissement) = :etab')
            ->andWhere('i.canalResolution = :canal')
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            ->setParameter('canal', CanalResolutionImpaye::App1Clic->value)
            ->getQuery()->getSingleScalarResult();

        $vue->tauxResolutionSelfService = $totalIncidents > 0 ? round($nbApp1Clic / $totalIncidents, 4) : 0.0;

        return $vue;
    }

    private function compterIncidents(Etablissement $etablissement, StatutIncidentImpaye $statut): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(IncidentImpaye::class, 'i')
            ->andWhere('IDENTITY(i.etablissement) = :etab')
            ->andWhere('i.statut = :statut')
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            ->setParameter('statut', $statut->value)
            ->getQuery()->getSingleScalarResult();
    }
}
