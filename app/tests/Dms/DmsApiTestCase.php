<?php

declare(strict_types=1);

namespace App\Tests\Dms;

use App\Tests\SchemaDuHarnais;
use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\DataFixtures\SocleFixtures;
use App\Dms\DataFixtures\DmsFixtures;
use App\Organisation\Entity\Etablissement;
use App\Platform\Event\DomainEvent;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Base des tests d'API du service transverse `App\Dms` : schéma recréé et fixtures socle + DMS
 * rechargées avant chaque test (même patron que `App\Tests\Ocr\OcrApiTestCase`).
 */
abstract class DmsApiTestCase extends ApiTestCase
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

        foreach ([SocleFixtures::class, DmsFixtures::class] as $classe) {
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

    /** @return array{0: Client, 1: array<string, mixed>} client, entête auth+étab */
    protected function adminOn(string $idEtablissement): array
    {
        $client = static::createClient();
        $token = $this->token($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idEtablissement]];

        return [$client, $entete];
    }

    /** @return array{0: Client, 1: array<string, mixed>, 2: string} client, entête auth+étab, id établissement A */
    protected function adminOnA(): array
    {
        $idA = $this->establishmentId(SocleFixtures::ETAB_A_NOM);
        [$client, $entete] = $this->adminOn($idA);

        return [$client, $entete, $idA];
    }

    /** @return array{0: Client, 1: array<string, mixed>, 2: string} client, entête auth+étab, id établissement B */
    protected function adminOnB(): array
    {
        $idB = $this->establishmentId(SocleFixtures::ETAB_B_NOM);
        [$client, $entete] = $this->adminOn($idB);

        return [$client, $entete, $idB];
    }

    protected function establishmentId(string $nom): string
    {
        return (string) $this->entity(Etablissement::class, ['nom' => $nom])->getId();
    }

    /**
     * Crée un utilisateur affecté sur `$nomEtablissement` avec un rôle portant exactement
     * `$actionsDms` (module `dms`). Retourne (client, entête auth+étab, email).
     *
     * @param list<string> $actionsDms
     *
     * @return array{0: Client, 1: array<string, mixed>, 2: string}
     */
    protected function dmsUserOn(string $nomEtablissement, array $actionsDms, ?string $emailSuffix = null): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $etablissement = $em->getRepository(Etablissement::class)->findOneBy(['nom' => $nomEtablissement]);
        self::assertInstanceOf(Etablissement::class, $etablissement);

        $role = new Role();
        $role->setNom('Rôle DMS test ' . uniqid('', true));
        foreach ($actionsDms as $action) {
            $permission = $em->getRepository(Permission::class)->findOneBy(['module' => 'dms', 'action' => $action]);
            self::assertInstanceOf(Permission::class, $permission, sprintf('Permission dms.%s introuvable.', $action));
            $role->addPermission($permission);
        }
        $em->persist($role);

        $email = ($emailSuffix ?? uniqid('dms-user-', true)) . '@itcotation.com';
        $motDePasse = 'DmsUtilisateur#2026';
        $utilisateur = (new Utilisateur())->setEmail($email)->setNom('Utilisateur DMS test')->setActif(true);
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

        return [$client, $entete, $email];
    }

    /**
     * Affecte un utilisateur nouvellement créé au rôle **existant** `$nomRole` (ex. le rôle dédié
     * `DmsFixtures::ROLE_LIENS_PUBLICS`) sur `$nomEtablissement`, sans créer de rôle ad hoc — pour
     * tester le comportement réel des fixtures livrées.
     *
     * @return array{0: Client, 1: array<string, mixed>, 2: string}
     */
    protected function userWithRoleOn(string $nomRole, string $nomEtablissement, string $emailSuffix): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $etablissement = $em->getRepository(Etablissement::class)->findOneBy(['nom' => $nomEtablissement]);
        self::assertInstanceOf(Etablissement::class, $etablissement);
        $role = $em->getRepository(Role::class)->findOneBy(['nom' => $nomRole]);
        self::assertInstanceOf(Role::class, $role, sprintf('Rôle « %s » introuvable.', $nomRole));

        $email = $emailSuffix . '@itcotation.com';
        $motDePasse = 'DmsUtilisateur#2026';
        $utilisateur = (new Utilisateur())->setEmail($email)->setNom('Utilisateur DMS test')->setActif(true);
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

        return [$client, $entete, $email];
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

    /**
     * Capture les événements de domaine publiés pendant l'appel qui suit (même patron que
     * `App\Tests\Finance\Api\ExpenseReportEventTest::capturerEvenements`).
     *
     * @param list<string> $noms
     */
    protected function captureEvents(array $noms): \ArrayObject
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::getContainer()->get(EventDispatcherInterface::class);
        $captures = new \ArrayObject();
        foreach ($noms as $nom) {
            $dispatcher->addListener($nom, static function (DomainEvent $event) use ($captures): void {
                $captures[] = $event;
            });
        }

        return $captures;
    }

    /** Crée un fichier temporaire avec `$contenu`, retourne son chemin (nettoyage laissé à l'OS/tmp). */
    protected function temporaryFile(string $contenu, string $prefixe = 'dmstest'): string
    {
        $chemin = tempnam(sys_get_temp_dir(), $prefixe);
        self::assertNotFalse($chemin);
        file_put_contents($chemin, $contenu);

        return $chemin;
    }
}
