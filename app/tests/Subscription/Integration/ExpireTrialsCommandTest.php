<?php

declare(strict_types=1);

namespace App\Tests\Subscription\Integration;

use App\Crm\Entity\Client;
use App\Crm\Enum\TypeClient;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Enum\StatutMandatSepa;
use App\Subscription\Entity\Plan;
use App\Subscription\Entity\Subscription;
use App\Subscription\Enum\SubscriptionStatus;
use App\Subscription\Service\SubscriptionMandates;
use App\Tests\SocleApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ED-5 — l'echeance de l'essai : bascule payante ou suspension.
 *
 * La regle arbitree le 04/09 : un essai echu dont le client a signe son mandat reste actif ; un essai
 * echu sans mandat est **suspendu**. Suspendu, pas resilie — l'exposition se coupe, aucune donnee
 * n'est supprimee (RG-ED-06), et un client qui revient trois semaines plus tard retrouve tout.
 *
 * ⚠ **Les deux temoins negatifs comptent autant que le cas nominal** : un essai qui COURT encore ne
 * doit pas etre suspendu, et un abonnement payant — qui n'a pas de terme d'essai — ne doit jamais
 * entrer dans cette boucle. Une commande qui balaierait « les abonnements actifs » au lieu de « ceux
 * qui portent un terme d'essai passe » couperait des clients qui paient, et ses tests de suspension
 * passeraient tous.
 */
final class ExpireTrialsCommandTest extends SocleApiTestCase
{
    private ?Plan $plan = null;

    protected function setUp(): void
    {
        parent::setUp();

        $id = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $_ENV['EDITOR_TENANT_ID'] = $id;
        $_SERVER['EDITOR_TENANT_ID'] = $id;
    }

    public function testUnEssaiEchuSansMandatEstSuspenduEtLesAutresNeLeSontPas(): void
    {
        $echuSansMandat = $this->abonnement('Echu sans mandat', new \DateTimeImmutable('-1 day'));
        $echuAvecMandat = $this->abonnement('Echu avec mandat', new \DateTimeImmutable('-1 day'));
        $this->signerUnMandat($echuAvecMandat);
        $essaiEnCours = $this->abonnement('Essai en cours', new \DateTimeImmutable('+5 days'));
        $payant = $this->abonnement('Abonnement payant', null);

        $commande = $this->commande();
        $commande->execute([]);

        $this->em()->refresh($echuSansMandat);
        $this->em()->refresh($echuAvecMandat);
        $this->em()->refresh($essaiEnCours);
        $this->em()->refresh($payant);

        self::assertSame(SubscriptionStatus::Suspended, $echuSansMandat->getStatus(), 'un essai echu sans mandat ouvre un service que rien ne paiera');
        self::assertSame(SubscriptionStatus::Active, $echuAvecMandat->getStatus(), 'le client a signe pendant son essai : il bascule, il n est pas coupe');
        self::assertSame(SubscriptionStatus::Active, $essaiEnCours->getStatus(), 'TEMOIN NEGATIF — un essai qui court encore n est pas echu');
        self::assertSame(SubscriptionStatus::Active, $payant->getStatus(), 'TEMOIN NEGATIF — un abonnement payant n a pas de terme d essai, il ne peut pas etre coupe par cette commande');

        self::assertStringContainsString('1 essai(s) suspendu(s), 1 converti(s)', $commande->getDisplay());
    }

    /** A blanc, la commande dit ce qu'elle ferait et ne change rien. */
    public function testAblancNeChangeRien(): void
    {
        $echu = $this->abonnement('A blanc', new \DateTimeImmutable('-1 day'));

        $commande = $this->commande();
        $commande->execute(['--a-blanc' => true]);

        $this->em()->refresh($echu);

        self::assertSame(SubscriptionStatus::Active, $echu->getStatus());
        self::assertStringContainsString('suspendrait '.$echu->getId()->toRfc4122(), $commande->getDisplay());
    }

    // ---------------------------------------------------------------- montage

    private function commande(): CommandTester
    {
        $application = new Application(static::$kernel ?? static::bootKernel());

        return new CommandTester($application->find('subscription:trials:expire'));
    }

    private function abonnement(string $raisonSociale, ?\DateTimeImmutable $trialEndsAt): Subscription
    {
        $client = (new Client())
            ->setType(TypeClient::Morale)
            ->setRaisonSociale($raisonSociale)
            ->setEmail(md5($raisonSociale).'@exemple.test')
            ->setGroupe($this->editeur()->getRegion()?->getGroupe())
            ->setEtablissementCreation($this->editeur());
        $this->em()->persist($client);
        $this->em()->flush();

        $abonnement = (new Subscription())
            ->setCustomerReference($client->getId()->toRfc4122())
            ->setPlan($this->plan())
            ->setTrialEndsAt($trialEndsAt);
        $abonnement->transitionTo(SubscriptionStatus::Active, new \DateTimeImmutable('-1 month'));

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
