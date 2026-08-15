<?php

declare(strict_types=1);

namespace App\Tests\Securite\Api;

use App\DataFixtures\SocleFixtures;
use App\Securite\DataFixtures\L7Fixtures;
use App\Securite\Entity\DelegationDroit;
use App\Securite\Enum\StatutDelegation;
use App\Tests\Securite\SecuriteApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Délégation temporaire de droits (US-L7-07, RG-M8-09) : dateFin obligatoire, intégration aux
 * droits effectifs, révocation anticipée et automatique (échéance).
 */
final class DelegationTest extends SecuriteApiTestCase
{
    /** CA-7 — Délégation sans dateFin ⇒ 422 ; avec dateFin ⇒ droits effectifs jusqu'à échéance incluse. */
    public function testCa7DateFinObligatoireEtDroitsEffectifs(): void
    {
        [$client, $entete] = $this->adminSurA();
        $idLecteur = $this->idUtilisateur(SocleFixtures::LECTEUR_EMAIL);
        $idAdmin = $this->idUtilisateur(SocleFixtures::ADMIN_EMAIL);
        $idRoleDelegation = $this->idRole(L7Fixtures::ROLE_DELEGATION_NOM);
        $idEtabA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);

        // Sans dateFin : refus (422).
        $client->request('POST', '/api/delegation_droits', $entete + [
            'json' => [
                'delegant' => '/api/utilisateurs/' . $idAdmin,
                'beneficiaire' => '/api/utilisateurs/' . $idLecteur,
                'role' => '/api/roles/' . $idRoleDelegation,
                'etablissement' => '/api/etablissements/' . $idEtabA,
                'dateDebut' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
        ]);
        self::assertResponseStatusCodeSame(422);

        // Avec dateFin : succès, droits effectifs du bénéficiaire enrichis (organisation.gerer).
        $dateFin = (new \DateTimeImmutable('+1 day'))->format(DATE_ATOM);
        $creation = $client->request('POST', '/api/delegation_droits', $entete + [
            'json' => [
                'delegant' => '/api/utilisateurs/' . $idAdmin,
                'beneficiaire' => '/api/utilisateurs/' . $idLecteur,
                'role' => '/api/roles/' . $idRoleDelegation,
                'etablissement' => '/api/etablissements/' . $idEtabA,
                'dateDebut' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'dateFin' => $dateFin,
            ],
        ]);
        self::assertResponseStatusCodeSame(201);

        $tokenLecteur = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);
        $moi = $client->request('GET', '/me', [
            'auth_bearer' => $tokenLecteur,
            'headers' => [\App\Securite\Service\ContexteEtablissement::HEADER => $idEtabA],
        ]);
        self::assertResponseIsSuccessful();
        self::assertContains('organisation.gerer', $moi->toArray()['droits']);
    }

    /** CA-9 — Révocation anticipée ⇒ statut=revoquee immédiat, droits retirés. */
    public function testCa9RevocationAnticipee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $delegation = $this->creerDelegationActive();

        $client->request('POST', '/api/delegations/' . $delegation->getId() . '/revoquer', $entete + [
            'json' => ['motif' => 'Erreur de saisie'],
        ]);
        self::assertResponseIsSuccessful();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $rechargee = $em->getRepository(DelegationDroit::class)->find($delegation->getId());
        self::assertNotNull($rechargee);
        self::assertSame(StatutDelegation::Revoquee, $rechargee->getStatut());
        self::assertNotNull($rechargee->getRevoquePar());

        $idEtabA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $tokenLecteur = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);
        $moi = $client->request('GET', '/me', [
            'auth_bearer' => $tokenLecteur,
            'headers' => [\App\Securite\Service\ContexteEtablissement::HEADER => $idEtabA],
        ]);
        self::assertNotContains('organisation.gerer', $moi->toArray()['droits']);
    }

    /** CA-8 — Délégation échue ⇒ la commande `securite:delegations:expirer` la passe à « expiree », droits retirés. */
    public function testCa8CommandeExpiration(): void
    {
        static::createClient(); // boot kernel + fixtures (setUp)
        $delegation = $this->creerDelegationActive(dateFinPassee: true);

        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel);
        $command = $application->find('securite:delegations:expirer');
        $tester = new CommandTester($command);
        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $rechargee = $em->getRepository(DelegationDroit::class)->find($delegation->getId());
        self::assertNotNull($rechargee);
        self::assertSame(StatutDelegation::Expiree, $rechargee->getStatut());
    }

    private function creerDelegationActive(bool $dateFinPassee = false): DelegationDroit
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $admin = $this->entite(\App\Securite\Entity\Utilisateur::class, ['email' => SocleFixtures::ADMIN_EMAIL]);
        $lecteur = $this->entite(\App\Securite\Entity\Utilisateur::class, ['email' => SocleFixtures::LECTEUR_EMAIL]);
        $role = $this->entite(\App\Securite\Entity\Role::class, ['nom' => L7Fixtures::ROLE_DELEGATION_NOM]);
        $etabA = $this->entite(\App\Organisation\Entity\Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);

        $delegation = new DelegationDroit();
        $delegation->setDelegant($admin);
        $delegation->setBeneficiaire($lecteur);
        $delegation->setRole($role);
        $delegation->setEtablissement($etabA);
        $delegation->setDateDebut(new \DateTimeImmutable('-1 hour'));
        $delegation->setDateFin($dateFinPassee ? new \DateTimeImmutable('-1 minute') : new \DateTimeImmutable('+1 day'));
        $em->persist($delegation);
        $em->flush();

        return $delegation;
    }
}
