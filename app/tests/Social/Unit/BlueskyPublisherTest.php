<?php

declare(strict_types=1);

namespace App\Tests\Social\Unit;

use App\Social\Adapter\BlueskyPublisher;
use App\Social\Dto\PublicationRequest;
use App\Social\Enum\SocialNetwork;
use App\Social\Exception\SocialPublishingException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Adaptateur Bluesky (SOC-2) — protocole AT, en deux appels : ouverture de session avec le mot de
 * passe d'application, puis écriture de l'enregistrement.
 */
final class BlueskyPublisherTest extends TestCase
{
    private const APP_PASSWORD = 'bluesky-mot-de-passe-application';
    private const DID = 'did:plc:demo0000000000000000000b';

    public function testPublicationEnDeuxAppels(): void
    {
        $appels = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$appels): MockResponse {
            $appels[] = $url;

            if (str_contains($url, 'createSession')) {
                return new MockResponse(
                    json_encode(['accessJwt' => 'jwt-de-session', 'did' => self::DID], \JSON_THROW_ON_ERROR),
                    ['http_code' => 200],
                );
            }

            return new MockResponse(
                json_encode(['uri' => 'at://' . self::DID . '/app.bsky.feed.post/3kabc123', 'cid' => 'bafy'], \JSON_THROW_ON_ERROR),
                ['http_code' => 200],
            );
        });

        $resultat = (new BlueskyPublisher($client))->publish($this->requete());

        self::assertCount(2, $appels);
        self::assertStringContainsString('com.atproto.server.createSession', $appels[0]);
        self::assertStringContainsString('com.atproto.repo.createRecord', $appels[1]);
        self::assertSame('at://' . self::DID . '/app.bsky.feed.post/3kabc123', $resultat->remotePostId);
        self::assertSame('https://bsky.app/profile/piscine-b.bsky.social/post/3kabc123', $resultat->remoteUrl);
    }

    /**
     * L'URI AT n'est pas cliquable ; l'adresse web se reconstruit. Si la forme change, on rend `null`
     * plutôt qu'un lien faux — un lien mort dans un historique fait douter de tout le reste.
     */
    public function testUriInattendueNeProduitPasDeLienFaux(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['accessJwt' => 'jwt', 'did' => self::DID], \JSON_THROW_ON_ERROR), ['http_code' => 200]),
            new MockResponse(json_encode(['uri' => 'sansbarreoblique'], \JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);

        $resultat = (new BlueskyPublisher($client))->publish($this->requete());

        self::assertSame('sansbarreoblique', $resultat->remotePostId);
        self::assertSame('https://bsky.app/profile/piscine-b.bsky.social/post/sansbarreoblique', $resultat->remoteUrl);
    }

    public function testMotDePasseRefuseALOuvertureDeSession(): void
    {
        $client = new MockHttpClient(new MockResponse('{}', ['http_code' => 401]));

        try {
            (new BlueskyPublisher($client))->publish($this->requete());
            self::fail('Un 401 a la session doit lever.');
        } catch (SocialPublishingException $e) {
            self::assertSame('unauthorized', $e->errorCode);
            self::assertFalse($e->retryable);
            self::assertStringNotContainsString(self::APP_PASSWORD, $e->getMessage());
        }
    }

    public function testEchecALEcritureApresSessionOuverte(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['accessJwt' => 'jwt', 'did' => self::DID], \JSON_THROW_ON_ERROR), ['http_code' => 200]),
            new MockResponse('{}', ['http_code' => 429]),
        ]);

        try {
            (new BlueskyPublisher($client))->publish($this->requete());
            self::fail('Un 429 a l ecriture doit lever.');
        } catch (SocialPublishingException $e) {
            self::assertSame('rate_limited', $e->errorCode);
            self::assertTrue($e->retryable);
        }
    }

    public function testSessionSansJetonRefusee(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode(['did' => self::DID], \JSON_THROW_ON_ERROR), ['http_code' => 200]));

        try {
            (new BlueskyPublisher($client))->publish($this->requete());
            self::fail('Une session sans jeton doit lever.');
        } catch (SocialPublishingException $e) {
            self::assertSame('missing_session_token', $e->errorCode);
        }
    }

    public function testHoteParDefautQuandAucunNEstFourni(): void
    {
        $premierUrl = null;
        $client = new MockHttpClient(function (string $method, string $url) use (&$premierUrl): MockResponse {
            $premierUrl ??= $url;

            if (str_contains($url, 'createSession')) {
                return new MockResponse(json_encode(['accessJwt' => 'jwt', 'did' => self::DID], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
            }

            return new MockResponse(json_encode(['uri' => 'at://x/y/3k'], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });

        (new BlueskyPublisher($client))->publish($this->requete(host: null));

        self::assertStringStartsWith('https://bsky.social/', (string) $premierUrl);
    }

    public function testNeSertQueBluesky(): void
    {
        $publisher = new BlueskyPublisher(new MockHttpClient());

        self::assertTrue($publisher->supports(SocialNetwork::Bluesky));
        self::assertFalse($publisher->supports(SocialNetwork::Mastodon));
    }

    private function requete(?string $host = 'https://bsky.social'): PublicationRequest
    {
        return new PublicationRequest(
            network: SocialNetwork::Bluesky,
            host: $host,
            accessToken: self::APP_PASSWORD,
            remoteAccountId: self::DID,
            handle: 'piscine-b.bsky.social',
            body: 'Aquagym samedi 10h.',
            idempotencyKey: 'cle-stable-123',
        );
    }
}
