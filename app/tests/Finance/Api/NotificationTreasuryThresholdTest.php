<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Platform\Entity\Notification;
use App\Platform\Event\DomainEvent;
use App\Platform\Event\EventBus;
use App\Platform\Event\EventSubject;
use App\Platform\Event\EventTenant;
use App\Platform\Notification\NotificationRule;
use App\Securite\Service\CalculateurDroits;
use App\Tests\Finance\TreasuryApiTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * T7 du plan (RG-TRE-14, §0.8) — la Cloche de chaque titulaire de `finance.read` sur l'établissement de
 * la `TreasuryCashAlert` reçoit l'alerte, aucun autre.
 */
final class NotificationTreasuryThresholdTest extends TreasuryApiTestCase
{
    public function testDestinatairesLimitesAFinanceRead(): void
    {
        $etablissement = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etablissement);

        $idAlerte = (string) Uuid::v4();

        /** @var EventBus $bus */
        $bus = static::getContainer()->get(EventBus::class);
        $bus->publish(new DomainEvent(
            'treasury.threshold_breached',
            new EventTenant($etablissement->getId()),
            new EventSubject('TreasuryCashAlert', $idAlerte),
            [
                'threshold_cents' => -50000,
                'projected_breach_date' => '2026-09-15',
                'projected_balance_cents' => -100000,
                'horizon_days' => 30,
                'cause_source' => 'supplier_invoice',
                'cause_source_id' => (string) Uuid::v4(),
                'cause_amount_cents' => 600000,
            ],
        ));
        $this->em()->flush();
        $this->em()->clear();

        $notifications = array_values($this->em()->getRepository(Notification::class)->findBy(['source' => 'treasury.threshold_breached']));
        self::assertNotEmpty($notifications, 'Témoin absent : si personne n\'est prévenu, le test ne prouve rien.');

        $regle = NotificationRule::forEvent('treasury.threshold_breached');
        self::assertNotNull($regle);

        /** @var CalculateurDroits $droits */
        $droits = static::getContainer()->get(CalculateurDroits::class);

        foreach ($notifications as $notification) {
            $codes = $droits->codesEffectifs($notification->getDestinataire(), $etablissement->getId());
            self::assertTrue(
                $droits->autorise($codes, 'finance', 'read'),
                sprintf('Prévenu sans pouvoir agir : %s n\'a pas finance.read.', $notification->getDestinataire()->getEmail()),
            );
            self::assertSame((string) $etablissement->getId(), (string) $notification->getEtablissement()->getId());
            self::assertSame('finance', $notification->getEcran());
            self::assertSame(['alert' => $idAlerte], $notification->getParams());
            self::assertSame('Seuil de trésorerie bientôt franchi — 2026-09-15', $notification->getTitre());
        }
    }

    /** §3 de la spec — aucun compte affecté uniquement sur B n'est prévenu pour un événement de A. */
    public function testAucunCompteHorsPerimetreNestPrevenu(): void
    {
        $etablissementA = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etablissementA);

        /** @var EventBus $bus */
        $bus = static::getContainer()->get(EventBus::class);
        $bus->publish(new DomainEvent(
            'treasury.threshold_breached',
            new EventTenant($etablissementA->getId()),
            new EventSubject('TreasuryCashAlert', (string) Uuid::v4()),
            ['threshold_cents' => 0, 'projected_breach_date' => '2026-09-15', 'projected_balance_cents' => -1, 'horizon_days' => 30, 'cause_source' => null, 'cause_source_id' => null, 'cause_amount_cents' => null],
        ));
        $this->em()->flush();
        $this->em()->clear();

        $notifications = $this->em()->getRepository(Notification::class)->findBy(['source' => 'treasury.threshold_breached']);
        self::assertNotEmpty($notifications, 'Témoin absent.');

        foreach ($notifications as $notification) {
            self::assertSame((string) $etablissementA->getId(), (string) $notification->getEtablissement()->getId());
        }
    }
}
