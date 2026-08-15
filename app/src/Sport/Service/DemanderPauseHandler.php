<?php

declare(strict_types=1);

namespace App\Sport\Service;

use App\Sport\Entity\AbonnementFitness;
use App\Sport\Entity\EcheanceSepa;
use App\Sport\Entity\PauseAbonnement;
use App\Sport\Enum\MotifInactiviteAccesFitness;
use App\Sport\Enum\StatutAbonnementFitness;
use App\Sport\Enum\StatutEcheanceSepa;
use App\Sport\Enum\StatutPauseAbonnement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Pause d'abonnement (US-SPORT-02, RG-SPORT-05, CA-2). Bloquée si un impayé est en cours (décision
 * actée). Gèle les échéances de la période, reporte la fin d'engagement, suspend l'accès (hypothèse
 * retenue §4.2 de la spec : une pause suspend l'accès, comme un impayé).
 */
final class DemanderPauseHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PropagationAccesFitnessHandler $propagation,
    ) {
    }

    public function demander(AbonnementFitness $abonnement, \DateTimeImmutable $debut, \DateTimeImmutable $fin, ?string $motif): PauseAbonnement
    {
        $pause = new PauseAbonnement();
        $pause->setAbonnement($abonnement)->setDateDebut($debut)->setDateFin($fin)->setMotif($motif);

        if ($abonnement->getStatut() === StatutAbonnementFitness::Impaye) {
            $pause->setStatut(StatutPauseAbonnement::Refusee);
            $this->em->persist($pause);
            $this->em->flush();

            throw new UnprocessableEntityHttpException('Pause refusée : impayé en cours non régularisé (décision actée, RG-SPORT).');
        }

        $pause->setStatut(StatutPauseAbonnement::Active);
        $this->em->persist($pause);

        /** @var list<EcheanceSepa> $echeances */
        $echeances = $this->em->getRepository(EcheanceSepa::class)->createQueryBuilder('e')
            ->andWhere('IDENTITY(e.abonnement) = :a')
            ->andWhere('e.dateProgrammee >= :debut')
            ->andWhere('e.dateProgrammee < :fin')
            ->andWhere('e.statut = :av')
            ->setParameter('a', $abonnement->getId(), 'uuid')
            ->setParameter('debut', $debut, 'date_immutable')
            ->setParameter('fin', $fin, 'date_immutable')
            ->setParameter('av', StatutEcheanceSepa::AVenir->value)
            ->getQuery()->getResult();
        foreach ($echeances as $echeance) {
            $echeance->setStatut(StatutEcheanceSepa::Gelee);
        }

        $jours = (int) $debut->diff($fin)->days;
        $abonnement->setDateFinEngagement($abonnement->getDateFinEngagement()->modify(sprintf('+%d days', $jours)));
        $abonnement->setStatut(StatutAbonnementFitness::Pause);

        $this->em->flush();

        $this->propagation->desactiver($abonnement, MotifInactiviteAccesFitness::Pause);

        return $pause;
    }
}
