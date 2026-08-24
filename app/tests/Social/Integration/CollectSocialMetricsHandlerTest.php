<?php

declare(strict_types=1);

namespace App\Tests\Social\Integration;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Social\Crypto\SocialTokenCipher;
use App\Social\Dto\CollectedMetrics;
use App\Social\Entity\SocialAccount;
use App\Social\Entity\SocialMetricSnapshot;
use App\Social\Entity\SocialPost;
use App\Social\Entity\SocialPublication;
use App\Social\Enum\SocialPublicationStatus;
use App\Social\Exception\SocialPublishingException;
use App\Social\Message\CollectSocialMetrics;
use App\Social\MessageHandler\CollectSocialMetricsHandler;
use App\Social\Service\SocialMetricsCollectorRegistry;
use App\Tests\Social\SocialApiTestCase;
use App\Tests\Social\Support\FakeMetricsCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

/**
 * Collecte des statistiques (SOC-3, D14 contrainte 2).
 */
final class CollectSocialMetricsHandlerTest extends SocialApiTestCase
{
    public function testUnReleveEstEnregistreAvecSaChargeBrute(): void
    {
        [$publication, $collecteur, $handler] = $this->prepare();

        $handler(new CollectSocialMetrics((string) $publication->getId()));

        $releves = $this->releves($publication);
        self::assertCount(1, $releves);
        self::assertSame(12, $releves[0]->getLikes());
        self::assertSame(3, $releves[0]->getShares());
        self::assertNull($releves[0]->getImpressions(), 'Aucun reseau ouvert ne rend la portee.');
        // La charge brute est conservée en plus de la vue normalisée : sans elle, une redéfinition de
        // « portée » rendrait tout le passé incomparable sans qu'on puisse même le constater.
        self::assertSame(12, $releves[0]->getRawPayload()['favourites_count']);
        self::assertSame(1, $collecteur->calls);
    }

    /**
     * C'est la courbe qu'on cherche, pas la valeur du jour : un compteur écrasé à chaque passage
     * effacerait exactement ce que les plateformes ne rendront jamais rétroactivement.
     */
    public function testChaquePassageAjouteUneLigneEtNEcrasePas(): void
    {
        [$publication, $collecteur, $handler] = $this->prepare();
        $message = new CollectSocialMetrics((string) $publication->getId());

        $handler($message);
        $collecteur->willReturn(new CollectedMetrics(likes: 20, shares: 5, replies: 2, impressions: null, raw: ['favourites_count' => 20]));
        $handler($message);

        $releves = $this->releves($publication);
        self::assertCount(2, $releves);
        self::assertSame([12, 20], array_map(static fn (SocialMetricSnapshot $s): ?int => $s->getLikes(), $releves));
    }

    public function testRienNEstCollecteSurUnePublicationNonParue(): void
    {
        [$publication, $collecteur, $handler] = $this->prepare(status: SocialPublicationStatus::Pending);

        $handler(new CollectSocialMetrics((string) $publication->getId()));

        self::assertSame(0, $collecteur->calls, 'Rien n est paru : il n y a rien a mesurer.');
        self::assertCount(0, $this->releves($publication));
    }

    /**
     * Un statut supprimé chez le réseau ne réapparaîtra pas. Sans borne, la collecte planifiée le
     * redemanderait à chaque passage, pour toujours.
     */
    public function testStatutDisparuArreteDefinitivementLaCollecte(): void
    {
        [$publication, $collecteur, $handler] = $this->prepare();
        $collecteur->willThrow(SocialPublishingException::permanent('remote_post_gone', 'Disparu.'));

        $handler(new CollectSocialMetrics((string) $publication->getId()));

        $relue = $this->relire($publication);
        self::assertNotNull($relue->getMetricsStoppedAt());
        self::assertSame('remote_post_gone', $relue->getMetricsStoppedReason());

        // Deuxième passage : on ne redemande plus.
        $handler(new CollectSocialMetrics((string) $publication->getId()));
        self::assertSame(1, $collecteur->calls);
    }

    public function testQuotaDepasseDemandeUneReprisePlutotQueDArreter(): void
    {
        [$publication, $collecteur, $handler] = $this->prepare();
        $collecteur->willThrow(SocialPublishingException::retryable('rate_limited', 'Quota.'));

        try {
            $handler(new CollectSocialMetrics((string) $publication->getId()));
            self::fail('Une erreur reessayable doit demander une reprise.');
        } catch (RecoverableMessageHandlingException) {
            // attendu
        }

        // Aucune borne posée : un quota dépassé n'est pas la fin de la mesure.
        self::assertNull($this->relire($publication)->getMetricsStoppedAt());
        self::assertCount(0, $this->releves($publication));
    }

    public function testCompteHorsEtablissementDuMessageNEstJamaisInterroge(): void
    {
        [$publication, $collecteur, $handler] = $this->prepare();

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

        $handler(new CollectSocialMetrics((string) $publication->getId()));

        self::assertSame(0, $collecteur->calls);
        self::assertSame('scope_violation', $this->relire($publication)->getMetricsStoppedReason());
    }

    /**
     * @return array{0: SocialPublication, 1: FakeMetricsCollector, 2: CollectSocialMetricsHandler}
     */
    private function prepare(SocialPublicationStatus $status = SocialPublicationStatus::Published): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $etabA = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertNotNull($etabA);
        $compteA = $em->getRepository(SocialAccount::class)->findOneBy(['establishment' => $etabA]);
        self::assertNotNull($compteA);

        $post = new SocialPost();
        $post->setEstablishment($etabA)->setBody('Aquagym samedi 10h.');
        $publication = new SocialPublication();
        $publication->setAccount($compteA)->setStatus($status);
        if ($status === SocialPublicationStatus::Published) {
            $publication->setRemotePostId('109999');
        }
        $post->addPublication($publication);
        $em->persist($post);
        $em->persist($publication);
        $em->flush();
        $em->clear();

        $collecteur = new FakeMetricsCollector();
        /** @var SocialTokenCipher $cipher */
        $cipher = static::getContainer()->get(SocialTokenCipher::class);

        return [
            $publication,
            $collecteur,
            new CollectSocialMetricsHandler($em, new SocialMetricsCollectorRegistry([$collecteur]), $cipher),
        ];
    }

    /** @return list<SocialMetricSnapshot> */
    private function releves(SocialPublication $publication): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();

        /** @var list<SocialMetricSnapshot> $releves */
        $releves = $em->getRepository(SocialMetricSnapshot::class)
            ->createQueryBuilder('s')
            ->where('s.publication = :p')
            ->setParameter('p', $publication->getId(), 'uuid')
            ->orderBy('s.collectedAt', 'ASC')
            ->addOrderBy('s.likes', 'ASC')
            ->getQuery()
            ->getResult();

        return $releves;
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
