<?php

declare(strict_types=1);

namespace App\Tests;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\EntityManagerInterface;

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

        // Le schéma est construit UNE FOIS par processus, puis vidé entre les tests. Le faire
        // détruire et reconstruire par chaque `setUp()` coûtait ~10 s par test — six heures sur
        // la suite complète, et donc une suite que personne ne lançait.
        SchemaDuHarnais::reinitialiser($em);

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
