<?php

declare(strict_types=1);

namespace App\Sport\Service;

use App\Securite\Entity\Utilisateur;
use App\Sport\Compta\Port\ProjectionEcritureSepaInterface;
use App\Sport\Entity\IncidentPrelevement;
use App\Sport\Enum\CanalResolutionImpaye;
use App\Sport\Enum\StatutAbonnementFitness;
use App\Sport\Enum\StatutIncidentPrelevement;
use App\Sport\Paiement\Port\EncaissementImmediatInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Résolution 1 clic (US-SPORT-07, RG-SPORT-03, CA-8) : encaissement CB immédiat, résolution de
 * l'incident, réactivation de l'abonnement et **restauration automatique de l'accès sans intervention
 * d'un agent**.
 */
final class ResolutionImpayeHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EncaissementImmediatInterface $encaissement,
        private readonly PropagationAccesFitnessHandler $propagation,
        private readonly ProjectionEcritureSepaInterface $projectionCompta,
    ) {
    }

    public function resoudre(IncidentPrelevement $incident): IncidentPrelevement
    {
        if ($incident->getStatut() === StatutIncidentPrelevement::Resolu) {
            throw new UnprocessableEntityHttpException('Incident déjà résolu.');
        }

        $initiation = $this->encaissement->initierPaiement($incident->getId(), $incident->getMontantCentimes());
        $confirmation = $this->encaissement->confirmerPaiement($initiation->referenceTransaction);
        if (!$confirmation->confirme) {
            throw new UnprocessableEntityHttpException('Encaissement non confirmé, impayé non résolu.');
        }

        $incident->setStatut(StatutIncidentPrelevement::Resolu)
            ->setCanalResolution(CanalResolutionImpaye::App1Clic)
            ->setDateResolution($confirmation->dateConfirmation);

        $abonnement = $incident->getAbonnement();
        $abonnement->setStatut(StatutAbonnementFitness::Actif);

        $this->em->flush();

        $this->propagation->activer($abonnement);
        $this->projectionCompta->enregistrerEncaissement($abonnement->getEtablissement()->getId(), $abonnement->getId(), $incident->getMontantCentimes(), $confirmation->dateConfirmation, 'resolution_1_clic');

        return $incident;
    }

    /** Réouverture forcée par un agent habilité (motif requis, traçabilité RG-SOCLE-07). */
    public function forcerReouverture(IncidentPrelevement $incident, Utilisateur $agent, string $motif): IncidentPrelevement
    {
        if (trim($motif) === '') {
            throw new UnprocessableEntityHttpException('Un motif est requis pour une réouverture forcée (RG-SOCLE-07).');
        }

        $incident->setReouvertureForceePar($agent)->setMotifReouvertureForcee($motif);

        $abonnement = $incident->getAbonnement();
        $abonnement->setStatut(StatutAbonnementFitness::Actif);

        $this->em->flush();

        $this->propagation->activer($abonnement);

        return $incident;
    }
}
