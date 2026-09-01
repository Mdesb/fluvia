<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use App\Sport\Entity\AbonnementFitness;
use App\Sport\Entity\EcheanceSepa;
use App\Sport\Enum\StatutAbonnementFitness;
use App\Sport\Enum\TermRenewalMode;
use App\Sport\Service\SubscriptionTermHandler;
use App\Tests\Sport\SportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * CE QUI ARRIVE A UN ABONNEMENT AU TERME DE SON ENGAGEMENT.
 *
 * Avant ce lot : rien. Les echeances s'arretaient a la fin d'engagement, le prelevement cessait, le
 * statut restait « actif », l'acces restait valide — **l'adherent continuait d'entrer
 * gratuitement**. Aucun ecran ne pouvait meme DISTINGUER cet abonnement d'un abonnement en cours.
 *
 * ⚠ LE TEST QUI COMPTE LE PLUS EST CELUI DE L'IDEMPOTENCE, ET C'EST DE L'ARGENT.
 * `GenerateurEcheancierHandler::generer()` persiste sans garde : deux appels doublent l'echeancier,
 * donc doublent les prelevements. Ce handler tourne dans une tache planifiee — donc deux fois si un
 * cycle deborde sur le suivant.
 */
final class TermeAbonnementTest extends SportApiTestCase
{
    private EntityManagerInterface $em;
    private SubscriptionTermHandler $handler;
    private \DateTimeImmutable $reference;

    protected function setUp(): void
    {
        parent::setUp();
        $conteneur = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $conteneur->get('doctrine')->getManager();
        $this->em = $em;
        /** @var SubscriptionTermHandler $handler */
        $handler = $conteneur->get(SubscriptionTermHandler::class);
        $this->handler = $handler;
    }

    /**
     * LE DEFAUT D'ORIGINE, EN UN TEST. Un abonnement au terme ne doit plus rester « actif » sans que
     * rien ne se passe : il est soit prolonge, soit echu — jamais laisse en l'etat.
     */
    public function testUnAbonnementAuTermeNeResteJamaisActifSansRien(): void
    {
        $abonnement = $this->abonnementAuTerme(TermRenewalMode::Monthly);
        $finAvant = $abonnement->getDateFinEngagement();

        $resultat = $this->handler->process($abonnement, $this->reference);

        self::assertStringContainsString('mensualise', $resultat);
        self::assertGreaterThan($finAvant, $abonnement->getDateFinEngagement());
    }

    /**
     * ⚠ LE TEST DE L'ARGENT — ET IL A FALLU LE VOIR ROUGE POUR L'ENONCER JUSTE.
     *
     * « Deux appels ne creent pas deux fois » est faux pour un mensuel roulant : le passage du mois
     * suivant DOIT rouler d'un mois de plus. La garantie qui compte est celle que la tache exerce
     * tous les jours : **un second passage avant le terme suivant ne cree rien**. Sans elle, un
     * abonnement mensuel gagnerait trente echeances par mois.
     */
    public function testUnSecondPassageAvantLeTermeSuivantNeCreeRien(): void
    {
        $abonnement = $this->abonnementAuTerme(TermRenewalMode::Monthly);
        $apres = $this->reference;

        $this->handler->process($abonnement, $apres);
        $apresPremier = $this->compterEcheances($abonnement);

        $second = $this->handler->process($abonnement, $apres);
        $apresSecond = $this->compterEcheances($abonnement);

        // Le second passage doit se NOMMER comme sans effet, pas se presenter comme un succes.
        // « en-cours » est la reponse juste : l'abonnement n'est plus a son terme, il roule.
        self::assertSame('en-cours', $second);
        self::assertSame(
            $apresPremier,
            $apresSecond,
            'Un second passage a double des prelevements.',
        );
    }

    /**
     * TEMOIN POSITIF DU PRECEDENT. Sans lui, le test ci-dessus passerait aussi si le handler ne
     * creait JAMAIS d'echeance — pour une raison sans aucun rapport avec l'idempotence.
     */
    public function testLePremierPassageCreeBienDesEcheances(): void
    {
        $abonnement = $this->abonnementAuTerme(TermRenewalMode::Monthly);
        $avant = $this->compterEcheances($abonnement);

        $this->handler->process($abonnement, $this->reference);

        self::assertGreaterThan($avant, $this->compterEcheances($abonnement));
    }

    /** Le mode « suspendre » coupe : le statut devient echu. */
    public function testLeModeSuspendreRendLAbonnementEchu(): void
    {
        $abonnement = $this->abonnementAuTerme(TermRenewalMode::Suspend);

        self::assertSame('suspendu', $this->handler->process($abonnement, $this->reference));
        self::assertSame(StatutAbonnementFitness::Echu, $abonnement->getStatut());
    }

