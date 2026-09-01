<?php

declare(strict_types=1);

namespace App\Sport\Service;

use App\Sport\Entity\AbonnementFitness;
use App\Sport\Entity\EcheanceSepa;
use App\Sport\Enum\MotifInactiviteAccesFitness;
use App\Sport\Enum\PeriodiciteAbonnementFitness;
use App\Sport\Enum\StatutAbonnementFitness;
use App\Sport\Enum\StatutEcheanceSepa;
use App\Sport\Enum\TermRenewalMode;
use Doctrine\ORM\EntityManagerInterface;

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

        // ⚠ LE MONTANT VIENT DES ECHEANCES, ET ON REFUSE PLUTOT QUE DE LE DEVINER.
        //
        // Prolonger exige un montant. La seule source honnete est ce que cet abonnement a
        // reellement porte. Un abonnement sans aucune echeance ne permet pas de l'etablir : on
        // s'arrete et on le NOMME dans le rapport. Inventer un prix -- celui de la formule
        // aujourd'hui, par exemple -- preleverait un montant que personne n'a signe.
        $derniere = $this->derniereEcheance($abonnement);

        if ($derniere === null) {
            return 'sans-montant';
        }

        $increment = $abonnement->getPeriodicite() === PeriodiciteAbonnementFitness::Mensuel
            ? '+1 month'
            : '+1 week';

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
                ->setMontantCentimes($derniere->getMontantCentimes())
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
