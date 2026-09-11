<?php

declare(strict_types=1);

namespace App\Membership\Service;

use App\Offre\Enum\Canal;
use App\Offre\Service\SubscriptionPriceResolver;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Enum\StatutMandatSepa;
use App\Sepa\Port\TokenisationIbanInterface;
use App\Sepa\Service\ChiffreurIbanInterface;
use App\Membership\Entity\Membership;
use App\Membership\Entity\Reengagement;
use App\Membership\Entity\StatutAccesFitness;
use App\Membership\Enum\MembershipStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Réengagement d'un résilié (US-SPORT-04, décision actée). Crée un **nouvel** `Membership`
 * distinct (traçabilité historique), avec un **nouvel engagement**, un **nouvel échéancier** et un
 * **nouveau mandat SEPA obligatoire** — même si un ancien mandat non révoqué existait encore.
 */
final class ReengagementHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TokenisationIbanInterface $tokenisation,
        private readonly ChiffreurIbanInterface $chiffreur,
        private readonly GenerateurEcheancierHandler $echeancier,
        private readonly SubscriptionPriceResolver $resolveurTarif,
    ) {
    }

    public function reengager(
        Membership $ancien,
        \DateTimeImmutable $dateReengagement,
        int $dureeEngagementMois,
        string $ibanClair,
        string $titulaireMandat,
        ?int $montantPremiereCentimes = null,
    ): Reengagement {
        if ($ancien->getStatut() !== MembershipStatus::Terminated) {
            throw new UnprocessableEntityHttpException('Seul un abonnement résilié peut être réengagé.');
        }

        $nouvel = new Membership();
        $nouvel->setAdherent($ancien->getAdherent())
            ->setPayeur($ancien->getPayeur())
            ->setFormule($ancien->getFormule())
            ->setEtablissement($ancien->getEtablissement())
            ->setPeriodicite($ancien->getPeriodicite())
            ->setStatut(MembershipStatus::Active)
            ->setDateSouscription($dateReengagement)
            ->setDateDebutEngagement($dateReengagement)
            ->setDateFinEngagement($dateReengagement->modify(sprintf('+%d months', $dureeEngagementMois)))
            ->setPreavisResiliationJours($ancien->getPreavisResiliationJours());

        // Nouveau mandat SEPA **toujours** requis (décision actée), même si l'ancien n'est pas révoqué.
        // `MandatSepa` (module partagé `App\Sepa`) est générique : plus de cycle 1:1 à casser côté
        // mandat (contrairement à l'ancien `MandatSepaFitness.abonnementRattache`).
        $token = $this->tokenisation->tokeniser($ibanClair);
        $nouveauMandat = new MandatSepa();
        $nouveauMandat->setRum($this->genererRum($nouvel))
            ->setIbanToken($token->token)
            ->setIban4Derniers($token->quatreDerniers)
            ->setIbanChiffre($this->chiffreur->chiffrer($ibanClair))
            ->setDebiteurNom($titulaireMandat)
            ->setDateSignature($dateReengagement)
            ->setStatut(StatutMandatSepa::Actif)
            ->setClient($ancien->getPayeur())
            ->setEtablissement($ancien->getEtablissement());
        $this->em->persist($nouveauMandat);
        $this->em->flush();

        $nouvel->setMandatSepa($nouveauMandat);
        $this->em->persist($nouvel);

        // ── LA SECONDE PORTE TRAVERSE LE MEME PASSAGE ────────────────────────────────────────
        //
        // ⚠ Ce handler créait un mandat NEUF et un échéancier complet — douze prélèvements — avec
        //    un montant reçu en champ libre, sans lire la facette SEPA et sans résoudre aucun
        //    tarif. Exactement la forme de la souscription au guichet.
        //
        //    **Raccorder la souscription sans raccorder celui-ci aurait rouvert le trou**, et sur
        //    les adhérents qui reviennent. C'est pourquoi les deux appellent le même résolveur au
        //    lieu de recevoir deux copies de la même règle : la quatrième divergence n'apparaîtra
        //    pas au prochain ajout.
        //
        // ⚠ Résolu à la date de RÉENGAGEMENT : c'est ce jour-là que le contrat se renoue, donc le
        //    tarif applicable est celui en vigueur à ce moment — pas celui de l'abonnement d'avant,
        //    qui peut dater d'un an.
        $tarif = $this->resolveurTarif->forFormula($ancien->getFormule(), Canal::Guichet, $dateReengagement);
        $montantCentimes = $tarif->priceCents();

        if ($montantPremiereCentimes !== null && $montantPremiereCentimes > $montantCentimes) {
            throw new UnprocessableEntityHttpException(sprintf(
                'La première échéance (%d c) ne peut pas dépasser le tarif résolu (%d c) : '
                . 'un prorata retranche, il ne fixe pas un prix.',
                $montantPremiereCentimes,
                $montantCentimes,
            ));
        }

        $nouvel->setMontantCentimes($montantCentimes);

        $this->echeancier->generer($nouvel, $montantCentimes, $montantPremiereCentimes);

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

        return $reengagement;
    }

    private function genererRum(Membership $abonnement): string
    {
        return 'RUM-' . strtoupper(substr(hash('sha256', (string) $abonnement->getId() . microtime()), 0, 20));
    }
}
