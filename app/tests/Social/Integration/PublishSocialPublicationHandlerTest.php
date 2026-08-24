<?php

declare(strict_types=1);

namespace App\Tests\Social\Integration;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Social\Crypto\SocialTokenCipher;
use App\Social\Entity\SocialAccount;
use App\Social\Entity\SocialPost;
use App\Social\Entity\SocialPublication;
use App\Social\Enum\SocialAccountStatus;
use App\Social\Enum\SocialPostStatus;
use App\Social\Enum\SocialPublicationStatus;
use App\Social\Message\PublishSocialPublication;
use App\Social\MessageHandler\PublishSocialPublicationHandler;
use App\Social\Service\SocialPublisherRegistry;
use App\Tests\Social\SocialApiTestCase;
use App\Tests\Social\Support\FakePublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

/**
 * Envoi asynchrone d'une publication (SOC-2, D7-bis).
 *
 * Le handler est instancié à la main avec un adaptateur pilotable : c'est le seul moyen d'éprouver le
 * classement des erreurs et l'idempotence sans dépendre d'un réseau qu'on ne contrôle pas.
 */
final class PublishSocialPublicationHandlerTest extends SocialApiTestCase
{
    public function testPublicationReussieEnregistreLIdentifiantDistant(): void
    {
        [$publication, $adaptateur, $handler] = $this->prepare();

        $handler(new PublishSocialPublication((string) $publication->getId()));

        $relue = $this->relire($publication);
        self::assertSame(SocialPublicationStatus::Published, $relue->getStatus());
        self::assertSame('remote-1', $relue->getRemotePostId());
        self::assertSame(1, $relue->getAttempts());
        self::assertNotNull($relue->getPublishedAt());
        self::assertSame(SocialPostStatus::Published, $relue->getPost()?->getStatus());
        self::assertSame(1, $adaptateur->calls);
    }

    /**
     * Le jeton est déchiffré au dernier moment et remis à l'adaptateur — jamais recopié dans le
     * message, qui dormirait alors en clair dans la table de la file, donc dans les sauvegardes.
     */
    public function testLeJetonEstDechiffreEtTransmisALAdaptateur(): void
    {
        [$publication, $adaptateur, $handler] = $this->prepare();

        $handler(new PublishSocialPublication((string) $publication->getId()));

        self::assertCount(1, $adaptateur->received);
        self::assertSame(\App\Social\DataFixtures\SocialFixtures::DEMO_ACCESS_TOKEN, $adaptateur->received[0]->accessToken);
        // Clé d'idempotence stable d'une tentative à l'autre : c'est l'identifiant de la publication.
        self::assertSame((string) $publication->getId(), $adaptateur->received[0]->idempotencyKey);
    }

    /**
     * Une file redélivre : c'est sa nature, pas une anomalie. Sans cette garde, un redémarrage du
     * worker ferait paraître deux fois le même message sur le fil d'un client.
     */
    public function testUnMessageRejoueNeRepublieerPas(): void
    {
        [$publication, $adaptateur, $handler] = $this->prepare();
        $message = new PublishSocialPublication((string) $publication->getId());

        $handler($message);
        $handler($message);

        self::assertSame(1, $adaptateur->calls, 'Le reseau ne doit avoir ete appele qu une seule fois.');
        self::assertSame(1, $this->relire($publication)->getAttempts());
    }

    public function testJetonRefuseMarqueLeCompteAReconnecter(): void
    {
        [$publication, $adaptateur, $handler] = $this->prepare();
        $adaptateur->willThrow(FakePublisher::unauthorized());

        $handler(new PublishSocialPublication((string) $publication->getId()));

        $relue = $this->relire($publication);
        self::assertSame(SocialPublicationStatus::Failed, $relue->getStatus());
        self::assertSame('unauthorized', $relue->getErrorCode());
        // C'est le refus du réseau qui fait foi sur l'état du jeton, pas notre horloge.
        self::assertSame(SocialAccountStatus::TokenExpired, $relue->getAccount()?->getStatus());
        self::assertSame(SocialPostStatus::Failed, $relue->getPost()?->getStatus());
    }

    public function testQuotaDepasseRemetEnAttenteEtDemandeUneReprise(): void
    {
        [$publication, $adaptateur, $handler] = $this->prepare();
        $adaptateur->willThrow(FakePublisher::rateLimited());

        try {
            $handler(new PublishSocialPublication((string) $publication->getId()));
            self::fail('Une erreur reessayable doit demander une reprise.');
        } catch (RecoverableMessageHandlingException) {
            // attendu
        }

        $relue = $this->relire($publication);
        // En attente et non « en cours » : si le worker meurt avant la reprise, l'état en base doit
        // dire qu'il reste quelque chose à faire.
        self::assertSame(SocialPublicationStatus::Pending, $relue->getStatus());
        self::assertSame('rate_limited', $relue->getErrorCode());
        self::assertSame(1, $relue->getAttempts());
    }

