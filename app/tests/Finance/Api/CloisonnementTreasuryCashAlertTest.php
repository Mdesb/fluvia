<?php

declare(strict_types=1);

namespace App\Tests\Finance\Api;

use App\DataFixtures\SocleFixtures;
use App\Finance\Treasury\Entity\TreasuryCashAlert;
use App\Organisation\Entity\Etablissement;
use App\Tests\Finance\TreasuryApiTestCase;

/**
 * T2 du plan (§3) — `TreasuryCashAlert` rejoint `PerimetreFinanceExtension` (5ᵉ ressource) : une alerte
 * d'un autre établissement n'est jamais visible en collection ni en lecture item (D8).
 */
final class CloisonnementTreasuryCashAlertTest extends TreasuryApiTestCase
{
    public function testAlerteDunAutreEtablissementInvisible(): void
    {
        $etablissementB = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertInstanceOf(Etablissement::class, $etablissementB);

        $alerteB = (new TreasuryCashAlert())
            ->setEstablishment($etablissementB)
            ->setThresholdCentsAtDetection(0)
            ->setHorizonDaysAtDetection(30)
            ->setProjectedBreachDate(new \DateTimeImmutable('today +5 days'))
            ->setProjectedBalanceCents(-1000);
        $this->em()->persist($alerteB);
        $this->em()->flush();

        [$client, $entete] = $this->operateurFinanceSur(SocleFixtures::ETAB_A_NOM, ['read']);

        $collection = $client->request('GET', '/api/treasury_cash_alerts', $entete)->toArray();
        $idsVisibles = array_map(
            static fn (array $membre): string => basename((string) $membre['@id']),
            $collection['member'] ?? [],
        );
        self::assertNotContains((string) $alerteB->getId(), $idsVisibles, 'L\'alerte de B ne doit pas fuiter en collection pour un opérateur limité à A.');

        $reponseItem = $client->request('GET', '/api/treasury_cash_alerts/' . $alerteB->getId(), $entete);
        self::assertContains($reponseItem->getStatusCode(), [403, 404], 'Fuite cross-tenant en lecture item (D8).');
    }

    public function testAlerteDeSonPropreEtablissementVisible(): void
    {
        $etablissementA = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etablissementA);

        $alerteA = (new TreasuryCashAlert())
            ->setEstablishment($etablissementA)
            ->setThresholdCentsAtDetection(0)
            ->setHorizonDaysAtDetection(30)
            ->setProjectedBreachDate(new \DateTimeImmutable('today +5 days'))
            ->setProjectedBalanceCents(-1000);
        $this->em()->persist($alerteA);
        $this->em()->flush();

        [$client, $entete] = $this->operateurFinanceSur(SocleFixtures::ETAB_A_NOM, ['read']);

        $reponse = $client->request('GET', '/api/treasury_cash_alerts/' . $alerteA->getId(), $entete);
        self::assertSame(200, $reponse->getStatusCode());
    }
}
