<?php

declare(strict_types=1);

namespace App\Tests\Social\Integration;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Social\Command\DispatchScheduledSocialPostsCommand;
use App\Social\Entity\SocialAccount;
use App\Social\Entity\SocialPost;
use App\Social\Entity\SocialPublication;
use App\Social\Message\PublishSocialPublication;
use App\Tests\Social\SocialApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Ordonnanceur des messages datés (SOC-3) — comble le manque assumé de SOC-2.
 *
 * L'heure de référence est passée en paramètre : c'est ce qui rend la commande vérifiable sans
 * assertion d'horloge dans la suite (D20).
 */
final class DispatchScheduledSocialPostsCommandTest extends SocialApiTestCase
{
    public function testUnMessageDateDontLHeureEstVenuePartEnFile(): void
    {
        $publication = $this->creerMessageDate('2026-08-24T09:00:00+00:00');

        $this->executer('2026-08-24T10:00:00+00:00');

        $envoyes = $this->transport()->getSent();
        self::assertCount(1, $envoyes);
        $message = $envoyes[0]->getMessage();
        self::assertInstanceOf(PublishSocialPublication::class, $message);
        self::assertSame((string) $publication->getId(), $message->publicationId);
        self::assertNotNull($this->relire($publication)->getQueuedAt());
    }

    public function testUnMessageDontLHeureNEstPasVenueNePartPas(): void
    {
        $this->creerMessageDate('2026-12-24T10:00:00+00:00');

        $this->executer('2026-08-24T10:00:00+00:00');

        self::assertCount(0, $this->transport()->getSent());
    }

    /**
     * La garde qui compte. Deux passages rapprochés — un cron qui déborde, une exécution manuelle
     * par-dessus la planifiée — publieraient sinon deux fois le même message sur le fil public d'un
     * client. Un doublon paru ne se rattrape pas.
     */
    public function testDeuxPassagesNeMettentPasLeMemeMessageDeuxFoisEnFile(): void
    {
        $this->creerMessageDate('2026-08-24T09:00:00+00:00');

        $this->executer('2026-08-24T10:00:00+00:00');
        $this->executer('2026-08-24T10:05:00+00:00');

        self::assertCount(1, $this->transport()->getSent(), 'Le second passage ne doit rien reprendre.');
    }

    private function executer(string $maintenant): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        /** @var MessageBusInterface $bus */
        $bus = static::getContainer()->get(MessageBusInterface::class);

        $commande = new DispatchScheduledSocialPostsCommand($em, $bus);
        $commande->run(new ArrayInput(['--now' => $maintenant]), new BufferedOutput());
    }

    private function creerMessageDate(string $quand): SocialPublication
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $etabA = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertNotNull($etabA);
        $compteA = $em->getRepository(SocialAccount::class)->findOneBy(['establishment' => $etabA]);
        self::assertNotNull($compteA);

        $post = new SocialPost();
        $post->setEstablishment($etabA)
            ->setBody('Aquagym de la semaine prochaine.')
            ->setScheduledFor(new \DateTimeImmutable($quand));
        $publication = new SocialPublication();
        $publication->setAccount($compteA);
        $post->addPublication($publication);
        $post->recomputeStatus();
        $em->persist($post);
        $em->persist($publication);
        $em->flush();
        $em->clear();

        return $publication;
    }

    private function transport(): InMemoryTransport
    {
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');

        return $transport;
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
