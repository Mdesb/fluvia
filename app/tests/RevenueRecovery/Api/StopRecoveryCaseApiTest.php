<?php

declare(strict_types=1);

namespace App\Tests\RevenueRecovery\Api;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\RevenueRecovery\Entity\RecoveryAttempt;
use App\RevenueRecovery\Entity\RecoveryCase;
use App\RevenueRecovery\Entity\RecoverySequence;
use App\RevenueRecovery\Enum\RecoveryAttemptStatus;
use App\RevenueRecovery\Enum\RecoveryCaseStatus;
use App\RevenueRecovery\Enum\RecoveryChannel;
use App\RevenueRecovery\Enum\RecoveryTriggerType;
use App\Tests\RevenueRecovery\RevenueRecoveryApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/** `POST /revenue-recovery/cases/{id}/stop` (US-RR-05, RG-RR-05, plan-revenue-recovery.md §2/T6). */
final class StopRecoveryCaseApiTest extends RevenueRecoveryApiTestCase
{
    /** CA-2 US-RR-05 : motif vide refusé. */
    public function testArretManuelSansMotifRefuse422(): void
    {
        [$case, $entete, $client] = $this->prepareCaseEtAgent();

        $client->request('POST', '/api/revenue-recovery/cases/' . $case->getId() . '/stop', $entete + [
            'json' => ['reason' => ''],
        ]);
        self::assertResponseStatusCodeSame(422, (string) $client->getResponse()->getContent(false));
    }

    /** CA-1 US-RR-05 : arrêt avec motif tracé, aucune tentative future envoyée (Pending -> Cancelled). */
    public function testArretManuelAvecMotifTraceAucuneTentativeFutureEnvoyee(): void
    {
        [$case, $entete, $client] = $this->prepareCaseEtAgent();

        $client->request('POST', '/api/revenue-recovery/cases/' . $case->getId() . '/stop', $entete + [
            'json' => ['reason' => 'Client injoignable, demande explicite d\'arrêt'],
        ]);
        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent(false));
        $reponse = $client->getResponse()->toArray();
        self::assertSame('stopped', $reponse['status']);
        self::assertSame('Client injoignable, demande explicite d\'arrêt', $reponse['stopReason']);
        self::assertNotNull($reponse['stoppedAt']);

        /** @var EntityManagerInterface $em */
        $em = $this->em();
        $em->clear();
        $attempts = $em->getRepository(RecoveryAttempt::class)->findBy(['recoveryCase' => $case->getId()]);
        foreach ($attempts as $attempt) {
            self::assertSame(RecoveryAttemptStatus::Cancelled, $attempt->getStatus(), 'Toute tentative Pending doit être annulée à l\'arrêt manuel.');
        }
    }

    /** @return array{0: RecoveryCase, 1: array<string, mixed>, 2: \ApiPlatform\Symfony\Bundle\Test\Client} */
    private function prepareCaseEtAgent(): array
    {
        [$client, $entete] = $this->userWithPermissions(SocleFixtures::ETAB_A_NOM, ['read', 'manage'], 'rr-manage-' . uniqid('', true));

        $em = $this->em();
        $etablissement = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etablissement);

        $sequence = (new RecoverySequence())
            ->setEstablishment($etablissement)
            ->setTriggerType(RecoveryTriggerType::BookingNoShow)
            ->setActive(true)
            ->setMaxAttempts(3)
            ->setSteps([['delayDays' => 1, 'channel' => 'email', 'templateCode' => 'no_show_j1']]);
        $em->persist($sequence);

        $case = (new RecoveryCase())
            ->setEstablishment($etablissement)
            ->setTriggerType(RecoveryTriggerType::BookingNoShow)
            ->setSubjectType('Reservation')
            ->setSubjectRef((string) Uuid::v4())
            ->setStatus(RecoveryCaseStatus::Active)
            ->setSequence($sequence)
            ->setOpenedAt(new \DateTimeImmutable());
        $em->persist($case);

        $attempt = (new RecoveryAttempt())
            ->setRecoveryCase($case)
            ->setStepIndex(0)
            ->setScheduledAt(new \DateTimeImmutable('+1 day'))
            ->setChannel(RecoveryChannel::Email)
            ->setStatus(RecoveryAttemptStatus::Pending);
        $em->persist($attempt);

        $em->flush();

        return [$case, $entete, $client];
    }
}
