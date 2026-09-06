<?php

declare(strict_types=1);

namespace App\Sport\Service;

use App\Offre\Enum\Canal;
use App\Offre\Service\SubscriptionPriceResolver;
use App\Sport\Entity\AbonnementFitness;
use App\Sport\Entity\EcheanceSepa;
use App\Sport\Enum\MotifInactiviteAccesFitness;
use App\Sport\Enum\PeriodiciteAbonnementFitness;
use App\Sport\Enum\StatutAbonnementFitness;
use App\Sport\Enum\StatutEcheanceSepa;
use App\Sport\Enum\TermRenewalMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * CE QUI ARRIVE A UN ABONNEMENT LE JOUR OU SON ENGAGEMENT SE TERMINE.
 *
 * Avant ce handler, la reponse etait : rien. Les echeances s'arretaient a `dateFinEngagement`, le
 * prelevement cessait, le statut restait « actif » et l'acces restait valide -- **l'adherent
 * continuait d'entrer gratuitement** jusqu'a ce qu'un humain s'en apercoive et resilie a la main.
 *
 * ⚠ ET LE CHEMIN DE RENOUVELLEMENT EXISTAIT DEJA, INATTEIGNABLE. `ReengagementHandler` exige
 * `StatutAbonnementFitness::Resilie` et leve sinon. Rien ne faisait sortir un abonnement de
 * « actif » a son terme : ce n'etait pas un mecanisme manquant, c'etait une TRANSITION manquante.
 *
 * ── L'IDEMPOTENCE EST LE POINT DUR, ET C'EST DE L'ARGENT ────────────────────────────────────────
 *
 * `GenerateurEcheancierHandler::generer()` persiste sans garde : deux appels doublent l'echeancier,
 * donc doublent les prelevements. Ce handler tourne dans une tache planifiee, donc potentiellement
 * deux fois si un cycle deborde. On ne genere donc JAMAIS une fenetre : on genere strictement
 * apres la derniere echeance existante, quelle qu'elle soit.
 */
