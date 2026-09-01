<?php

declare(strict_types=1);

namespace App\Sport\Service;

use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\Offre\Entity\Formule;
use App\Offre\Enum\Canal;
use App\Offre\Service\SubscriptionPriceResolver;
use App\Organisation\Entity\Etablissement;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Enum\StatutMandatSepa;
use App\Sepa\Port\TokenisationIbanInterface;
use App\Sepa\Service\ChiffreurIbanInterface;
use App\Sport\Entity\AbonnementFitness;
use App\Sport\Entity\StatutAccesFitness;
use App\Sport\Enum\PeriodiciteAbonnementFitness;
use App\Sport\Enum\StatutAbonnementFitness;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Souscription d'un abonnement fitness (US-SPORT-01, CA-1). Crée l'abonnement, tokenise l'IBAN
 * (jamais persisté en clair) et signe le mandat, génère l'échéancier jusqu'à la fin d'engagement, et
 * ouvre un `StatutAccesFitness` (droit d'accès rattaché ultérieurement, §0 point 5 du plan).
 */
final class SouscriptionAbonnementHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TokenisationIbanInterface $tokenisation,
        private readonly ChiffreurIbanInterface $chiffreur,
        private readonly GenerateurEcheancierHandler $echeancier,
        private readonly SubscriptionPriceResolver $resolveurTarif,
    ) {
    }

    public function souscrire(
        Beneficiaire $adherent,
        Client $payeur,
        Formule $formule,
        Etablissement $etablissement,
        PeriodiciteAbonnementFitness $periodicite,
        \DateTimeImmutable $dateSouscription,
        int $dureeEngagementMois,
        string $ibanClair,
        string $titulaireMandat,
        // Le demi-mois d'une souscription en cours de période. `null` : toutes au même montant.
        ?int $montantPremiereCentimes = null,
    ): AbonnementFitness {
        // ── LE PRIX VIENT DU TARIF, PLUS DE L'APPELANT ────────────────────────────────────────
        //
        // ⚠ ARBITRAGE DE MAXIME, 01/09 : « il ne doit pas y avoir de prix libre. » Le guichet
        //    recevait `montantCentimes` en champ libre et strictement positif ; il résout
        //    désormais depuis la grille tarifaire, exactement comme la boutique.
        //
        // ⚠ RÉSOLU À LA DATE DE SOUSCRIPTION, ET NON À MAINTENANT — c'est une différence assumée
        //    avec le chemin en ligne, où la vente EST maintenant et où aucune autre date n'existe.
        //    Ici la date du contrat est connue et c'est elle qui fait foi : le tarif applicable est
        //    celui en vigueur quand l'adhérent signe, pas celui du jour où on saisit.
        $tarif = $this->resolveurTarif->forFormula($formule, Canal::Guichet, $dateSouscription);
        $montantCentimes = $tarif->priceCents();

        // ⚠ LE PRORATA RESTE FOURNI, MAIS IL NE PEUT PLUS ÊTRE UN PRIX. Borné au tarif résolu, il
        //    ne sait qu'en RETRANCHER — un demi-mois, un mois offert. Au-delà, ce serait un prix
        //    libre déguisé en première échéance, ce que l'arbitrage refuse.
        //
        //    ⚠ Ce que ça ne règle PAS, et qui reste à décider : le prorata devrait être CALCULÉ,
        //    pas saisi. `App\Subscription\Service\ProrationCalculator` sait le faire — en
        //    centimes entiers, journée d'entrée due en entier — mais il vit dans la facturation de
        //    la plateforme, pas des adhérents. L'extraire, le réutiliser ou le dupliquer se décide ;
        //    le dupliquer est la seule option clairement mauvaise.
        if ($montantPremiereCentimes !== null && $montantPremiereCentimes > $montantCentimes) {
            throw new UnprocessableEntityHttpException(sprintf(
                'La première échéance (%d c) ne peut pas dépasser le tarif résolu (%d c) : '
                . 'un prorata retranche, il ne fixe pas un prix.',
                $montantPremiereCentimes,
                $montantCentimes,
            ));
        }
        $abonnement = new AbonnementFitness();
        $abonnement->setAdherent($adherent)
            ->setPayeur($payeur)
            ->setFormule($formule)
            ->setEtablissement($etablissement)
            ->setPeriodicite($periodicite)
            ->setStatut(StatutAbonnementFitness::Actif)
            ->setDateSouscription($dateSouscription)
            ->setDateDebutEngagement($dateSouscription)
            ->setDateFinEngagement($dateSouscription->modify(sprintf('+%d months', $dureeEngagementMois)));

        $engagement = $formule->getEngagement() ?? [];
        $preavis = (int) ($engagement['resiliation'] ?? 30);
        $abonnement->setPreavisResiliationJours($preavis);

        // `MandatSepa` (module partagé `App\Sepa`) est générique : rattaché au client + établissement
        // directement, plus de cycle 1:1 à casser côté mandat (contrairement à l'ancien
        // `MandatSepaFitness.abonnementRattache`) — le mandat est inséré une seule fois.
        $token = $this->tokenisation->tokeniser($ibanClair);
        $mandat = new MandatSepa();
        $mandat->setRum($this->genererRum($abonnement))
            ->setIbanToken($token->token)
            ->setIban4Derniers($token->quatreDerniers)
            ->setIbanChiffre($this->chiffreur->chiffrer($ibanClair))
            ->setDebiteurNom($titulaireMandat)
            ->setDateSignature($dateSouscription)
            ->setStatut(StatutMandatSepa::Actif)
            ->setClient($payeur)
            ->setEtablissement($etablissement);
        $this->em->persist($mandat);
        $this->em->flush();

        $abonnement->setMandatSepa($mandat);
        $this->em->persist($abonnement);

        // ⚠ LE MONTANT COURANT VA SUR L'ABONNEMENT, LE PRORATA N'Y VA PAS. L'abonnement porte ce
        //    qu'on facturera la prochaine fois — un demi-mois d'entrée ne dit rien du prix.
        $abonnement->setMontantCentimes($montantCentimes);

        $this->echeancier->generer($abonnement, $montantCentimes, $montantPremiereCentimes);

        $statutAcces = new StatutAccesFitness();
        $statutAcces->setAbonnement($abonnement)->setActif(true);
        $this->em->persist($statutAcces);

        $this->em->flush();

        return $abonnement;
    }

    private function genererRum(AbonnementFitness $abonnement): string
    {
        return 'RUM-' . strtoupper(substr(hash('sha256', (string) $abonnement->getId() . microtime()), 0, 20));
    }
}
