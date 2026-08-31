<?php

declare(strict_types=1);

namespace App\Tests\Marketing;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Crm\DataFixtures\CrmFixtures;
use App\DataFixtures\SocleFixtures;
use App\Marketing\DataFixtures\MarketingFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\SchemaDuHarnais;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Base des tests du module Campagnes.
 *
 * Charge le socle et le CRM : un module qui décrit des clients n'a rien à prouver sans clients, et
 * surtout rien à prouver sans le SECOND groupe — c'est lui qui rend le cloisonnement observable.
 */
abstract class MarketingApiTestCase extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();

        // Le schéma est construit UNE FOIS par processus, puis vidé entre les tests.
        SchemaDuHarnais::reinitialiser($em);

        foreach ([SocleFixtures::class, CrmFixtures::class, MarketingFixtures::class] as $classe) {
            $fixture = $container->get($classe);
            $fixture->load($em);
        }

        self::ensureKernelShutdown();
    }

    protected function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }

    /** @return array{0: Client, 1: array<string, mixed>} */
    protected function adminSurA(): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);
        $entete = [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => (string) $this->etablissementA()->getId()],
        ];

        return [$client, $entete];
    }

    /**
     * Un agent affecté au seul groupe B.
     *
     * C'est le personnage central de ces tests : sans lui, on ne vérifie pas un cloisonnement, on
     * vérifie qu'une requête rend des lignes.
     *
     * @return array{0: Client, 1: array<string, mixed>}
     */
    protected function agentSurGroupeB(): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, CrmFixtures::AGENT_B_EMAIL, CrmFixtures::AGENT_B_MDP);
        $etabC = $this->em()->getRepository(Etablissement::class)
            ->findOneBy(['nom' => CrmFixtures::ETAB_C_NOM]);
        $entete = [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => (string) $etabC?->getId()],
        ];

        return [$client, $entete];
    }

    protected function etablissementA(): Etablissement
    {
        $etablissement = $this->em()->getRepository(Etablissement::class)
            ->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etablissement);

        return $etablissement;
    }

    private function jeton(Client $client, string $email, string $motDePasse): string
    {
        return $client->request('POST', '/auth', [
            'json' => ['email' => $email, 'motDePasse' => $motDePasse],
        ])->toArray()['token'];
    }
}
