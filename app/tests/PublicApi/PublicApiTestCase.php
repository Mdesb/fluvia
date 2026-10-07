<?php

declare(strict_types=1);

namespace App\Tests\PublicApi;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Audit\Entity\EntreeAudit;
use App\DataFixtures\SocleFixtures;
use App\Tests\SocleApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le montage commun des tests de l'API partenaire : l'éditeur est « Piscine A » (comme dans
 * `SupportAccessApiTest`), et chaque clé est émise PAR L'API, jamais posée en base — c'est le critère
 * d'acceptation n°1 (« sans toucher à la base »).
 */
abstract class PublicApiTestCase extends SocleApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $id = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $_ENV['EDITOR_TENANT_ID'] = $id;
        $_SERVER['EDITOR_TENANT_ID'] = $id;
    }

    /**
     * L'administratrice, l'établissement actif étant `$establishment` (A = l'éditeur, B = un client).
     *
     * @return array{0: Client, 1: array<string, mixed>}
     */
    protected function admin(string $establishment = SocleFixtures::ETAB_A_NOM): array
    {
        $client = static::createClient();

        return [$client, [
            'auth_bearer' => $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP),
            'headers' => ['X-Etablissement' => $this->idEtablissement($establishment)],
        ]];
    }

    /** @return array<string, mixed> la fiche de l'application créée */
    protected function createApplication(string $name = 'Agrégateur IT Cotation'): array
    {
        [$client, $headers] = $this->admin();
        $application = $client->request('POST', '/api/editor/partner-applications', $headers + [
            'json' => ['name' => $name, 'contactEmail' => 'integration@example.test'],
        ])->toArray();
        self::assertResponseIsSuccessful();

        return $application;
    }

    /** @return array{secret: string, credentialId: string} */
    protected function issueKey(string $applicationId): array
    {
        [$client, $headers] = $this->admin();
        $issued = $client->request('POST', '/api/editor/partner-applications/'.$applicationId.'/credentials', $headers + [
            'json' => new \stdClass(),
        ])->toArray();
        self::assertResponseIsSuccessful();

        return ['secret' => (string) $issued['issuedSecret'], 'credentialId' => (string) $issued['issuedCredentialId']];
    }

    /** @return array{0: int, 1: array<string, mixed>} le statut de `GET /v1/me` et son corps */
    protected function me(string $secret): array
    {
        $response = static::createClient()->request('GET', '/v1/me', ['headers' => ['Authorization' => 'Bearer '.$secret]]);

        return [$response->getStatusCode(), 200 === $response->getStatusCode() ? $response->toArray() : []];
    }

    protected function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }

    /** L'unique entrée d'audit d'un geste, et l'établissement auquel elle est rattachée. */
    protected function auditEstablishment(string $action, string $targetId): string
    {
        $this->em()->clear();
        $entries = $this->em()->getRepository(EntreeAudit::class)->findBy(['action' => $action, 'cibleId' => $targetId]);
        self::assertCount(1, $entries, sprintf('Le geste « %s » sur %s doit laisser exactement une trace.', $action, $targetId));

        return (string) $entries[0]->getEtablissement();
    }
}
