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
        $vue->nbResolus = $this->compterIncidents($etablissement, StatutIncidentImpaye::Resolu);

        $vue->nbAccesBloques = (int) $this->em->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(IncidentImpaye::class, 'i')
            ->andWhere('IDENTITY(i.etablissement) = :etab')
            ->andWhere('i.accesBloque = true')
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            ->getQuery()->getSingleScalarResult();

        $totalIncidents = $vue->nbEnRepresentation + $vue->nbEnRecouvrement + $vue->nbResolus;
        $nbApp1Clic = (int) $this->em->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(IncidentImpaye::class, 'i')
            ->andWhere('IDENTITY(i.etablissement) = :etab')
            ->andWhere('i.canalResolution = :canal')
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            ->setParameter('canal', CanalResolutionImpaye::App1Clic->value)
            ->getQuery()->getSingleScalarResult();

        // ⚠ 0.0 SUR UN ENSEMBLE VIDE N'EST PAS UN TAUX, C'EST UNE ABSENCE DE TAUX.
        //
        // La valeur est conservee a 0.0 pour ne pas changer le type d'un champ deja consomme, mais
        // elle ne veut alors RIEN dire. C'est pour cela que les trois comptes sont exposes : un
        // appelant qui affiche ce pourcentage sans verifier que leur somme est non nulle annonce
        // « 0 % regles par le client seul » a un etablissement qui n'a jamais eu d'impaye.
        //
        // Si un jour plus personne ne lit ce champ sans le denominateur, le rendre nullable serait
        // meilleur : l'absence de donnee se dirait a la source au lieu de se deduire.
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
