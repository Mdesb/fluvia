<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\DataFixtures\SocleFixtures;
use App\Finance\Treasury\Entity\TreasuryCashAlert;
use App\Finance\Treasury\Enum\CashAlertStatus;
use App\Organisation\Entity\Etablissement;
use App\Platform\Event\DomainEvent;
use App\Tests\Finance\TreasuryApiTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * T6 du plan (§0.6, §4.4 dernier point de la spec) — `PATCH cashAlertThresholdCents: null` résout
 * silencieusement toute `TreasuryCashAlert` `open` de l'établissement, sans événement.
 */
final class TreasurySettingsProcessorTest extends TreasuryApiTestCase
{
    public function testDesactivationDuSeuilResoutLesAlertesOuvertes(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $reponse = $client->request('POST', '/api/treasury_settings', $entete + [
            'json' => [
                'establishment' => '/api/etablissements/' . $this->idEtablissement(SocleFixtures::ETAB_A_NOM),
                'cashAlertThresholdCents' => -50000,
                'cashAlertHorizonDays' => 30,
            ],
        ])->toArray();

        $etablissement = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etablissement);

        $alerte = (new TreasuryCashAlert())
            ->setEstablishment($etablissement)
            ->setStatus(CashAlertStatus::Open)
            ->setThresholdCentsAtDetection(-50000)
            ->setHorizonDaysAtDetection(30)
            ->setProjectedBreachDate(new \DateTimeImmutable('today +5 days'))
            ->setProjectedBalanceCents(-100000);
        $this->em()->persist($alerte);
        $this->em()->flush();

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::getContainer()->get(EventDispatcherInterface::class);
        $captures = new \ArrayObject();
        $listener = static function (DomainEvent $event) use ($captures): void {
            $captures[] = $event;
        };
        $dispatcher->addListener('treasury.threshold_breached', $listener);

        try {
            $client->request('PATCH', '/api/treasury_settings/' . $reponse['id'], $this->entetePatch($entete) + [
                'json' => ['cashAlertThresholdCents' => null],
            ]);
        } finally {
            $dispatcher->removeListener('treasury.threshold_breached', $listener);
        }

        self::assertCount(0, $captures, 'La résolution à la désactivation du seuil est silencieuse — aucun événement.');

        $this->em()->clear();
        $alerteApres = $this->em()->getRepository(TreasuryCashAlert::class)->find($alerte->getId());
        self::assertInstanceOf(TreasuryCashAlert::class, $alerteApres);
        self::assertSame(CashAlertStatus::Resolved, $alerteApres->getStatus());
        self::assertNotNull($alerteApres->getResolvedAt());
    }

    public function testDesactivationSansAlerteOuverteEstIdempotente(): void
    {
        [$client, $entete] = $this->adminSurA();

        $reponse = $client->request('POST', '/api/treasury_settings', $entete + [
            'json' => [
                'establishment' => '/api/etablissements/' . $this->idEtablissement(SocleFixtures::ETAB_A_NOM),
                'cashAlertThresholdCents' => -50000,
            ],
        ])->toArray();

        $reponsePatch = $client->request('PATCH', '/api/treasury_settings/' . $reponse['id'], $this->entetePatch($entete) + [
            'json' => ['cashAlertThresholdCents' => null],
        ]);

        self::assertSame(200, $reponsePatch->getStatusCode());
        self::assertSame([], $this->em()->getRepository(TreasuryCashAlert::class)->findAll());
    }
}
