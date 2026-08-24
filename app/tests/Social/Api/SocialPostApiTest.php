<?php

declare(strict_types=1);

namespace App\Tests\Social\Api;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Social\Entity\SocialAccount;
use App\Social\Entity\SocialPost;
use App\Social\Entity\SocialPublication;
use App\Social\Enum\SocialAccountStatus;
use App\Social\Enum\SocialPostStatus;
use App\Social\Enum\SocialPublicationStatus;
use App\Tests\Social\SocialApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Rédaction d'un message et création de ses publications (SOC-1, D14).
 */
final class SocialPostApiTest extends SocialApiTestCase
{
    public function testUnPostCreeUnePublicationParCompteVise(): void
    {
        [$client, $entete] = $this->adminSurA();
        $compteA = $this->compteDe(SocleFixtures::ETAB_A_NOM);

        $reponse = $client->request('POST', '/api/social_posts', [
            'json' => [
                'body' => 'Aquagym samedi 10h, il reste des places.',
                'targetAccounts' => ['/api/social_accounts/' . $compteA->getId()],
            ],
        ] + $entete);

        self::assertResponseStatusCodeSame(201, (string) $client->getResponse()->getContent(false));
        $donnees = $reponse->toArray();
        self::assertSame('draft', $donnees['status']);
        self::assertCount(1, $donnees['publications']);
        self::assertSame('pending', $donnees['publications'][0]['status']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $publications = $em->getRepository(SocialPublication::class)->findAll();
        self::assertCount(1, $publications);
        self::assertSame((string) $compteA->getId(), (string) $publications[0]->getAccount()?->getId());
    }

    /**
     * Le test central du lot : les comptes visés arrivent du client, donc c'est par là qu'on entre.
     */
    public function testViserLeCompteDUnAutreEtablissementEstIntrouvable(): void
    {
        [$client, $entete] = $this->adminSurA();
        $compteB = $this->compteDe(SocleFixtures::ETAB_B_NOM);

        $client->request('POST', '/api/social_posts', [
            'json' => [
                'body' => 'Message publie au nom de quelqu un d autre.',
                'targetAccounts' => ['/api/social_accounts/' . $compteB->getId()],
            ],
        ] + $entete);

        // 404 et non 403 : un 403 confirmerait l'existence du compte de B.
        self::assertResponseStatusCodeSame(404, (string) $client->getResponse()->getContent(false));

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        self::assertCount(0, $em->getRepository(SocialPost::class)->findAll(), 'Aucun message ne doit avoir ete cree.');
        self::assertCount(0, $em->getRepository(SocialPublication::class)->findAll());
    }

    public function testMessageSansDestinationRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/social_posts', [
            'json' => ['body' => 'Un message qui ne partirait nulle part.', 'targetAccounts' => []],
        ] + $entete);

        self::assertResponseStatusCodeSame(422, (string) $client->getResponse()->getContent(false));
    }

    public function testMemeCompteViseDeuxFoisRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $compteA = $this->compteDe(SocleFixtures::ETAB_A_NOM);
        $iri = '/api/social_accounts/' . $compteA->getId();

        $client->request('POST', '/api/social_posts', [
            'json' => ['body' => 'Deux fois le meme compte.', 'targetAccounts' => [$iri, $iri]],
        ] + $entete);

        self::assertResponseStatusCodeSame(422, (string) $client->getResponse()->getContent(false));
    }

    public function testCompteRevoqueNePeutPasEtreVise(): void
    {
        [$client, $entete] = $this->adminSurA();
        $compteA = $this->compteDe(SocleFixtures::ETAB_A_NOM);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $compte = $em->getRepository(SocialAccount::class)->find($compteA->getId());
        self::assertNotNull($compte);
        $compte->setStatus(SocialAccountStatus::Revoked)->setAccessTokenEncrypted(null);
        $em->flush();

        $client->request('POST', '/api/social_posts', [
            'json' => [
                'body' => 'Vers un compte deconnecte.',
                'targetAccounts' => ['/api/social_accounts/' . $compteA->getId()],
            ],
        ] + $entete);

        self::assertResponseStatusCodeSame(422, (string) $client->getResponse()->getContent(false));
    }

    /**
     * Bluesky s'arrête à 300 caractères : un message de 301 doit être refusé à la rédaction, pas
     * découvert au moment de l'envoi.
     */
    public function testMessageTropLongPourLeReseauViseRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $compteBluesky = $this->creerCompteBlueskySurA();

        $client->request('POST', '/api/social_posts', [
            'json' => [
                'body' => str_repeat('a', 301),
                'targetAccounts' => ['/api/social_accounts/' . $compteBluesky->getId()],
            ],
        ] + $entete);

        self::assertResponseStatusCodeSame(422, (string) $client->getResponse()->getContent(false));
    }

    public function testMemeLongueurAccepteeSurMastodon(): void
    {
        [$client, $entete] = $this->adminSurA();
        $compteA = $this->compteDe(SocleFixtures::ETAB_A_NOM);

        $client->request('POST', '/api/social_posts', [
            'json' => [
                'body' => str_repeat('a', 301),
                'targetAccounts' => ['/api/social_accounts/' . $compteA->getId()],
            ],
        ] + $entete);

        // Mastodon accepte 500 : la limite est bien évaluée par réseau visé, pas globalement.
        self::assertResponseStatusCodeSame(201, (string) $client->getResponse()->getContent(false));
    }

    public function testLeJetonNApparaitPasDansLeMessagePublie(): void
    {
        [$client, $entete] = $this->adminSurA();
        $compteA = $this->compteDe(SocleFixtures::ETAB_A_NOM);

        $client->request('POST', '/api/social_posts', [
            'json' => [
                'body' => 'Un message ordinaire.',
                'targetAccounts' => ['/api/social_accounts/' . $compteA->getId()],
            ],
        ] + $entete);
        self::assertResponseStatusCodeSame(201, (string) $client->getResponse()->getContent(false));

        // La publication expose son compte : la réponse traverse donc SocialAccount, et c'est
        // précisément le chemin par lequel un jeton pourrait ressortir sans qu'on y pense.
        $corps = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('accessTokenEncrypted', $corps);
        self::assertStringNotContainsString(\App\Social\DataFixtures\SocialFixtures::DEMO_ACCESS_TOKEN, $corps);
    }

    public function testEtatRecalculeDepuisLesPublications(): void
    {
        $post = new SocialPost();
        self::assertSame(SocialPostStatus::Draft, $post->recomputeStatus()->getStatus());

        $reussie = (new SocialPublication())->setStatus(SocialPublicationStatus::Published);
        $echouee = (new SocialPublication())->setStatus(SocialPublicationStatus::Failed);
        $enAttente = new SocialPublication();

        $post->addPublication($reussie)->addPublication($enAttente);
        self::assertSame(SocialPostStatus::Publishing, $post->recomputeStatus()->getStatus(), 'Une ligne encore en attente : ni publie, ni echoue.');

        $enAttente->setStatus(SocialPublicationStatus::Published);
        self::assertSame(SocialPostStatus::Published, $post->recomputeStatus()->getStatus());

        $post->addPublication($echouee);
        self::assertSame(SocialPostStatus::PartiallyFailed, $post->recomputeStatus()->getStatus());

        $reussie->setStatus(SocialPublicationStatus::Failed);
        $enAttente->setStatus(SocialPublicationStatus::Skipped);
        self::assertSame(SocialPostStatus::Failed, $post->recomputeStatus()->getStatus(), 'Ignoree n est pas reussie.');
    }

    private function compteDe(string $nomEtablissement): SocialAccount
    {
        $etab = $this->entite(Etablissement::class, ['nom' => $nomEtablissement]);

        return $this->entite(SocialAccount::class, ['establishment' => $etab]);
    }

    private function creerCompteBlueskySurA(): SocialAccount
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etabA = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertNotNull($etabA);

        $compte = new SocialAccount();
        $compte->setEstablishment($etabA)
            ->setNetwork(\App\Social\Enum\SocialNetwork::Bluesky)
            ->setHost('https://bsky.social')
            ->setRemoteAccountId('did:plc:limitetest000000000000000')
            ->setHandle('limite-test.bsky.social')
            ->setAccessTokenEncrypted('chiffre-inerte-de-test')
            ->setStatus(SocialAccountStatus::Connected);
        $em->persist($compte);
        $em->flush();

        return $compte;
    }
}
