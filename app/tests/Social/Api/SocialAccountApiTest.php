<?php

declare(strict_types=1);

namespace App\Tests\Social\Api;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Social\Crypto\SocialTokenCipher;
use App\Social\DataFixtures\SocialFixtures;
use App\Social\Entity\SocialAccount;
use App\Social\Enum\SocialAccountStatus;
use App\Tests\Social\SocialApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Connexion d'un compte social (SOC-1) — l'exigence centrale du lot : **un jeton ne sort jamais**.
 */
final class SocialAccountApiTest extends SocialApiTestCase
{
    public function testConnexionChiffreLeJetonEtNeLeRenvoieJamais(): void
    {
        [$client, $entete] = $this->adminSurA();
        $jetonEnClair = 'mastodon-token-en-clair-a-ne-jamais-revoir';

        $reponse = $client->request('POST', '/api/social_accounts', [
            'json' => [
                'network' => 'mastodon',
                'host' => 'https://piscines.example',
                'remoteAccountId' => '109000000000000042',
                'handle' => '@nouveau@piscines.example',
                'accessTokenPlain' => $jetonEnClair,
            ],
        ] + $entete);

        self::assertResponseStatusCodeSame(201, (string) $client->getResponse()->getContent(false));

        $corps = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString($jetonEnClair, $corps, 'Le jeton en clair est ressorti de l API.');
        self::assertStringNotContainsString('accessTokenEncrypted', $corps, 'Le champ chiffré ne doit pas être sérialisé du tout.');
        self::assertStringNotContainsString('accessTokenPlain', $corps);

        $donnees = $reponse->toArray();
        self::assertTrue($donnees['hasAccessToken'], 'Le coffre doit annoncer qu il détient un jeton.');

        // En base : chiffré, et déchiffrable — un chiffrement de façade qui perdrait le jeton serait
        // aussi grave qu un jeton en clair, mais invisible jusqu à la première publication.
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $compte = $em->getRepository(SocialAccount::class)->findOneBy(['remoteAccountId' => '109000000000000042']);
        self::assertNotNull($compte);
        $stocke = (string) $compte->getAccessTokenEncrypted();
        self::assertNotSame($jetonEnClair, $stocke);
        self::assertStringNotContainsString($jetonEnClair, $stocke);

        /** @var SocialTokenCipher $cipher */
        $cipher = static::getContainer()->get(SocialTokenCipher::class);
        self::assertSame($jetonEnClair, $cipher->decrypt($stocke));
    }

    public function testLectureDUnCompteExistantNExposePasLeJeton(): void
    {
        [$client, $entete] = $this->adminSurA();
        $etabA = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);
        $compteA = $this->entite(SocialAccount::class, ['establishment' => $etabA]);

        $client->request('GET', '/api/social_accounts/' . $compteA->getId(), $entete);
        self::assertResponseIsSuccessful();

        $corps = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString(SocialFixtures::DEMO_ACCESS_TOKEN, $corps);
        self::assertStringNotContainsString('accessTokenEncrypted', $corps);
        self::assertStringContainsString('hasAccessToken', $corps);
    }

    public function testConnexionSansJetonRefusee(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/social_accounts', [
            'json' => [
                'network' => 'bluesky',
                'remoteAccountId' => 'did:plc:sansjeton000000000000000',
                'handle' => 'sansjeton.bsky.social',
            ],
        ] + $entete);

        // Un compte connecté sans jeton est un compte qui ne publiera jamais : il vaut mieux le
        // refuser à la création que de le découvrir au premier envoi.
        self::assertResponseStatusCodeSame(422, (string) $client->getResponse()->getContent(false));
    }

    public function testHoteParDefautAppliquePourBluesky(): void
    {
        [$client, $entete] = $this->adminSurA();

        $reponse = $client->request('POST', '/api/social_accounts', [
            'json' => [
                'network' => 'bluesky',
                'remoteAccountId' => 'did:plc:avecdefaut00000000000000',
                'handle' => 'avecdefaut.bsky.social',
                'accessTokenPlain' => 'bluesky-app-password-demo',
            ],
        ] + $entete);

        self::assertResponseStatusCodeSame(201, (string) $client->getResponse()->getContent(false));
        self::assertSame('https://bsky.social', $reponse->toArray()['host']);
    }

    public function testMastodonSansHoteRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/social_accounts', [
            'json' => [
                'network' => 'mastodon',
                'remoteAccountId' => '109000000000000099',
                'handle' => '@sanshote@quelquepart',
                'accessTokenPlain' => 'mastodon-token-sans-hote',
            ],
        ] + $entete);

        // Mastodon est fédéré : un jeton sans instance ne vaut pour personne.
        self::assertResponseStatusCodeSame(422, (string) $client->getResponse()->getContent(false));
    }

    public function testRevocationEffaceLesJetons(): void
    {
        [$client, $entete] = $this->adminSurA();
        $etabA = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);
        $compteA = $this->entite(SocialAccount::class, ['establishment' => $etabA]);

        $client->request('PATCH', '/api/social_accounts/' . $compteA->getId(), [
            'headers' => $entete['headers'] + ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['status' => 'revoked'],
        ] + $entete);
        self::assertResponseIsSuccessful();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $apres = $em->getRepository(SocialAccount::class)->find($compteA->getId());
        self::assertNotNull($apres);
        self::assertSame(SocialAccountStatus::Revoked, $apres->getStatus());
        // Révoquer, c est cesser de détenir : garder le jeton « au cas où » serait garder de quoi
        // publier au nom de quelqu un qui a demandé qu on ne le puisse plus.
        self::assertNull($apres->getAccessTokenEncrypted());
        self::assertNull($apres->getRefreshTokenEncrypted());
    }

    public function testMemeCompteConnecteDeuxFoisRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $etabA = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);
        $compteA = $this->entite(SocialAccount::class, ['establishment' => $etabA]);

        $client->request('POST', '/api/social_accounts', [
            'json' => [
                'network' => $compteA->getNetwork()->value,
                'host' => $compteA->getHost(),
                'remoteAccountId' => $compteA->getRemoteAccountId(),
                'handle' => $compteA->getHandle(),
                'accessTokenPlain' => 'un-second-jeton',
            ],
        ] + $entete);

        // Deux lignes pour le même compte distant, et plus personne ne sait laquelle publie.
        self::assertResponseStatusCodeSame(409, (string) $client->getResponse()->getContent(false));
    }
}