final class SubscriptionTermHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PropagationAccesFitnessHandler $propagation,
        private readonly SubscriptionPriceResolver $resolveurTarif,
    ) {
    }

    /**
     * @return string ce qui a ete fait, pour le rapport de la commande
     */
    public function process(AbonnementFitness $abonnement, \DateTimeImmutable $maintenant): string
    {
        if ($abonnement->getStatut() !== StatutAbonnementFitness::Actif) {
            return 'ignore';
        }

        if ($abonnement->getDateFinEngagement() > $maintenant) {
            return 'en-cours';
        }

        $mode = TermRenewalMode::forFormule($abonnement->getFormule());

        if ($mode === TermRenewalMode::Suspend) {
            $abonnement->setStatut(StatutAbonnementFitness::Echu);
            $this->propagation->desactiver($abonnement, MotifInactiviteAccesFitness::Terme);
            $this->em->flush();

            return 'suspendu';
        }

        // ⚠ ON A TOUJOURS BESOIN DE LA DERNIERE ECHEANCE — POUR LA DATE, PLUS POUR LE PRIX.
        //
        // Elle sert desormais uniquement de point de reprise de l'echeancier (voir la garde
        // d'idempotence plus bas). Un abonnement sans aucune echeance ne permet pas de savoir OU
        // reprendre : on s'arrete et on le NOMME dans le rapport.
        $derniere = $this->derniereEcheance($abonnement);

        if ($derniere === null) {
            return 'sans-montant';
        }

        // ── LE PRIX SE RELIT AU TARIF EN VIGUEUR, LA DUREE NON — ET CE N'EST PAS UNE INCOHERENCE ──
        //
        // Arbitrage de Maxime, le 03/09 : « reconduction au tarif en vigueur ». Il partait d'un cas
        // concret : « ils font une augmentation de tarifs, ils doivent pouvoir mettre la date a
        // laquelle le nouveau tarif va s'appliquer ». Le mecanisme de date existe deja — une saison
        // neuve et une grille dessus — mais il n'atteignait jamais un abonnement en cours.
        //
        // ⚠ LE COMMENTAIRE PRECEDENT DISAIT L'INVERSE, ET IL AVAIT RAISON POUR SON EPOQUE : « inventer
        // un prix -- celui de la formule aujourd'hui -- preleverait un montant que personne n'a
        // signe ». Ce qui a change n'est pas le raisonnement, c'est la decision produit : reconduire,
        // c'est accepter le tarif publie au jour de la reconduction.
        //
        // ⚠ ET LA DUREE, ELLE, NE SE RELIT TOUJOURS PAS DANS LA FORMULE (voir `dureeEngagement()`).
        // La distinction tient en une phrase, et sans elle quelqu'un alignera l'un sur l'autre dans
        // six mois : LA DUREE EST CONTRACTUELLE — l'adherent a signe douze mois, la formule a pu
        // passer a vingt-quatre depuis ; LE PRIX EST PUBLIE — l'exploitant le revise, et c'est
        // precisement ce qu'une grille tarifaire datee sert a faire.
        //
        // Resolu a la date de reconduction, canal Guichet : meme choix que `ReengagementHandler`,
        // pour la meme raison — c'est ce jour-la que le contrat se renoue.
        try {
            $montantCentimes = $this->resolveurTarif
                ->forFormula($abonnement->getFormule(), Canal::Guichet, $maintenant)
                ->priceCents();
        } catch (UnprocessableEntityHttpException) {
            // ⚠ ON RATTRAPE, ET C'EST LE POINT LE PLUS IMPORTANT DE CE BLOC.
            //
            // `SubscriptionPriceResolver` LEVE quand rien ne resout : formule sans produit porteur,
            // produit sans facette SEPA, ou aucune saison ne couvrant la date. Or ce handler tourne
            // dans `sport:abonnements:traiter-terme`, qui traite TOUS les abonnements arrivant a
            // terme. Une exception non rattrapee sur un seul abonnement mal tarife arreterait le
            // lot : les suivants ne seraient pas reconduits, et personne ne le saurait avant le
            // releve bancaire.
            //
            // Un abonne dont le tarif n'existe plus est un cas REEL — le produit a pu etre retire
            // de la vente. Il doit produire une ligne de rapport, pas une panne. C'est l'idiome que
            // ce handler emploie deja avec `suspendu` et `sans-montant`.
            return 'sans-tarif';
        }

        // ⚠ MÊME PIÈGE QUE DANS LE GÉNÉRATEUR D'ÉCHÉANCIER : ce ternaire rendait hebdomadaire tout
        //    ce qui n'était pas mensuel. Un abonnement annuel se serait reconduit d'une semaine.
        $increment = $abonnement->getPeriodicite()->increment();

        $ancienneFin = $abonnement->getDateFinEngagement();
        $nouvelleFin = $mode === TermRenewalMode::Renew
            ? $ancienneFin->modify($this->dureeEngagement($abonnement))
            : $ancienneFin->modify($increment);

        $abonnement->setDateFinEngagement($nouvelleFin);

        // ── LA GARDE D'IDEMPOTENCE ─────────────────────────────────────────────────────────────
        //
        // On repart de la DERNIERE echeance existante, pas de l'ancienne fin d'engagement. Si un
        // passage precedent a deja genere une partie de la fenetre, on ne la regenere pas : on
        // reprend la ou il s'est arrete. Un second passage sur un abonnement deja traite ne cree
        // donc rien du tout.
        $date = $derniere->getDateProgrammee()->modify($increment);
        $creees = 0;

        while ($date < $nouvelleFin) {
            $echeance = new EcheanceSepa();
            $echeance->setAbonnement($abonnement)
                ->setDateProgrammee($date)
                ->setMontantCentimes($montantCentimes)
                ->setStatut(StatutEcheanceSepa::AVenir);
            $this->em->persist($echeance);
            ++$creees;

            $date = $date->modify($increment);
        }

        $this->em->flush();

        // ⚠ ZERO ECHEANCE CREEE N'EST PAS UNE MENSUALISATION.
        //
        // Ca arrive quand l'echeancier existant depasse deja la nouvelle fin -- apres une pause qui
        // a repousse le terme, ou un traitement partiel. La date bouge, rien n'est preleve, et
        // annoncer « mensualise » ferait croire a l'exploitant que l'abonnement repart.
        //
        // Trouve par le temoin positif du test d'idempotence : sans lui, « aucun doublon » aurait
        // ete vert pour la pire des raisons -- un handler qui ne cree jamais rien ne double jamais.
        if ($creees === 0) {
            return 'deja-couvert';
        }

        return $mode === TermRenewalMode::Renew
            ? sprintf('reconduit (%d echeance(s))', $creees)
            : sprintf('mensualise (%d echeance(s))', $creees);
    }

    /**
     * La duree de l'engagement d'origine, pour reconduire a l'identique.
     *
     * Elle se DEDUIT des dates portees par l'abonnement plutot que de se relire dans la formule :
     * la formule a pu changer depuis la souscription, et reconduire sur une duree que l'adherent
     * n'a jamais signee serait pire que de ne pas reconduire.
     */
    private function dureeEngagement(AbonnementFitness $abonnement): string
    {
        $mois = $abonnement->getDateDebutEngagement()->diff($abonnement->getDateFinEngagement());
        $total = ($mois->y * 12) + $mois->m;

        // Un engagement de moins d'un mois plein retombe sur un mois : il n'y a pas d'engagement
        // « de zero mois », et une reconduction de duree nulle bouclerait sans fin.
        return sprintf('+%d months', max(1, $total));
    }

    private function derniereEcheance(AbonnementFitness $abonnement): ?EcheanceSepa
    {
        return $this->em->getRepository(EcheanceSepa::class)->findOneBy(
            ['abonnement' => $abonnement],
            ['dateProgrammee' => 'DESC'],
        );
    }
}
