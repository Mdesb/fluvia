<?php

declare(strict_types=1);

namespace App\Tests\Social\Integration;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Platform\Module\ModuleGuard;
use App\Social\Crypto\SocialTokenCipher;
use App\Social\Entity\SocialAccount;
use App\Social\Entity\SocialPost;
use App\Social\Entity\SocialPublication;
use App\Social\Enum\SocialPublicationStatus;
use App\Social\Message\CollectSocialMetrics;
use App\Social\Message\PublishSocialPublication;
use App\Social\MessageHandler\CollectSocialMetricsHandler;
use App\Social\MessageHandler\PublishSocialPublicationHandler;
use App\Social\Service\SocialMetricsCollectorRegistry;
use App\Social\Service\SocialPublisherRegistry;
use App\Tests\Platform\ModuleSwitch;
use App\Tests\Social\SocialApiTestCase;
use App\Tests\Social\Support\FakeMetricsCollector;
use App\Tests\Social\Support\FakePublisher;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Recentrage, PR 2 (d) — les deux gestionnaires de messages asynchrones de Social ne publient plus
 * et ne relèvent plus rien pour un établissement où le module est éteint.
 *
 * Un message déjà en file au moment de l'extinction arrive quand même au gestionnaire : c'est là que
 * la garde doit tenir. Les adaptateurs réseau sont des doublures qui comptent leurs appels. Éteint :
 * aucun appel au réseau ; une publication en file passe « sautée » (motif `module_disabled`), un relevé ne change rien. Allumé : un appel (témoin).
 */
final class SwitchedOffSocialHandlersTest extends SocialApiTestCase
{
    use ModuleSwitch;

    public function testAQueuedPublicationIsNotPublishedWhenSocialIsOff(): void
    {
        foreach ([false, true] as $on) {
            $this->switchModule('social', $on, SocleFixtures::ETAB_A_NOM);
            $publication = $this->publication(SocialPublicationStatus::Pending);
            $publisher = new FakePublisher();
            $handler = new PublishSocialPublicationHandler(
                $this->em(),
                new SocialPublisherRegistry([$publisher]),
                static::getContainer()->get(SocialTokenCipher::class),
                static::getContainer()->get(ModuleGuard::class),
            );

            $handler(new PublishSocialPublication((string) $publication->getId()));

            self::assertSame($on ? 1 : 0, $publisher->calls);
            $this->em()->clear();
            $reread = $this->em()->getRepository(SocialPublication::class)->find($publication->getId());
            // Éteint : la publication ne reste pas « en attente » pour toujours — elle est sautée, avec
            // son motif (relecture de la PR 2, 20/10). Allumé : publiée (témoin).
            self::assertSame($on ? SocialPublicationStatus::Published : SocialPublicationStatus::Skipped, $reread?->getStatus());
            if (!$on) {
                self::assertSame('module_disabled', $reread?->getErrorCode());
            }
        }
    }

    public function testMetricsAreNotCollectedWhenSocialIsOff(): void
    {
        foreach ([false, true] as $on) {
            $this->switchModule('social', $on, SocleFixtures::ETAB_A_NOM);
            $publication = $this->publication(SocialPublicationStatus::Published);
            $collector = new FakeMetricsCollector();
            $handler = new CollectSocialMetricsHandler(
                $this->em(),
                new SocialMetricsCollectorRegistry([$collector]),
                static::getContainer()->get(SocialTokenCipher::class),
                static::getContainer()->get(ModuleGuard::class),
            );

            $handler(new CollectSocialMetrics((string) $publication->getId()));

            self::assertSame($on ? 1 : 0, $collector->calls);
        }
    }

    private function publication(SocialPublicationStatus $status): SocialPublication
    {
        $em = $this->em();
        $establishment = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertNotNull($establishment);
        $account = $em->getRepository(SocialAccount::class)->findOneBy(['establishment' => $establishment]);
        self::assertNotNull($account);

        $post = (new SocialPost())->setEstablishment($establishment)->setBody('Aquagym samedi 10h.');
        $publication = (new SocialPublication())->setAccount($account)->setStatus($status);
        if ($status === SocialPublicationStatus::Published) {
            $publication->setRemotePostId('109999');
        }
        $post->addPublication($publication);
        $em->persist($post);
        $em->persist($publication);
        $em->flush();
        $em->clear();

        return $publication;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }
}
