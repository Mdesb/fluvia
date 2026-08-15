<?php

declare(strict_types=1);

namespace App\Sport\Service;

use App\Organisation\Entity\Etablissement;
use App\Sport\Entity\EcheanceSepa;
use App\Sport\Entity\RemiseSepa;
use App\Sport\Enum\StatutEcheanceSepa;
use App\Sport\Enum\StatutRemiseSepa;
use App\Sport\Sepa\Port\CollecteurSepaInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Génère et transmet une remise (lot pain.008 simulé) pour toutes les échéances à venir jusqu'à une
 * date donnée (§2.1 du plan). Aucune remise bancaire réelle n'est effectuée (Risque n°2, stub).
 */
final class GenererRemiseSepaHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CollecteurSepaInterface $collecteur,
    ) {
    }

    public function generer(Etablissement $etablissement, \DateTimeImmutable $dateExecutionPrevue): RemiseSepa
    {
        /** @var list<EcheanceSepa> $echeances */
        $echeances = $this->em->getRepository(EcheanceSepa::class)->createQueryBuilder('e')
            ->join('e.abonnement', 'a')
            ->andWhere('IDENTITY(a.etablissement) = :etab')
            ->andWhere('e.statut = :av')
            ->andWhere('e.dateProgrammee <= :date')
            ->setParameter('etab', $etablissement->getId(), 'uuid')
            ->setParameter('av', StatutEcheanceSepa::AVenir->value)
            ->setParameter('date', $dateExecutionPrevue, 'date_immutable')
            ->getQuery()->getResult();

        $remise = new RemiseSepa();
        $remise->setEtablissement($etablissement)
            ->setDateGeneration(new \DateTimeImmutable())
            ->setDateExecutionPrevue($dateExecutionPrevue)
            ->setStatut(StatutRemiseSepa::Brouillon);
        $this->em->persist($remise);
        $this->em->flush();

        $resultat = $this->collecteur->genererRemise($remise, $echeances);
        $remise->setReferenceRemise($resultat->referenceRemise)
            ->setNbEcheances($resultat->nbEcheances)
            ->setMontantTotalCentimes($resultat->montantTotalCentimes)
            ->setStatut(StatutRemiseSepa::Generee);

        foreach ($echeances as $echeance) {
            $echeance->setRemise($remise);
            $echeance->setStatut(StatutEcheanceSepa::Prelevee);
            $echeance->setDateExecutionReelle(new \DateTimeImmutable());
        }

        $this->collecteur->transmettre($remise);
        $remise->setStatut(StatutRemiseSepa::Transmise);

        $this->em->flush();

        return $remise;
    }
}
