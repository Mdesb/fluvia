<?php

declare(strict_types=1);

namespace App\Tests\RevenueRecovery\Api;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\RevenueRecovery\Entity\RecoveryCase;
use App\RevenueRecovery\Entity\RecoverySequence;
use App\RevenueRecovery\Enum\RecoveryTriggerType;
use App\Tests\Platform\ModuleSwitch;
use App\Tests\RevenueRecovery\RevenueRecoveryApiTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Recentrage, PR 2 (d) — l'écouteur de Revenue Recovery n'ouvre plus de dossier de relance pour un
 * établissement où le module est éteint.
 *
 * Une séquence active « paiement échoué » existe sur A ; `payment.failed` est publié par le vrai bus.
 * Éteint : aucun dossier. Allumé : un dossier (témoin).
 */
final class SwitchedOffRevenueRecoveryListenerTest extends RevenueRecoveryApiTestCase
{
    use ModuleSwitch;

    public function testPaymentFailedOpensNoCaseWhenRevenueRecoveryIsOff(): void
    {
        $establishment = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $establishment);
        $sequence = (new RecoverySequence())->setEstablishment($establishment)->setTriggerType(RecoveryTriggerType::PaymentFailed)
            ->setActive(true)->setSteps([['delayDays' => 1, 'channel' => 'email', 'templateCode' => 'paiement_j1']]);
        $this->em()->persist($sequence);
        $this->em()->flush();

        foreach ([false, true] as $on) {
            $this->switchModule('revenue_recovery', $on, SocleFixtures::ETAB_A_NOM);
            $before = $this->em()->getRepository(RecoveryCase::class)->count([]);

            /** @var EventBus $bus */
            $bus = static::getContainer()->get(EventBus::class);
            $bus->publish(new DomainEvent(
                'payment.failed',
                new EventTenant($establishment->getId()),
                new EventSubject('Paiement', (string) Uuid::v4()),
                ['amount_cents' => 4200],
            ));

            $after = $this->em()->getRepository(RecoveryCase::class)->count([]);
            self::assertSame($on ? $before + 1 : $before, $after, $on ? 'témoin : allumé, un dossier' : 'éteint, aucun dossier');
        }
    }
}
