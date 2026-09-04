<?php

declare(strict_types=1);

namespace App\Tests\Subscription\Integration;

use App\Crm\Entity\Client;
use App\Crm\Enum\TypeClient;
use App\DataFixtures\SocleFixtures;
use App\Facturation\Entity\ParametreFacturationEtablissement;
use App\Organisation\Entity\Etablissement;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Enum\StatutMandatSepa;
use App\Subscription\Entity\Plan;
use App\Subscription\Entity\Subscription;
use App\Subscription\Enum\SubscriptionStatus;
use App\Subscription\Service\SubscriptionMandates;
use App\Tests\Facturation\FacturationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ED-5 — ce que la facturation mensuelle doit refuser de facturer, et ce qu'elle doit continuer a facturer.
 *
 * **Le defaut que ces quatre cas empechent.** L'essai libre-service ACTIVE l'abonnement sans mandat.
 * `abonnementsFacturables()` selectionne tout ce qui est `active` ou `suspended` : sans garde, le
 * premier passage de la commande emettrait une facture mensuelle a un client qui n'a rien signe,
 * puis `Recouvrement` le relancerait pour un impaye qui n'existe pas. La relance serait chez nous la
 * faute, pas chez lui.
 *
 * ⚠ **LE QUATRIEME CAS EST CELUI QUI VALIDE LES TROIS AUTRES.** Un abonnement payant ordinaire DOIT
 * rester facture. Une garde trop large — « ne facture pas ce qui n'a pas de mandat actif », par
 * exemple — passerait les trois premiers tests et ferait disparaitre des factures dues, sans que
 * rien ne le signale : une facture qui n'est pas emise ne produit aucune erreur, juste un client qui
 * ne paie plus. C'est le sens dans lequel un controle trop large est invisible a ses propres refus.
 */
final class TrialBillingGuardTest extends FacturationApiTestCase
{
    private ?Plan $plan = null;

    protected function setUp(): void
    {
        parent::setUp();

        $id = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $_ENV['EDITOR_TENANT_ID'] = $id;
        $_SERVER['EDITOR_TENANT_ID'] = $id;

        $parametre = $this->em()->getRepository(ParametreFacturationEtablissement::class)
            ->findOneBy(['profilExploitant' => $this->profilExploitant()]);
        \assert($parametre instanceof ParametreFacturationEtablissement);
        $parametre->setTauxTvaAbonnement($this->em()->getRepository(\App\Compta\Entity\TauxTva::class)->find($this->idTauxTva('Taux normal 20 %')));
        $this->em()->flush();
    }

    public function testUnEssaiNestFactureQueSilAAbouti(): void
    {
        $essaiEnCours = $this->abonnement('Essai en cours', trialEndsAt: new \DateTimeImmutable('+10 days'));
        $essaiEchuSansMandat = $this->abonnement('Essai echu sans mandat', trialEndsAt: new \DateTimeImmutable('-1 day'));
        $essaiConverti = $this->abonnement('Essai converti', trialEndsAt: new \DateTimeImmutable('-1 day'));
        $this->signerUnMandat($essaiConverti);
        $abonnementPayant = $this->abonnement('Abonnement payant', trialEndsAt: null);

        $commande = $this->commande();
        $commande->execute(['--a-blanc' => true]);
        $sortie = $commande->getDisplay();

        self::assertStringNotContainsString(
            $essaiEnCours->getId()->toRfc4122(),
            $sortie,
            'un essai en cours est gratuit : il ne doit rien facturer'
        );
        self::assertStringNotContainsString(
            $essaiEchuSansMandat->getId()->toRfc4122(),
            $sortie,
            'un essai echu SANS mandat facturerait un client qui n a donne aucune autorisation de prelevement'
        );
        self::assertStringContainsString(
            $essaiConverti->getId()->toRfc4122(),
            $sortie,
            'un essai echu dont le client a signe est devenu un abonnement payant : il se facture'
        );
        self::assertStringContainsString(
            $abonnementPayant->getId()->toRfc4122(),
            $sortie,
            'TEMOIN NEGATIF — la garde ne doit pas toucher un abonnement qui n est pas passe par l essai'
        );
    }