    /**
     * L'état terminal ne doit pas dépendre de la configuration de la file : une publication épuisée
     * qui resterait « en attente » pour toujours ne serait visible nulle part.
     */
    public function testTentativesEpuiseesFinissentEnEchecSansReprise(): void
    {
        [$publication, $adaptateur, $handler] = $this->prepare();
        $adaptateur->willThrow(FakePublisher::rateLimited());

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $aJour = $em->getRepository(SocialPublication::class)->find($publication->getId());
        self::assertNotNull($aJour);
        $aJour->setAttempts(5);
        $em->flush();
        $em->clear();

        $handler(new PublishSocialPublication((string) $publication->getId()));

        $relue = $this->relire($publication);
        self::assertSame(SocialPublicationStatus::Failed, $relue->getStatus());
        self::assertSame(6, $relue->getAttempts());
    }

    public function testCompteRevoqueEntreTempsEstIgnoreEtNonEchoue(): void
    {
        [$publication, $adaptateur, $handler] = $this->prepare();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $compte = $em->getRepository(SocialPublication::class)->find($publication->getId())?->getAccount();
        self::assertNotNull($compte);
        $compte->setStatus(SocialAccountStatus::Revoked)->setAccessTokenEncrypted(null);
        $em->flush();
        $em->clear();

        $handler(new PublishSocialPublication((string) $publication->getId()));

        // Ignorée et non échouée : rien n'a été demandé au réseau. Les confondre ferait chercher une
        // panne réseau là où le compte n'était simplement plus connecté.
        self::assertSame(SocialPublicationStatus::Skipped, $this->relire($publication)->getStatus());
        self::assertSame(0, $adaptateur->calls);
    }

    /**
     * Le contrôle qui n'a pas d'équivalent hors HTTP : l'identifiant vient d'un message, il n'y a
     * aucune session dont dériver un périmètre. On vérifie donc l'invariant sur l'entité résolue.
     */
    public function testCompteHorsEtablissementDuMessageNePublieJamais(): void
    {
        [$publication, $adaptateur, $handler] = $this->prepare();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etabB = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        $compteB = $em->getRepository(SocialAccount::class)->findOneBy(['establishment' => $etabB]);
        self::assertNotNull($compteB);

        $aJour = $em->getRepository(SocialPublication::class)->find($publication->getId());
        self::assertNotNull($aJour);
        $aJour->setAccount($compteB);
        $em->flush();
        $em->clear();

        $handler(new PublishSocialPublication((string) $publication->getId()));

        $relue = $this->relire($publication);
        self::assertSame(SocialPublicationStatus::Failed, $relue->getStatus());
        self::assertSame('scope_violation', $relue->getErrorCode());
        self::assertSame(0, $adaptateur->calls, 'Rien ne doit partir au nom d un autre etablissement.');
    }

    public function testPublicationInexistanteNeCassePas(): void
    {
        [, $adaptateur, $handler] = $this->prepare();

        $handler(new PublishSocialPublication('01912345-0000-7000-8000-000000000000'));
        $handler(new PublishSocialPublication('pas-un-uuid'));

        self::assertSame(0, $adaptateur->calls);
    }

    /**
     * @return array{0: SocialPublication, 1: FakePublisher, 2: PublishSocialPublicationHandler}
     */
    private function prepare(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $etabA = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertNotNull($etabA);
        $compteA = $em->getRepository(SocialAccount::class)->findOneBy(['establishment' => $etabA]);
        self::assertNotNull($compteA);

        $post = new SocialPost();
        $post->setEstablishment($etabA)->setBody('Aquagym samedi 10h, il reste des places.');
        $publication = new SocialPublication();
        $publication->setAccount($compteA);
        $post->addPublication($publication);
        $em->persist($post);
        $em->persist($publication);
        $em->flush();
        $em->clear();

        $adaptateur = new FakePublisher();
        /** @var SocialTokenCipher $cipher */
        $cipher = static::getContainer()->get(SocialTokenCipher::class);
        $handler = new PublishSocialPublicationHandler(
            $em,
            new SocialPublisherRegistry([$adaptateur]),
            $cipher,
        );

        return [$publication, $adaptateur, $handler];
    }

    private function relire(SocialPublication $publication): SocialPublication
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $relue = $em->getRepository(SocialPublication::class)->find($publication->getId());
        self::assertNotNull($relue);

        return $relue;
    }
}
