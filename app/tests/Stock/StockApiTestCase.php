<?php

declare(strict_types=1);

namespace App\Tests\Stock;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Stock\DataFixtures\StockFixtures;
use App\Vente\DataFixtures\VenteFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Base des tests d'API du module `App\Stock` : schéma recréé, fixtures socle + offre + vente + stock. */
abstract class StockApiTestCase extends ApiTestCase
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

        foreach ([SocleFixtures::class, OffreFixtures::class, VenteFixtures::class, StockFixtures::class] as $classe) {
            $container->get($classe)->load($em);
        }

        self::ensureKernelShutdown();
    }

    protected function jeton(Client $client, string $email, string $motDePasse): string
    {
        return $client->request('POST', '/auth', [
            'json' => ['email' => $email, 'motDePasse' => $motDePasse],
        ])->toArray()['token'];
    }

    /** @return array{0: Client, 1: array<string, mixed>, 2: string} client, entête auth+étab, id établissement A */
    protected function adminSurA(): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        return [$client, $entete, $idA];
    }

    /** @return array{0: Client, 1: array<string, mixed>, 2: string} client, entête auth+étab, id établissement B */
    protected function adminSurB(): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idB]];

        return [$client, $entete, $idB];
    }

    /**
     * Crée un utilisateur affecté **uniquement** sur l'établissement donné, avec un rôle ne portant
     * que les codes `stock.<action>` demandés (RG-SOCLE-03/05) — sert à reproduire l'IDOR cross-tenant
     * (un opérateur affecté à un seul établissement ne doit jamais pouvoir agir sur l'autre via un
     * identifiant deviné/connu dans le corps de la requête).
     *
     * @param list<string> $actions codes « action » du module `stock` (ex. 'transferer', 'gerer')
     *
     * @return array{0: Client, 1: array<string, mixed>, 2: string} client, entête auth+étab, id établissement
     */
    protected function operateurStockSur(string $nomEtab, array $actions): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => $nomEtab]);
        self::assertNotNull($etab, sprintf('Établissement « %s » introuvable.', $nomEtab));

        $suffixe = bin2hex(random_bytes(4));

        $role = (new Role())->setNom('Opérateur Stock ' . $nomEtab . ' ' . $suffixe);
        foreach ($actions as $action) {
            $permission = $em->getRepository(Permission::class)->findOneBy(['module' => 'stock', 'action' => $action]);
            self::assertNotNull($permission, sprintf('Permission stock.%s introuvable (StockFixtures).', $action));
            $role->addPermission($permission);
        }
        $em->persist($role);

        $email = 'operateur.' . $suffixe . '@itcotation.com';
        $motDePasse = 'Operateur#2026';
        $utilisateur = (new Utilisateur())->setEmail($email)->setNom('Opérateur Stock ' . $suffixe)->setActif(true);
        $utilisateur->setMotDePasse($hasher->hashPassword($utilisateur, $motDePasse));
        $em->persist($utilisateur);

        $affectation = (new Affectation())->setUtilisateur($utilisateur)->setRole($role)->setEtablissement($etab);
        $em->persist($affectation);

        $em->flush();

        $client = static::createClient();
        $token = $this->jeton($client, $email, $motDePasse);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => (string) $etab->getId()]];

        return [$client, $entete, (string) $etab->getId()];
    }

    protected function idEtablissement(string $nom): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => $nom]);
        self::assertNotNull($etab);

        return (string) $etab->getId();
    }

    /**
     * @template T of object
     *
     * @param class-string<T>      $classe
     * @param array<string, mixed> $criteres
     *
     * @return T
     */
    protected function entite(string $classe, array $criteres): object
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $entite = $em->getRepository($classe)->findOneBy($criteres);
        self::assertNotNull($entite, sprintf('%s introuvable (%s).', $classe, json_encode($criteres)));

        return $entite;
    }
}