    /**
     * La garde ne depend d'AUCUNE tache planifiee, et c'est ce qui la rend sure.
     *
     * L'essai echu ci-dessous n'a jamais ete vu par `subscription:trials:expire` : il est toujours
     * `active`, son terme est passe depuis un mois. Si la garde s'appuyait sur l'etat pose par la
     * commande — « suspendu, donc pas facture » — le jour ou l'ordonnanceur ne tourne pas
     * produirait des factures fausses. Elle s'appuie sur le mandat, qui ne depend de personne.
     */
    public function testUnEssaiEchuDepuisUnMoisEtJamaisTraiteNestToujoursPasFacture(): void
    {
        $oublie = $this->abonnement('Essai oublie', trialEndsAt: new \DateTimeImmutable('-1 month'));

        self::assertSame(SubscriptionStatus::Active, $oublie->getStatus(), 'rien ne l a suspendu');

        $commande = $this->commande();
        $commande->execute(['--a-blanc' => true]);

        self::assertStringNotContainsString($oublie->getId()->toRfc4122(), $commande->getDisplay());
    }

    // ---------------------------------------------------------------- montage

    private function commande(): CommandTester
    {
        $application = new Application(static::$kernel ?? static::bootKernel());

        return new CommandTester($application->find('subscription:facturer-le-mois'));
    }

    private function abonnement(string $raisonSociale, ?\DateTimeImmutable $trialEndsAt): Subscription
    {
        $client = (new Client())
            ->setType(TypeClient::Morale)
            ->setRaisonSociale($raisonSociale)
            ->setSiret('12345678900011')
            ->setAdresse(['rue' => '9 rue des Abonnes', 'cp' => '75016', 'ville' => 'Paris', 'pays' => 'FR'])
            ->setEmail(md5($raisonSociale).'@exemple.test')
            ->setGroupe($this->editeur()->getRegion()?->getGroupe())
            ->setEtablissementCreation($this->editeur());
        $this->em()->persist($client);
        $this->em()->flush();

        $abonnement = (new Subscription())
            ->setCustomerReference($client->getId()->toRfc4122())
            ->setPlan($this->plan())
            ->setTrialEndsAt($trialEndsAt);
        $abonnement->transitionTo(SubscriptionStatus::Active, new \DateTimeImmutable('-2 months'));

        $this->em()->persist($abonnement);
        $this->em()->flush();

        return $abonnement;
    }

    private function signerUnMandat(Subscription $abonnement): void
    {
        $client = $this->em()->getRepository(Client::class)->find($abonnement->getCustomerReference());
        \assert($client instanceof Client);

        $mandat = (new MandatSepa())
            ->setRum((new SubscriptionMandates($this->em()))->reference($abonnement))
            ->setIbanToken(hash('sha256', $abonnement->getId()->toRfc4122()))
            ->setIban4Derniers('0189')
            ->setIbanChiffre('chiffre')
            ->setBicDebiteur('AGRIFRPPXXX')
            ->setDebiteurNom($client->getRaisonSociale() ?? '')
            ->setDateSignature(new \DateTimeImmutable('-1 week'))
            ->setStatut(StatutMandatSepa::Actif)
            ->setClient($client)
            ->setEtablissement($this->editeur());

        $this->em()->persist($mandat);
        $this->em()->flush();
    }

    private function plan(): Plan
    {
        if (null === $this->plan) {
            $this->plan = (new Plan())
                ->setCode('essentiel')
                ->setLabel('Essentiel')
                ->setMonthlyPriceCents(4900)
                ->setIncludedCapabilities(['controle_acces'])
                ->setActive(true);
            $this->em()->persist($this->plan);
            $this->em()->flush();
        }

        return $this->plan;
    }

    private function editeur(): Etablissement
    {
        $etablissement = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        \assert($etablissement instanceof Etablissement);

        return $etablissement;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
