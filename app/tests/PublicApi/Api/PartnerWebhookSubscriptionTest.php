<?php

declare(strict_types=1);

namespace App\Tests\PublicApi\Api;

use App\DataFixtures\SocleFixtures;
use App\PublicApi\Entity\PartnerWebhookSubscription;
use App\PublicApi\Webhook\PartnerWebhookCipher;
use App\PublicApi\Webhook\PartnerWebhookManager;
use App\Tests\PublicApi\PublicApiTestCase;

/**
 * L'abonnement aux webhooks, côté éditeur (spec API partenaire v1, §3.3) : le secret n'est rendu
 * qu'une fois, il est chiffré au repos avec l'URL, il se régénère ; liste d'événements fermée.
 */
final class PartnerWebhookSubscriptionTest extends PublicApiTestCase
{
    private const URL = 'https://93.184.216.34/fluvia/hook?token=jeton-du-partenaire';

    public function testLeSecretEstRenduUneFoisEtChiffreAuRepos(): void
    {
        $application = $this->createApplication();
        $configured = $this->configure($application['id'], ['url' => self::URL, 'events' => ['booking.cancelled', 'payment.failed']]);
        $secret = $configured['issuedWebhookSecret'];
        self::assertMatchesRegularExpression('/^whsec_[0-9a-f]{64}$/', $secret);
        self::assertSame('93.184.216.34', $configured['webhook']['host']);

        // Reconfigurer ne rend pas de secret ; la liste ne rend ni le secret ni l'URL.
        $again = $this->configure($application['id'], ['url' => self::URL, 'events' => ['booking.cancelled']]);
        self::assertNull($again['issuedWebhookSecret']);
        [$client, $headers] = $this->admin();
        $raw = $client->request('GET', '/api/editor/partner-applications', $headers)->getContent();
        self::assertStringContainsString('"host":"93.184.216.34"', $raw, 'sinon l’absence ci-dessous ne prouverait rien');
        self::assertStringNotContainsString($secret, $raw);
        self::assertStringNotContainsString('jeton-du-partenaire', $raw);

        // En base : chiffrés, et déchiffrables par le coffre.
        $this->em()->clear();
        $stored = $this->em()->getRepository(PartnerWebhookSubscription::class)->findAll()[0];
        self::assertNotSame('', $stored->getEncryptedSecret(), 'sinon l’absence ci-dessous ne prouverait rien');
        self::assertStringNotContainsString($secret, $stored->getEncryptedSecret());
        self::assertStringNotContainsString('jeton-du-partenaire', $stored->getEncryptedUrl());
        $cipher = static::getContainer()->get(PartnerWebhookCipher::class);
        self::assertSame($secret, $cipher->decrypt($stored->getEncryptedSecret()));

        // Régénérer rend un secret NEUF, et l'ancien ne déchiffre plus.
        [$client, $headers] = $this->admin();
        $rotated = $client->request('POST', '/api/editor/partner-applications/'.$application['id'].'/webhook/rotate-secret', $headers)->toArray();
        self::assertNotSame($secret, $rotated['issuedWebhookSecret']);
        $this->em()->clear();
        $stored = $this->em()->getRepository(PartnerWebhookSubscription::class)->findAll()[0];
        self::assertSame($rotated['issuedWebhookSecret'], $cipher->decrypt($stored->getEncryptedSecret()));
        self::assertSame($this->idEtablissement(SocleFixtures::ETAB_A_NOM), $this->auditEstablishment(PartnerWebhookManager::ACTION_SECRET_ROTATED, (string) $stored->getId()));
    }

    public function testUneUrlInterneOuUnEvenementHorsCatalogueEstRefuse(): void
    {
        $application = $this->createApplication();

        foreach ([
            ['url' => 'https://10.0.0.1/hook', 'events' => ['booking.cancelled']],
            ['url' => 'http://93.184.216.34/hook', 'events' => ['booking.cancelled']],
            ['url' => self::URL, 'events' => ['customer.came_of_age']],
            ['url' => self::URL, 'events' => []],
        ] as $body) {
            self::assertSame(422, $this->configure($application['id'], $body, false)['status'], json_encode($body));
        }
        self::assertCount(0, $this->em()->getRepository(PartnerWebhookSubscription::class)->findAll());
    }

    /** Hors du tenant éditeur, 404 ; témoin : l'éditeur configure. */
    public function testUnNonEditeurNeConfigurePasDeWebhook(): void
    {
        $application = $this->createApplication();
        [$client, $headers] = $this->admin(SocleFixtures::ETAB_B_NOM);
        $refused = $client->request('POST', '/api/editor/partner-applications/'.$application['id'].'/webhook', $headers + [
            'json' => ['url' => self::URL, 'events' => ['booking.cancelled']],
        ]);
        self::assertSame(404, $refused->getStatusCode());
        self::assertArrayHasKey('issuedWebhookSecret', $this->configure($application['id'], ['url' => self::URL, 'events' => ['booking.cancelled']]));
    }

    /** Le marqueur de `app/.env` n'est pas une clé : refusé hors test. */
    public function testLeMarqueurDeCleEstRefuseHorsDuTest(): void
    {
        $this->expectException(\LogicException::class);
        (new PartnerWebhookCipher('A_GENERER_PAR_LE_DEPLOIEMENT_VOIR_infra_env.preprod.example', 'prod'))->encrypt('https://x.example');
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function configure(string $applicationId, array $body, bool $expectSuccess = true): array
    {
        [$client, $headers] = $this->admin();
        $response = $client->request('POST', '/api/editor/partner-applications/'.$applicationId.'/webhook', $headers + ['json' => $body]);
        if (!$expectSuccess) {
            return ['status' => $response->getStatusCode()];
        }
        self::assertResponseIsSuccessful();

        return $response->toArray();
    }
}
