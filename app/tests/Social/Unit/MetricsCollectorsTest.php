<?php

declare(strict_types=1);

namespace App\Tests\Social\Unit;

use App\Social\Adapter\BlueskyMetricsCollector;
use App\Social\Adapter\MastodonMetricsCollector;
use App\Social\Dto\MetricsRequest;
use App\Social\Enum\SocialNetwork;
use App\Social\Exception\SocialPublishingException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Collecteurs de statistiques (SOC-3) — testés contre un client HTTP simulé.
 *
 * Les deux réseaux nomment les mêmes choses différemment (`favourites_count` / `likeCount`,
 * `reblogs_count` / `repostCount`) : la vue normalisée est donc une **interprétation**, et c'est
 * précisément pour cela qu'on conserve la charge brute à côté.
 */
final class MetricsCollectorsTest extends TestCase
{
    public function testMastodonNormaliseEtConserveLaChargeBrute(): void
    {
        $brut = ['id' => '109999', 'favourites_count' => 12, 'reblogs_count' => 3, 'replies_count' => 1, 'content' => '<p>Aquagym</p>'];
        $client = new MockHttpClient(new MockResponse(json_encode($brut, \JSON_THROW_ON_ERROR), ['http_code' => 200]));

        $releve = (new MastodonMetricsCollector($client))->collect($this->requeteMastodon());

        self::assertSame(12, $releve->likes);
        self::assertSame(3, $releve->shares);
        self::assertSame(1, $releve->replies);
        // Mastodon ne rend aucune portée : nul, et non zéro. « Zéro » et « non rendu » sont deux faits
        // différents, et les confondre fabriquerait des moyennes fausses.
        self::assertNull($releve->impressions);
        self::assertSame($brut, $releve->raw);
    }

    public function testMastodonStatutSupprimeEstDefinitif(): void
    {
        $client = new MockHttpClient(new MockResponse('{}', ['http_code' => 404]));

        try {
            (new MastodonMetricsCollector($client))->collect($this->requeteMastodon());
            self::fail('Un 404 doit lever.');
        } catch (SocialPublishingException $e) {
            // Un statut supprimé ne réapparaîtra pas : le redemander toutes les heures pendant des
            // mois consommerait le quota de l établissement pour rien.
            self::assertSame('remote_post_gone', $e->errorCode);
            self::assertFalse($e->retryable);
        }
    }

    public function testMastodonQuotaEstReessayable(): void
    {
        $client = new MockHttpClient(new MockResponse('{}', ['http_code' => 429]));

        try {
            (new MastodonMetricsCollector($client))->collect($this->requeteMastodon());
            self::fail('Un 429 doit lever.');
        } catch (SocialPublishingException $e) {
            self::assertTrue($e->retryable);
        }
    }

    public function testMastodonCompteurAbsentDonneNulEtNonZero(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode(['id' => '1'], \JSON_THROW_ON_ERROR), ['http_code' => 200]));

        $releve = (new MastodonMetricsCollector($client))->collect($this->requeteMastodon());

        self::assertNull($releve->likes);
        self::assertNull($releve->shares);
    }

    public function testBlueskyOuvreUneSessionPuisLit(): void
    {
        $appels = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$appels): MockResponse {
            $appels[] = $url;

            if (str_contains($url, 'createSession')) {
                return new MockResponse(json_encode(['accessJwt' => 'jwt', 'did' => 'did:plc:x'], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
            }

            return new MockResponse(json_encode([
                'posts' => [['uri' => 'at://x/y/3k', 'likeCount' => 7, 'repostCount' => 2, 'replyCount' => 0]],
            ], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });

        $releve = (new BlueskyMetricsCollector($client))->collect($this->requeteBluesky());

        self::assertCount(2, $appels);
        self::assertStringContainsString('com.atproto.server.createSession', $appels[0]);
        self::assertStringContainsString('app.bsky.feed.getPosts', $appels[1]);
        self::assertSame(7, $releve->likes);
        self::assertSame(2, $releve->shares);
        // Zéro rendu explicitement : celui-ci est bien zéro, pas « non rendu ».
        self::assertSame(0, $releve->replies);
        self::assertNull($releve->impressions);
        self::assertSame('at://x/y/3k', $releve->raw['uri']);
    }

    public function testBlueskyEnregistrementDisparuEstDefinitif(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['accessJwt' => 'jwt', 'did' => 'did:plc:x'], \JSON_THROW_ON_ERROR), ['http_code' => 200]),
            new MockResponse(json_encode(['posts' => []], \JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);

        try {
            (new BlueskyMetricsCollector($client))->collect($this->requeteBluesky());
            self::fail('Un tableau vide doit lever.');
        } catch (SocialPublishingException $e) {
            self::assertSame('remote_post_gone', $e->errorCode);
            self::assertFalse($e->retryable);
        }
    }

    public function testChaqueCollecteurNeSertQueSonReseau(): void
    {
        $mastodon = new MastodonMetricsCollector(new MockHttpClient());
        $bluesky = new BlueskyMetricsCollector(new MockHttpClient());

        self::assertTrue($mastodon->supports(SocialNetwork::Mastodon));
        self::assertFalse($mastodon->supports(SocialNetwork::Bluesky));
        self::assertTrue($bluesky->supports(SocialNetwork::Bluesky));
        self::assertFalse($bluesky->supports(SocialNetwork::Mastodon));
    }

    private function requeteMastodon(): MetricsRequest
    {
        return new MetricsRequest(
            network: SocialNetwork::Mastodon,
            host: 'https://mastodon.social',
            accessToken: 'jeton',
            handle: '@piscine@mastodon.social',
            remotePostId: '109999',
        );
    }

    private function requeteBluesky(): MetricsRequest
    {
        return new MetricsRequest(
            network: SocialNetwork::Bluesky,
            host: 'https://bsky.social',
            accessToken: 'mot-de-passe-application',
            handle: 'piscine.bsky.social',
            remotePostId: 'at://x/y/3k',
        );
    }
}
