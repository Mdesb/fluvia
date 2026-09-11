<?php

declare(strict_types=1);

namespace App\Membership\Service;

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
use App\Membership\Entity\Membership;
use App\Membership\Entity\StatutAccesFitness;
use App\Membership\Enum\MembershipPeriodicity;
use App\Membership\Enum\MembershipStatus;
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
        \DateTimeImmutable $dateSouscription,
        // ⚠ LA DURÉE N'EST QU'UN REPLI DEPUIS LE 06/09. Quand la formule déclare
        //    `engagement.dureeMin`, c'est elle qui décide — le contrat gèle les termes de
        //    l'offre. Aucune formule ne le déclare aujourd'hui (`engagement` est NULL sur les
        //    quatre), et aucun écran ne permet de l'écrire : ce paramètre reste donc le seul
        //    chemin réel, jusqu'à ce que la fiche produit sache poser l'engagement.
        int $dureeEngagementMois,
        // ⚠ NULLABLES DEPUIS LE 06/09 : LE CHEMIN EN LIGNE SIGNE SON MANDAT AVANT LA VENTE.
        //    Le client saisit son IBAN dans le tunnel, bien avant qu'un abonnement existe. Repasser
        //    cet IBAN ici créerait un SECOND mandat sur le même client et le même établissement :
        //    deux RUM pour un seul contrat, et le rapprochement bancaire ne dirait plus lequel a
        //    prélevé.
        ?string $ibanClair = null,
        ?string $titulaireMandat = null,
        // Le demi-mois d'une souscription en cours de période. `null` : toutes au même montant.
        ?int $montantPremiereCentimes = null,
        // Le canal décide du TARIF : un produit peut être vendable en ligne et invisible au guichet.
        Canal $canal = Canal::Guichet,
        // Le mandat déjà signé, quand il y en a un. Exclusif avec l'IBAN ci-dessus.
        ?MandatSepa $mandatExistant = null,
    ): Membership {
        if ($mandatExistant === null && ($ibanClair === null || $titulaireMandat === null)) {
            throw new UnprocessableEntityHttpException(
                'Un mandat SEPA est requis : soit un mandat déjà signé, soit un IBAN et son titulaire.',
            );
        }
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
        $tarif = $this->resolveurTarif->forFormula($formule, $canal, $dateSouscription);
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
        // ── LE CONTRAT GÈLE LES TERMES DE L'OFFRE ────────────────────────────────────────
        //
        // ⚠ LA CADENCE VENAIT DU CORPS DE LA REQUÊTE, ET LA FORMULE ÉTAIT IGNORÉE. Mesuré le
        //    06/09 : `Formule::$periodicite` est éditable dans l'écran produit et lue par
        //    PERSONNE — tous les `getPeriodicite()` du dépôt lisent un abonnement ou un rapport,
        //    jamais une formule. Une formule déclarée ANNUELLE souscrite ici devenait MENSUELLE,
        //    en silence, et le vendeur ne pouvait pas s'en apercevoir.
        //
        //    Le préavis, lui, était gelé depuis toujours (`engagement.resiliation`). C'est le même
        //    geste, étendu : ce qui définit l'offre appartient à l'offre.
        $declaree = $formule->getPeriodicite();
        if ($declaree === null) {
            throw new UnprocessableEntityHttpException(
                'Cette formule ne déclare aucune périodicité : renseignez-la dans la fiche produit. '
                . "La cadence d'un abonnement n'est plus saisie à la souscription, elle vient de l'offre.",
            );
        }

        // ⚠ `Personnalise` EST REFUSÉ, PAS TRADUIT. Ce cas n'est employé nulle part dans le dépôt ;
        //    lui choisir une cadence ici inventerait une règle commerciale et l'appliquerait en
        //    silence à toutes les souscriptions d'une telle formule.
        $periodicite = MembershipPeriodicity::depuisFormule($declaree);
        if ($periodicite === null) {
            throw new UnprocessableEntityHttpException(sprintf(
                'La périodicité « %s » de cette formule n\'a pas de cadence de prélèvement définie. '
                . 'Choisissez « mensuel » ou « annuel » dans la fiche produit.',
                $declaree->value,
            ));
        }

        $engagement = $formule->getEngagement() ?? [];

        // La durée déclarée par l'offre prime ; le paramètre reste le repli tant qu'aucun écran ne
        // sait poser `engagement.dureeMin` — il est NULL sur les quatre formules de préproduction.
        $duree = isset($engagement['dureeMin']) && is_numeric($engagement['dureeMin'])
            ? (int) $engagement['dureeMin']
            : $dureeEngagementMois;

        $abonnement = new Membership();
        $abonnement->setAdherent($adherent)
            ->setPayeur($payeur)
            ->setFormule($formule)
            ->setEtablissement($etablissement)
            ->setPeriodicite($periodicite)
            ->setStatut(MembershipStatus::Active)
            ->setDateSouscription($dateSouscription)
            ->setDateDebutEngagement($dateSouscription)
            ->setDateFinEngagement($dateSouscription->modify(sprintf('+%d months', $duree)));

        $preavis = (int) ($engagement['resiliation'] ?? 30);
        $abonnement->setPreavisResiliationJours($preavis);

        // `MandatSepa` (module partagé `App\Sepa`) est générique : rattaché au client + établissement
        // directement, plus de cycle 1:1 à casser côté mandat (contrairement à l'ancien
        // `MandatSepaFitness.abonnementRattache`) — le mandat est inséré une seule fois.
        // ⚠ UN MANDAT DÉJÀ SIGNÉ SE REPREND, IL NE SE REFAIT PAS. Le tunnel en ligne fait saisir
        //    l'IBAN au client avant qu'un abonnement existe ; en signer un second ici mettrait deux
        //    RUM actifs sur le même client et le même établissement, et le rapprochement bancaire ne
        //    dirait plus lequel a prélevé.
        if ($mandatExistant instanceof MandatSepa) {
            $mandat = $mandatExistant;
        } else {
            $token = $this->tokenisation->tokeniser((string) $ibanClair);
            $mandat = new MandatSepa();
            $mandat->setRum($this->genererRum($abonnement))
                ->setIbanToken($token->token)
                ->setIban4Derniers($token->quatreDerniers)
                ->setIbanChiffre($this->chiffreur->chiffrer((string) $ibanClair))
                ->setDebiteurNom((string) $titulaireMandat)
                ->setDateSignature($dateSouscription)
                ->setStatut(StatutMandatSepa::Actif)
                ->setClient($payeur)
                ->setEtablissement($etablissement);
            $this->em->persist($mandat);
            $this->em->flush();
        }

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

    private function genererRum(Membership $abonnement): string
    {
        return 'RUM-' . strtoupper(substr(hash('sha256', (string) $abonnement->getId() . microtime()), 0, 20));
    }
}
