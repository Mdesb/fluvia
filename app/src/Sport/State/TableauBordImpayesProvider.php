<?php

declare(strict_types=1);

namespace App\Sport\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Organisation\Entity\Etablissement;
use App\Securite\Service\ContexteEtablissement;
use App\Sport\ApiResource\TableauBordImpayes;
use App\Sport\Entity\IncidentPrelevement;
use App\Sport\Entity\StatutAccesFitness;
use App\Sport\Enum\CanalResolutionImpaye;
use App\Sport\Enum\MotifInactiviteAccesFitness;
use App\Sport\Enum\StatutIncidentPrelevement;
use Doctrine\ORM\EntityManagerInterface;

/**
 * GET /sport/tableau-bord-impayes (US-SPORT-11, CA-13). Agrège par établissement actif (RG-SOCLE-05).
 *
 * @implements ProviderInterface<TableauBordImpayes>
 */
final class TableauBordImpayesProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TableauBordImpayes
    {
        $vue = new TableauBordImpayes();
        $etablissement = $this->contexte->etablissementActif();
        if (!$etablissement instanceof Etablissement) {
            return $vue;
        }

        $vue->nbEnRepresentation = $this->compterIncidents($etablissement, StatutIncidentPrelevement::Representation);
        $vue->nbEnRecouvrement = $this->compterIncidents($etablissement, StatutIncidentPrelevement::Recouvrement);
        $nbResolus = $this->compterIncidents($etablissement, StatutIncidentPrelevement::Resolu);

        $vue->nbBadgesRefuses = (int) $this->em->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(StatutAccesFitness::class, 's')
            ->join('s.abonnement', 'a')
            ->andWhere('IDENTITY(a.etablissement) = :etab')
            ->andWhere('s.actif = false')
            ->andWhere('s.motifInactivite = :motif')
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            ->setParameter('motif', MotifInactiviteAccesFitness::Impaye->value)
            ->getQuery()->getSingleScalarResult();

        $totalIncidents = $vue->nbEnRepresentation + $vue->nbEnRecouvrement + $nbResolus;
        $nbApp1Clic = (int) $this->em->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(IncidentPrelevement::class, 'i')
            ->join('i.abonnement', 'a')
            ->andWhere('IDENTITY(a.etablissement) = :etab')
            ->andWhere('i.canalResolution = :canal')
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            ->setParameter('canal', CanalResolutionImpaye::App1Clic->value)
            ->getQuery()->getSingleScalarResult();

        $vue->tauxResolutionSelfService = $totalIncidents > 0 ? round($nbApp1Clic / $totalIncidents, 4) : 0.0;

        return $vue;
    }

    private function compterIncidents(Etablissement $etablissement, StatutIncidentPrelevement $statut): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(IncidentPrelevement::class, 'i')
            ->join('i.abonnement', 'a')
            ->andWhere('IDENTITY(a.etablissement) = :etab')
            ->andWhere('i.statut = :statut')
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            ->setParameter('statut', $statut->value)
            ->getQuery()->getSingleScalarResult();
    }
}
