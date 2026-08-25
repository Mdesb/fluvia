<?php

declare(strict_types=1);

namespace App\Tests\RevenueRecovery;

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
use App\RevenueRecovery\DataFixtures\RevenueRecoveryFixtures;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Sepa\DataFixtures\SepaFixtures;
use App\Vente\DataFixtures\VenteFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Base des tests d'API/service du module `App\RevenueRecovery` : schéma recréé et fixtures socle +
 * offre + compta + vente + CRM + SEPA + réservation + revenue-recovery rechargées avant chaque test
 * (même patron que `App\Tests\SmartFlow\SmartFlowApiTestCase`) — les fixtures `App\Reservation`/
 * `App\Crm` fournissent un `Client`/`Beneficiaire`/`Reservation` réels sans qu'aucun fichier de ces
 * modules ne soit modifié par ce lot.
 */
abstract class RevenueRecoveryApiTestCase extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        foreach ([
            SocleFixtures::class, OffreFixtures::class, ComptaFixtures::class, VenteFixtures::class,
            CrmFixtures::class, SepaFixtures::class, ReservationFixtures::class, RevenueRecoveryFixtures::class,
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
     * Utilisateur portant un sous-ensemble des permissions `revenue_recovery.*` (`$actions` ⊂
     * `{read, configure, manage}`), affecté sur `$nomEtablissement`.
     *
     * @param list<string> $actions
     *
     * @return array{0: Client, 1: array<string, mixed>, 2: string}
     */
    protected function userWithPermissions(string $nomEtablissement, array $actions, string $emailSuffix): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $etablissement = $em->getRepository(Etablissement::class)->findOneBy(['nom' => $nomEtablissement]);
        self::assertInstanceOf(Etablissement::class, $etablissement);

        $role = new Role();
        $role->setNom('Rôle Revenue Recovery test ' . uniqid('', true));
        foreach ($actions as $action) {
            $permission = $em->getRepository(Permission::class)->findOneBy(['module' => 'revenue_recovery', 'action' => $action]);
            self::assertInstanceOf(Permission::class, $permission, sprintf('Permission revenue_recovery.%s introuvable.', $action));
            $role->addPermission($permission);
        }
        $em->persist($role);

        $email = $emailSuffix . '@itcotation.com';
        $motDePasse = 'RevenueRecoveryUtilisateur#2026';
        $utilisateur = (new Utilisateur())->setEmail($email)->setNom('Utilisateur Revenue Recovery test')->setActif(true);
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

    protected function establishmentId(string $nom): string
    {
        return (string) $this->entity(Etablissement::class, ['nom' => $nom])->getId();
    }

    /** `Beneficiaire` lié au client CRM payeur de démonstration (organisateur de démonstration). */
    protected function beneficiairePayeur(): Beneficiaire
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $payeur = $em->getRepository(CrmClient::class)->findOneBy(['email' => CrmFixtures::PAYEUR_EMAIL]);
        self::assertNotNull($payeur, 'Client payeur de démonstration introuvable.');
        $beneficiaire = $em->getRepository(Beneficiaire::class)->findOneBy(['client' => $payeur]);
        self::assertNotNull($beneficiaire, 'Bénéficiaire payeur de démonstration introuvable.');

        return $beneficiaire;
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
