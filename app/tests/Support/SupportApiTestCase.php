<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Tests\SchemaDuHarnais;
use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Organisation\Entity\Etablissement;
use App\Securite\Service\ContexteEtablissement;
use App\Support\DataFixtures\SupportFixtures;
use App\Tests\DdlHorsMapping;
use Doctrine\ORM\EntityManagerInterface;

/** Base des tests d'API du module `App\Support` : schéma recréé, fixtures `SupportFixtures` (autonome). */
abstract class SupportApiTestCase extends ApiTestCase
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

        $container->get(SupportFixtures::class)->load($em);

        self::ensureKernelShutdown();
    }

    protected function jeton(Client $client, string $email, string $motDePasse): string
    {
        return $client->request('POST', '/auth', [
            'json' => ['email' => $email, 'motDePasse' => $motDePasse],
        ])->toArray()['token'];
    }

    /** @return array{0: Client, 1: array<string, mixed>} client, entête auth+étab */
    protected function connecte(string $email, string $etablissementNom): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, $email, SupportFixtures::MDP);
        $idEtab = $this->idEtablissement($etablissementNom);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idEtab]];

        return [$client, $entete];
    }

    protected function idEtablissement(string $nom): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => $nom]);
        self::assertNotNull($etab, sprintf('Établissement "%s" introuvable.', $nom));

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
