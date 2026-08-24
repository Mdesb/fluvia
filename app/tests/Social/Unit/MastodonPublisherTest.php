<?php

declare(strict_types=1);

namespace App\Tests\Social\Unit;

use App\Social\Adapter\MastodonPublisher;
use App\Social\Dto\PublicationRequest;
use App\Social\Enum\SocialNetwork;
use App\Social\Exception\SocialPublishingException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Adaptateur Mastodon (SOC-2) — testé contre un client HTTP simulé, sans base ni réseau.
 */
final class MastodonPublisherTest extends TestCase
{
    private const TOKEN = 'mastodon-secret-token-a-ne-pas-fuir';

    public function testPublicationReussie(): void
    {
        $client = new MockHttpClient(new MockResponse(
            json_encode(['id' => '109999', 'url' => 'https://mastodon.social/@piscine/109999'], \JSON_THROW_ON_ERROR),
            ['http_code' => 200],
        ));

        $resultat = (new MastodonPublisher($client))->publish($this->requete());

        self::assertSame('109999', $resultat->remotePostId);
        self::assertSame('https://mastodon.social/@piscine/109999', $resultat->remoteUrl);
    }

    /**
     * Sans clé d'idempotence, une coupure après l'envoi mais avant la réponse ferait republier le même
     * message à la reprise — deux fois sur le fil du client, sans que personne sache pourquoi.
     */
    public function testLaCleDIdempotenceEstTransmise(): void
    {
        $vues = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$vues): MockResponse {
            $vues = ['method' => $method, 'url' => $url, 'headers' => $options['headers'] ?? []];

            return new MockResponse(json_encode(['id' => '1'], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });

        (new MastodonPublisher($client))->publish($this->requete());

        self::assertSame('POST', $vues['method']);
        self::assertSame('https://mastodon.social/api/v1/statuses', $vues['url']);
        self::assertContains('Idempotency-Key: cle-stable-123', $vues['headers']);
    }

    public function testJetonRefuseEstDefinitif(): void
    {
        $client = new MockHttpClient(new MockResponse('{}', ['http_code' => 401]));

        try {
            (new MastodonPublisher($client))->publish($this->requete());
            self::fail('Un 401 doit lever.');
        } catch (SocialPublishingException $e) {
            self::assertSame('unauthorized', $e->errorCode);
            self::assertFalse($e->retryable, 'Reessayer cinq fois un jeton revoque ne le rendra pas valide.');
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    public function testQuotaDepasseEstReessayable(): void
    {
        $client = new MockHttpClient(new MockResponse('{}', ['http_code' => 429]));

        try {
            (new MastodonPublisher($client))->publish($this->requete());
            self::fail('Un 429 doit lever.');
        } catch (SocialPublishingException $e) {
            self::assertSame('rate_limited', $e->errorCode);
            self::assertTrue($e->retryable, 'Un quota depasse repasse tout seul : c est la raison d etre de la file.');
        }
    }

    public function testPanneServeurEstReessayable(): void
    {
        $client = new MockHttpClient(new MockResponse('{}', ['http_code' => 503]));

        try {
            (new MastodonPublisher($client))->publish($this->requete());
            self::fail('Un 503 doit lever.');
        } catch (SocialPublishingException $e) {
            self::assertTrue($e->retryable);
        }
    }

    public function testRefusApplicatifEstDefinitif(): void
    {
        $client = new MockHttpClient(new MockResponse('{}', ['http_code' => 422]));

        try {
            (new MastodonPublisher($client))->publish($this->requete());
            self::fail('Un 422 doit lever.');
        } catch (SocialPublishingException $e) {
            self::assertFalse($e->retryable);
        }
    }

    /**
     * Un 2xx sans identifiant : on ne pourrait ni prouver la parution, ni aller chercher les
     * statistiques plus tard. Le déclarer publié serait un mensonge dans l'historique.
     */
    public function testAcceptationSansIdentifiantRefusee(): void
    {
        $client = new MockHttpClient(new MockResponse('{}', ['http_code' => 200]));

        try {
            (new MastodonPublisher($client))->publish($this->requete());
            self::fail('Une acceptation sans identifiant doit lever.');
        } catch (SocialPublishingException $e) {
            self::assertSame('missing_remote_id', $e->errorCode);
            self::assertFalse($e->retryable);
        }
    }

    public function testInstanceInconnueEstDefinitive(): void
    {
        $client = new MockHttpClient(new MockResponse('{}', ['http_code' => 200]));

        try {
            (new MastodonPublisher($client))->publish($this->requete(host: null));
            self::fail('Mastodon sans instance doit lever.');
        } catch (SocialPublishingException $e) {
            self::assertSame('missing_host', $e->errorCode);
            self::assertFalse($e->retryable);
        }
    }

    public function testNeSertQueMastodon(): void
    {
        $publisher = new MastodonPublisher(new MockHttpClient());

        self::assertTrue($publisher->supports(SocialNetwork::Mastodon));
        self::assertFalse($publisher->supports(SocialNetwork::Bluesky));
    }

    private function requete(?string $host = 'https://mastodon.social'): PublicationRequest
    {
        return new PublicationRequest(
            network: SocialNetwork::Mastodon,
            host: $host,
            accessToken: self::TOKEN,
            remoteAccountId: '109000000000000001',
            handle: '@piscine@mastodon.social',
            body: 'Aquagym samedi 10h.',
            idempotencyKey: 'cle-stable-123',
        );
    }
}