    /**
     * ⚠ UNE FORMULE SANS `modeAuTerme` NE DOIT PAS ETRE SUSPENDUE.
     *
     * Les trois formules de la preproduction portent `{"auto":true,"prix":"fixe"}`, ecrit par des
     * fixtures et jamais lu. Le repli doit etre « mensuel » : ni acces coupe du jour au lendemain,
     * ni reengagement d'un an impose a partir d'un drapeau que personne n'a choisi.
     */
    public function testUneFormuleSansModeRetombeSurMensuelEtPasSurSuspendre(): void
    {
        $abonnement = $this->abonnementAuTerme(null);

        $resultat = $this->handler->process($abonnement, $this->reference);

        self::assertStringContainsString('mensualise', $resultat);
        self::assertSame(StatutAbonnementFitness::Actif, $abonnement->getStatut());
    }

    /**
     * ⚠ ON REFUSE DE PROLONGER PLUTOT QUE D'INVENTER UN PRIX. Un abonnement sans aucune echeance ne
     * permet pas d'etablir ce qu'il porte ; prelever un montant devine serait pire que ne rien faire.
     */
    public function testSansAucuneEcheanceOnNeProlongePasEtOnLeDit(): void
    {
        $abonnement = $this->abonnementAuTerme(TermRenewalMode::Monthly);
        foreach ($this->em->getRepository(EcheanceSepa::class)->findBy(['abonnement' => $abonnement]) as $e) {
            $this->em->remove($e);
        }
        $this->em->flush();

        $finAvant = $abonnement->getDateFinEngagement();

        self::assertSame('sans-montant', $this->handler->process($abonnement, $this->reference));
        self::assertEquals($finAvant, $abonnement->getDateFinEngagement());
    }

    // ── Fabrique ────────────────────────────────────────────────────────────────────────────────

    private function abonnementAuTerme(?TermRenewalMode $mode): AbonnementFitness
    {
        $abonnement = $this->abonnementDemo();

        $formule = $abonnement->getFormule();
        self::assertNotNull($formule, "L'abonnement de demonstration n'a pas de formule.");

        $renouvellement = $formule->getRenouvellement();
        if ($mode === null) {
            unset($renouvellement[TermRenewalMode::CLE_FORMULE]);
        } else {
            $renouvellement[TermRenewalMode::CLE_FORMULE] = $mode->value;
        }
        $formule->setRenouvellement($renouvellement);

        // ⚠ LE TERME SE PREND DANS L'ECHEANCIER, IL NE S'INVENTE PAS.
        //
        // Une date en dur ici est une hypothese sur des fixtures qu'on n'a pas lues : la mienne
        // tombait AVANT la premiere echeance, la troncature les emportait toutes, et le handler
        // rendait « sans-montant » — correctement, sur un cas que je n'avais pas voulu construire.
        // En prenant la troisieme echeance comme terme, l'etat est coherent par construction, quoi
        // que fassent les fixtures.
        $echeances = $this->em->getRepository(EcheanceSepa::class)
            ->findBy(['abonnement' => $abonnement], ['dateProgrammee' => 'ASC']);

        self::assertGreaterThan(
            3,
            \count($echeances),
            "L'abonnement de demonstration porte moins de 4 echeances : cette fabrique ne peut plus "
            . 'construire un abonnement au terme avec un echeancier derriere lui.',
        );

        $gardees = \array_slice($echeances, 0, 3);
        // ⚠ LE TERME EST STRICTEMENT APRES LA DERNIERE ECHEANCE, comme le fait le generateur
        // d'origine : sa boucle place les echeances AVANT la fin d'engagement, jamais dessus.
        // Poser le terme SUR la derniere echeance fabriquait un etat que le produit ne produit
        // jamais, et le handler n'avait alors plus aucune periode a remplir — il rendait
        // « deja-couvert », correctement, sur un cas impossible.
        $terme = end($gardees)->getDateProgrammee()->modify('+1 month');

        foreach (\array_slice($echeances, 3) as $trop) {
            $this->em->remove($trop);
        }

        // La date de reference est un parametre du handler : le test n'a donc aucune assertion
        // d'horloge (D20), et il donne le meme resultat quel que soit le jour ou il tourne.
        //
        // ⚠ DIX JOURS APRES LE TERME, PAS UN MOIS. A un mois pile, la reference tombe exactement sur
        // la nouvelle fin d'engagement — le seul instant ou re-rouler est legitime — et le test
        // mesurerait ce cas frontiere au lieu du cas quotidien. La tache tourne TOUS LES JOURS :
        // c'est le lendemain qui doit ne rien creer, pas le mois suivant.
        $this->reference = $terme->modify('+10 days');

        $abonnement->setStatut(StatutAbonnementFitness::Actif)->setDateFinEngagement($terme);
        $this->em->flush();

        return $abonnement;
    }

    private function compterEcheances(AbonnementFitness $abonnement): int
    {
        return \count($this->em->getRepository(EcheanceSepa::class)->findBy(['abonnement' => $abonnement]));
    }
}
