<?php

declare(strict_types=1);

namespace App\Tests\Subscription\Integration;

use App\Crm\Entity\Client;
use App\Crm\Enum\TypeClient;
use App\DataFixtures\SocleFixtures;
use App\Facturation\Entity\Facture;
use App\Facturation\Entity\ParametreFacturationEtablissement;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\StatutUtilisateur;
use App\Subscription\Entity\Plan;
use App\Subscription\Entity\Subscription;
use App\Subscription\Entity\SubscriptionInvoice;
use App\Subscription\Enum\SubscriptionStatus;
use App\Subscription\Security\BillingServiceAccount;
use App\Tests\Facturation\FacturationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * ED-7 — la facturation automatique et le compte qui la signe.
 *
 * **Une facture NF525 porte le nom de qui l'a émise**, et une tâche périodique n'a personne derrière
 * elle. Maxime a arbitré : un compte de service nommé, lisible par un client, auquel personne ne peut
 * se connecter. Ces tests figent les trois conditions qu'il a posées — surtout la deuxième, qui est
 * la seule que du code puisse garantir.
 */
final class FacturationAutomatiqueTest extends FacturationApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $id = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $_ENV['EDITOR_TENANT_ID'] = $id;
        $_SERVER['EDITOR_TENANT_ID'] = $id;

        // Sans taux désigné, la facturation refuse — c'est testé ailleurs. Ici on teste la signature.
        $parametre = $this->em()->getRepository(ParametreFacturationEtablissement::class)
            ->findOneBy(['profilExploitant' => $this->profilExploitant()]);
        \assert($parametre instanceof ParametreFacturationEtablissement);
        $parametre->setTauxTvaAbonnement($this->em()->getRepository(\App\Compta\Entity\TauxTva::class)->find($this->idTauxTva('Taux normal 20 %')));
        $this->em()->flush();
    }

    /**
     * **La condition que du code peut garantir : personne ne peut se connecter avec ce compte.**
     *
     * Un compte de service dont on peut emprunter l'identité vaut pire que rien — il devient
     * l'endroit où l'on masque ce qu'on ne veut pas signer. La garantie ne repose pas sur une
     * vigilance : le compte est `Suspendu`, et le contrôle qui refuse un jeton d'un compte non actif
     * existait déjà.
     */
    public function testLeCompteDeServiceNestPasConnectable(): void
    {
        $compte = $this->compteDeService()->compte();

        self::assertSame(BillingServiceAccount::NOM, $compte->getNom());
        self::assertSame(StatutUtilisateur::Suspendu, $compte->getStatut());
        self::assertFalse($compte->isActif(), 'un compte non actif ne peut pas porter de jeton valide');

        // Et il n'est rattaché à rien : aucun droit, aucune liste d'affectation.
        self::assertCount(0, $this->em()->getRepository(\App\Securite\Entity\Affectation::class)->findBy(['utilisateur' => $compte]));
    }

    /** Le nom est lu par un client sur sa facture : il doit se comprendre, pas se décoder. */
    public function testLeNomDuCompteSeLitParUnClient(): void
    {
        $nom = BillingServiceAccount::NOM;

        self::assertStringNotContainsStringIgnoringCase('système', $nom);
        self::assertStringNotContainsStringIgnoringCase('system', $nom);
        self::assertStringNotContainsStringIgnoringCase('admin', $nom);
        self::assertStringContainsString('automatique', mb_strtolower($nom), 'le client doit comprendre que c est un traitement');
    }

    /** La passe automatique facture, et les factures portent le compte de service. */
    public function testLaPasseAutomatiqueFactureAuNomDuCompteDeService(): void
    {
        $this->abonnementActif('Camping des Pins');

        $testeur = $this->commande();
        $testeur->execute([]);

        self::assertSame(0, $testeur->getStatusCode(), $testeur->getDisplay());
        self::assertStringContainsString('1 facture(s) emise(s)', $testeur->getDisplay());

        $factures = $this->em()->getRepository(Facture::class)->findAll();
        self::assertCount(1, $factures);
        self::assertSame(BillingServiceAccount::NOM, $factures[0]->getCreePar()?->getNom());
    }

    /**
     * Relancée, elle ne refacture pas — elle compte et passe.
     *
     * C'est ce qui rend une tâche périodique sans danger : un ordonnanceur qui repasse après un
     * incident ne doit pas produire un second prélèvement.
     */
    public function testRelanceeElleNeRefacturePas(): void
    {
        $this->abonnementActif('Camping des Pins');

        $this->commande()->execute([]);
        $second = $this->commande();
        $second->execute([]);

        self::assertStringContainsString('0 facture(s) emise(s), 1 deja facturee(s)', $second->getDisplay());
        self::assertCount(1, $this->em()->getRepository(SubscriptionInvoice::class)->findAll());
    }

    /** Lancée à la main, elle porte le nom de la personne — c'est ce qu'on veut un jour de rattrapage. */
    public function testLanceeALaMainElleporteLeNomDeLaPersonne(): void
    {
        $this->abonnementActif('Camping des Pins');

        $testeur = $this->commande();
        $testeur->execute(['--auteur' => SocleFixtures::ADMIN_EMAIL]);

        $factures = $this->em()->getRepository(Facture::class)->findAll();
        self::assertCount(1, $factures);
        self::assertSame(SocleFixtures::ADMIN_EMAIL, $factures[0]->getCreePar()?->getEmail());
    }

    /** Le mode à blanc n'émet rien. */
    public function testLeModeABlancNemetRien(): void
    {
        $this->abonnementActif('Camping des Pins');

        $testeur = $this->commande();
        $testeur->execute(['--a-blanc' => true]);

        self::assertStringContainsString('Rien n a ete emis', $testeur->getDisplay());
        self::assertCount(0, $this->em()->getRepository(Facture::class)->findAll());
    }

    // ---------------------------------------------------------------- montage

    private function commande(): CommandTester
    {
        $application = new Application(static::$kernel ?? static::bootKernel());

        return new CommandTester($application->find('subscription:facturer-le-mois'));
    }

    private function compteDeService(): BillingServiceAccount
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        return new BillingServiceAccount($this->em(), $hasher);
    }

    private function abonnementActif(string $raisonSociale): Subscription
    {
        $editeur = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        \assert($editeur instanceof Etablissement);

        $client = (new Client())
            ->setType(TypeClient::Morale)
            ->setRaisonSociale($raisonSociale)
            ->setEmail(md5($raisonSociale).'@exemple.test')
            ->setGroupe($editeur->getRegion()?->getGroupe())
            ->setEtablissementCreation($editeur);
        $this->em()->persist($client);

        $plan = (new Plan())
            ->setCode('essentiel')
            ->setLabel('Essentiel')
            ->setMonthlyPriceCents(4900)
            ->setIncludedCapabilities(['controle_acces'])
            ->setActive(true);
        $this->em()->persist($plan);
        $this->em()->flush();

        $abonnement = (new Subscription())
            ->setCustomerReference($client->getId()->toRfc4122())
            ->setPlan($plan);
        $abonnement->transitionTo(SubscriptionStatus::Active, new \DateTimeImmutable('-1 day'));

        $this->em()->persist($abonnement);
        $this->em()->flush();

        return $abonnement;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
