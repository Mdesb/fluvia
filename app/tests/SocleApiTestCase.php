<?php

declare(strict_types=1);

namespace App\Tests;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Base des tests d'API du socle : schéma recréé et fixtures rechargées avant chaque test
 * pour garantir l'isolation.
 */
abstract class SocleApiTestCase extends ApiTestCase
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
        // FK_CHECKS désactivé le temps du drop/create (nombreuses tables inter-référencées) : évite les
        // échecs d'ordonnancement DROP/CREATE observés après l'introduction du schéma recouvrement_*.
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        DdlHorsMapping::appliquer($em);

        /** @var SocleFixtures $fixtures */
        $fixtures = $container->get(SocleFixtures::class);
        $fixtures->load($em);

        // Le kernel est redémarré par createClient() dans chaque test ; la base persiste.
        self::ensureKernelShutdown();
    }

    protected function jeton(Client $client, string $email, string $motDePasse): string
    {
        $reponse = $client->request('POST', '/auth', [
            'json' => ['email' => $email, 'motDePasse' => $motDePasse],
        ]);

        return $reponse->toArray()['token'];
    }

    protected function idEtablissement(string $nom): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => $nom]);
        self::assertNotNull($etab, sprintf('Établissement "%s" introuvable.', $nom));

        return (string) $etab->getId();
    }
}
