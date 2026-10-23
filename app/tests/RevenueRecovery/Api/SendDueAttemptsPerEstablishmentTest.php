<?php

declare(strict_types=1);

namespace App\Tests\RevenueRecovery\Api;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\RevenueRecovery\Entity\RecoveryAttempt;
use App\RevenueRecovery\Entity\RecoverySequence;
use App\RevenueRecovery\Enum\RecoveryAttemptStatus;
use App\RevenueRecovery\Enum\RecoveryTriggerType;
use App\RevenueRecovery\Service\RecoveryEngine;
use App\Tests\Platform\ModuleSwitch;
use App\Tests\RevenueRecovery\RevenueRecoveryApiTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Recentrage, relecture de la PR 2 (point 5) — `revenue-recovery:attempts:send` ne traite que les
 * établissements où Revenue Recovery est allumé.
 *
 * Une tentative due sur A, une sur B, ouvertes module allumé partout. Puis allumé sur A, éteint sur
 * B : un seul passage traite la tentative de A et laisse celle de B en attente.
 */
final class SendDueAttemptsPerEstablishmentTest extends RevenueRecoveryApiTestCase
{
    use ModuleSwitch;

    public function testOnlyTheEstablishmentsWhereTheModuleIsOnAreProcessed(): void
    {
        /** @var RecoveryEngine $engine */
        $engine = static::getContainer()->get(RecoveryEngine::class);
        $attempts = [];
        foreach ([SocleFixtures::ETAB_A_NOM, SocleFixtures::ETAB_B_NOM] as $name) {
            $establishment = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => $name]);
            self::assertInstanceOf(Etablissement::class, $establishment);
            $this->em()->persist((new RecoverySequence())->setEstablishment($establishment)->setTriggerType(RecoveryTriggerType::PaymentFailed)
                ->setActive(true)->setSteps([['delayDays' => 0, 'channel' => 'email', 'templateCode' => 'paiement_j0']]));
            $this->em()->flush();

            $case = $engine->handle(new DomainEvent(
                'payment.failed',
                new EventTenant($establishment->getId()),
                new EventSubject('Paiement', (string) Uuid::v4()),
                ['amount_cents' => 4200],
            ), RecoveryTriggerType::PaymentFailed);
            self::assertNotNull($case, 'montage : un dossier par établissement');
            $attempts[$name] = $this->em()->getRepository(RecoveryAttempt::class)->findOneBy(['recoveryCase' => $case->getId()]);
            self::assertInstanceOf(RecoveryAttempt::class, $attempts[$name]);
        }

        $this->switchModule('revenue_recovery', true, SocleFixtures::ETAB_A_NOM);
        $this->switchModule('revenue_recovery', false, SocleFixtures::ETAB_B_NOM);
        $engine->sendDueAttempts(new \DateTimeImmutable('+1 minute'));

        $this->em()->refresh($attempts[SocleFixtures::ETAB_A_NOM]);
        $this->em()->refresh($attempts[SocleFixtures::ETAB_B_NOM]);
        self::assertNotSame(RecoveryAttemptStatus::Pending, $attempts[SocleFixtures::ETAB_A_NOM]->getStatus(), 'allumé sur A : traitée');
        self::assertSame(RecoveryAttemptStatus::Pending, $attempts[SocleFixtures::ETAB_B_NOM]->getStatus(), 'éteint sur B : laissée en attente');
    }

    /**
     * Pour un établissement éteint, rien n'est écrit — pas même l'annulation d'une tentative dont le
     * dossier a été clos. Allumé (témoin), la même tentative est annulée.
     */
    public function testNothingIsWrittenForASwitchedOffEstablishmentEvenForAClosedCase(): void
    {
        /** @var RecoveryEngine $engine */
        $engine = static::getContainer()->get(RecoveryEngine::class);
        $establishment = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertInstanceOf(Etablissement::class, $establishment);
        $this->em()->persist((new RecoverySequence())->setEstablishment($establishment)->setTriggerType(RecoveryTriggerType::PaymentFailed)
            ->setActive(true)->setSteps([['delayDays' => 0, 'channel' => 'email', 'templateCode' => 'paiement_j0']]));
        $this->em()->flush();
        $case = $engine->handle(new DomainEvent(
            'payment.failed',
            new EventTenant($establishment->getId()),
            new EventSubject('Paiement', (string) Uuid::v4()),
            ['amount_cents' => 4200],
        ), RecoveryTriggerType::PaymentFailed);
        self::assertNotNull($case);
        $attempt = $this->em()->getRepository(RecoveryAttempt::class)->findOneBy(['recoveryCase' => $case->getId()]);
        self::assertInstanceOf(RecoveryAttempt::class, $attempt);
        $case->setStatus(\App\RevenueRecovery\Enum\RecoveryCaseStatus::Stopped);
        $this->em()->flush();

        $this->switchModule('revenue_recovery', false, SocleFixtures::ETAB_B_NOM);
        $engine->sendDueAttempts(new \DateTimeImmutable('+1 minute'));
        $this->em()->refresh($attempt);
        self::assertSame(RecoveryAttemptStatus::Pending, $attempt->getStatus(), 'éteint : la tentative n\'est pas touchée');

        $this->switchModule('revenue_recovery', true, SocleFixtures::ETAB_B_NOM);
        $engine->sendDueAttempts(new \DateTimeImmutable('+1 minute'));
        $this->em()->refresh($attempt);
        self::assertSame(RecoveryAttemptStatus::Cancelled, $attempt->getStatus(), 'témoin : allumé, la tentative du dossier clos est annulée');
    }
}
