<?php

declare(strict_types=1);

namespace App\Tests\SmartFlow;

use App\Tests\SchemaDuHarnais;
use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Compta\DataFixtures\ComptaFixtures;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client as CrmClient;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Organisation\Entity\Etablissement;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Sepa\DataFixtures\SepaFixtures;
use App\SmartFlow\DataFixtures\SmartFlowFixtures;
use App\Vente\DataFixtures\VenteFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Base des tests d'API du module `App\SmartFlow` : schéma recréé et fixtures socle + offre + compta +
 * vente + CRM + SEPA + réservation + smart-flow rechargées avant chaque test (même patron que
 * `App\Tests\Reservation\ReservationApiTestCase`/`App\Tests\Dms\DmsApiTestCase`) — les fixtures
 * `App\Reservation`/`App\Crm` fournissent des `Ressource`/`Creneau`/`Beneficiaire` réels sans qu'aucun
 * fichier de ces modules ne soit modifié par ce lot.
 */
abstract class SmartFlowApiTestCase extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();

        // Le schéma est construit UNE FOIS par processus, puis vidé entre les tests. Le faire
        // détruire et reconstruire par chaque `setUp()` coûtait ~10 s par test — six heures sur
        // la suite complète, et donc une suite que personne ne lançait.
        SchemaDuHarnais::reinitialiser($em);

        foreach ([
            SocleFixtures::class, OffreFixtures::class, ComptaFixtures::class, VenteFixtures::class,
            CrmFixtures::class, SepaFixtures::class, ReservationFixtures::class, SmartFlowFixtures::class,
        ] as $classe) {
            $fixture = $container->get($classe);
            $fixture->load($em);
        }

        self::ensureKernelShutdown();
    }

    protected function token(Client $client, string $email, string $motDePasse): string
    {
        return $client->request('POST', '/auth', [
            'json' => ['email' => $email, 'motDePasse' => $motDePasse],
        ])->toArray()['token'];
    }

    /** @return array{0: Client, 1: array<string, mixed>, 2: string} client, entête auth+étab, id établissement */
    protected function adminOn(string $nomEtablissement): array
    {
        $idEtab = $this->establishmentId($nomEtablissement);
        $client = static::createClient();
        $token = $this->token($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idEtab]];

        return [$client, $entete, $idEtab];
    }

    /**
     * Utilisateur portant `smart_flow.reschedule_manage` (agent d'accueil, §3 spec-smart-flow.md).
     *
     * @return array{0: Client, 1: array<string, mixed>, 2: string}
     */
    protected function managerOn(string $nomEtablissement): array
    {
        return $this->userWithSmartFlowPermissions($nomEtablissement, ['reschedule_manage'], 'sf-manager-' . uniqid('', true));
    }

    /**
     * Utilisateur ne portant que `smart_flow.reschedule_manage`/`reschedule_read_own` — via
     * `$actions`. Affecté sur `$nomEtablissement`.
     *
     * @param list<string> $actions
     *
     * @return array{0: Client, 1: array<string, mixed>, 2: string}
     */
    protected function userWithSmartFlowPermissions(string $nomEtablissement, array $actions, string $emailSuffix): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $etablissement = $em->getRepository(Etablissement::class)->findOneBy(['nom' => $nomEtablissement]);
        self::assertInstanceOf(Etablissement::class, $etablissement);

        $role = new Role();
        $role->setNom('Rôle Smart Flow test ' . uniqid('', true));
        foreach ($actions as $action) {
            $permission = $em->getRepository(Permission::class)->findOneBy(['module' => 'smart_flow', 'action' => $action]);
            self::assertInstanceOf(Permission::class, $permission, sprintf('Permission smart_flow.%s introuvable.', $action));
            $role->addPermission($permission);
        }
        $em->persist($role);

        $email = $emailSuffix . '@itcotation.com';
        $motDePasse = 'SmartFlowUtilisateur#2026';
        $utilisateur = (new Utilisateur())->setEmail($email)->setNom('Utilisateur Smart Flow test')->setActif(true);
        $utilisateur->setMotDePasse($hasher->hashPassword($utilisateur, $motDePasse));
        $em->persist($utilisateur);

        $em->persist((new Affectation())->setUtilisateur($utilisateur)->setRole($role)->setEtablissement($etablissement));
        $em->flush();

        $client = static::createClient();
        $token = $this->token($client, $email, $motDePasse);
        $entete = [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => (string) $etablissement->getId()],
        ];

        return [$client, $entete, (string) $etablissement->getId()];
    }

    /**
     * Utilisateur « own » (`smart_flow.reschedule_read_own` uniquement) lié au client CRM `$clientId`
     * (même patron que `App\Tests\Facturation\Api\EspaceClientFactureApiTest::creerClientEtUtilisateur`).
     *
     * @return array{0: Client, 1: array<string, mixed>, 2: string}
     */
    protected function ownUserOn(string $nomEtablissement, Uuid $clientId, string $emailSuffix): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $etablissement = $em->getRepository(Etablissement::class)->findOneBy(['nom' => $nomEtablissement]);
        self::assertInstanceOf(Etablissement::class, $etablissement);

        $permission = $em->getRepository(Permission::class)->findOneBy(['module' => 'smart_flow', 'action' => 'reschedule_read_own']);
        self::assertInstanceOf(Permission::class, $permission, 'Permission smart_flow.reschedule_read_own introuvable.');

        $role = (new Role())->setNom('Client Smart Flow test ' . uniqid('', true));
        $role->addPermission($permission);
        $em->persist($role);

        $email = $emailSuffix . '@itcotation.com';
        $motDePasse = 'SmartFlowClient#2026';
        $utilisateur = (new Utilisateur())->setEmail($email)->setNom('Client Smart Flow test')->setActif(true);
        $utilisateur->setMotDePasse($hasher->hashPassword($utilisateur, $motDePasse));
        $utilisateur->setClientLie($clientId);
        $em->persist($utilisateur);

        $em->persist((new Affectation())->setUtilisateur($utilisateur)->setRole($role)->setEtablissement($etablissement));
        $em->flush();

        $client = static::createClient();
        $token = $this->token($client, $email, $motDePasse);
        $entete = [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => (string) $etablissement->getId()],
        ];

        return [$client, $entete, (string) $etablissement->getId()];
    }

    protected function establishmentId(string $nom): string
    {
        return (string) $this->entity(Etablissement::class, ['nom' => $nom])->getId();
    }

    /** Beneficiaire lié au client CRM payeur de démonstration (organisateur de démonstration). */
    protected function idBeneficiairePayeur(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $payeur = $em->getRepository(CrmClient::class)->findOneBy(['email' => CrmFixtures::PAYEUR_EMAIL]);
        self::assertNotNull($payeur, 'Client payeur de démonstration introuvable.');
        $beneficiaire = $em->getRepository(Beneficiaire::class)->findOneBy(['client' => $payeur]);
        self::assertNotNull($beneficiaire, 'Bénéficiaire payeur de démonstration introuvable.');

        return (string) $beneficiaire->getId();
    }

    protected function idBeneficiaireParPrenom(string $prenom): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $client = $em->getRepository(CrmClient::class)->findOneBy(['prenom' => $prenom]);
        self::assertNotNull($client, sprintf('Client prénommé « %s » introuvable.', $prenom));
        $beneficiaire = $em->getRepository(Beneficiaire::class)->findOneBy(['client' => $client]);
        self::assertNotNull($beneficiaire, sprintf('Bénéficiaire prénommé « %s » introuvable.', $prenom));

        return (string) $beneficiaire->getId();
    }

    /**
     * @template T of object
     *
     * @param class-string<T>      $classe
     * @param array<string, mixed> $criteres
     *
     * @return T
     */
    protected function entity(string $classe, array $criteres): object
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $entite = $em->getRepository($classe)->findOneBy($criteres);
        self::assertNotNull($entite, sprintf('%s introuvable (%s).', $classe, json_encode($criteres)));

        return $entite;
    }

    protected function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
