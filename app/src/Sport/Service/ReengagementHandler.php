<?php

declare(strict_types=1);

namespace App\Sport\Service;

use App\Sport\Entity\AbonnementFitness;
use App\Sport\Entity\MandatSepaFitness;
use App\Sport\Entity\Reengagement;
use App\Sport\Entity\StatutAccesFitness;
use App\Sport\Enum\StatutAbonnementFitness;
use App\Sport\Enum\StatutMandatSepaFitness;
use App\Sport\Sepa\Port\TokenisationIbanInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Réengagement d'un résilié (US-SPORT-04, décision actée). Crée un **nouvel** `AbonnementFitness`
 * distinct (traçabilité historique), avec un **nouvel engagement**, un **nouvel échéancier** et un
 * **nouveau mandat SEPA obligatoire** — même si un ancien mandat non révoqué existait encore.
 */
final class ReengagementHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TokenisationIbanInterface $tokenisation,
        private readonly GenerateurEcheancierHandler $echeancier,
    ) {
    }

    public function reengager(
        AbonnementFitness $ancien,
        \DateTimeImmutable $dateReengagement,
        int $dureeEngagementMois,
        int $montantCentimes,
        string $ibanClair,
        string $titulaireMandat,
    ): Reengagement {
        if ($ancien->getStatut() !== StatutAbonnementFitness::Resilie) {
            throw new UnprocessableEntityHttpException('Seul un abonnement résilié peut être réengagé.');
        }

        $nouvel = new AbonnementFitness();
        $nouvel->setAdherent($ancien->getAdherent())
            ->setPayeur($ancien->getPayeur())
            ->setFormule($ancien->getFormule())
            ->setEtablissement($ancien->getEtablissement())
            ->setPeriodicite($ancien->getPeriodicite())
            ->setStatut(StatutAbonnementFitness::Actif)
            ->setDateSouscription($dateReengagement)
            ->setDateDebutEngagement($dateReengagement)
            ->setDateFinEngagement($dateReengagement->modify(sprintf('+%d months', $dureeEngagementMois)))
            ->setPreavisResiliationJours($ancien->getPreavisResiliationJours());

        // Nouveau mandat SEPA **toujours** requis (décision actée), même si l'ancien n'est pas révoqué.
        // Ordre d'insertion contraint par le cycle 1:1 requis des deux côtés, cf. commentaire sur
        // `MandatSepaFitness.abonnementRattache` (même contrainte qu'à la souscription).
        $token = $this->tokenisation->tokeniser($ibanClair);
        $nouveauMandat = new MandatSepaFitness();
        $nouveauMandat->setRum($this->genererRum($nouvel))
            ->setIbanToken($token->token)
            ->setIban4Derniers($token->quatreDerniers)
            ->setTitulaire($titulaireMandat)
            ->setDateSignature($dateReengagement)
            ->setStatut(StatutMandatSepaFitness::Actif)
            ->setPayeur($ancien->getPayeur());
        $this->em->persist($nouveauMandat);
        $this->em->flush();

        $nouvel->setMandatSepa($nouveauMandat);
        $this->em->persist($nouvel);

        $this->echeancier->generer($nouvel, $montantCentimes);

        $statutAcces = new StatutAccesFitness();
        $statutAcces->setAbonnement($nouvel)->setActif(true);
        $this->em->persist($statutAcces);

        $reengagement = new Reengagement();
        $reengagement->setAncienAbonnement($ancien)
            ->setNouvelAbonnement($nouvel)
            ->setNouveauMandat($nouveauMandat)
            ->setDateReengagement($dateReengagement);
        $this->em->persist($reengagement);

        $this->em->flush();

        $nouveauMandat->setAbonnementRattache($nouvel);
        $this->em->flush();

        return $reengagement;
    }

    private function genererRum(AbonnementFitness $abonnement): string
    {
        return 'RUM-' . strtoupper(substr(hash('sha256', (string) $abonnement->getId() . microtime()), 0, 20));
    }
}
