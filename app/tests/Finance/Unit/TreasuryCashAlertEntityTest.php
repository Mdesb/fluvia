<?php

declare(strict_types=1);

namespace App\Tests\Finance\Unit;

use App\DataFixtures\SocleFixtures;
use App\Finance\Treasury\Entity\TreasuryCashAlert;
use App\Finance\Treasury\Enum\CashAlertStatus;
use App\Organisation\Entity\Etablissement;
use App\Tests\Finance\TreasuryApiTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * T2 du plan (§0.3) — la contrainte `UNIQUE` sur la colonne générée virtuelle `open_establishment_id`
 * refuse une seconde `TreasuryCashAlert` `open` pour le même établissement : anti-répétition garantie
 * en base, pas seulement par un `findOneBy()` applicatif dans `finance:treasury:verifier-seuils`.
 */
final class TreasuryCashAlertEntityTest extends TreasuryApiTestCase
{
    public function testUneSeuleAlerteOuvertePourUnEtablissement(): void
    {
        $em = $this->em();
        $etablissement = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etablissement);

        $premiere = $this->nouvelleAlerte($etablissement);
        $em->persist($premiere);
        $em->flush();

        $seconde = $this->nouvelleAlerte($etablissement);
        $em->persist($seconde);

        $this->expectException(UniqueConstraintViolationException::class);
        $em->flush();
    }

    /** Une alerte `resolved` ne compte pas dans l'unicité (les `NULL` de la colonne générée sont exclus de l'index). */
    public function testUneAlerteResolueNEmpecheJamaisUneNouvelleAlerteOuverte(): void
    {
        $em = $this->em();
        $etablissement = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etablissement);

        $resolue = $this->nouvelleAlerte($etablissement)->setStatus(CashAlertStatus::Resolved)->setResolvedAt(new \DateTimeImmutable());
        $em->persist($resolue);
        $em->flush();

        $ouverte = $this->nouvelleAlerte($etablissement);
        $em->persist($ouverte);
        $em->flush();

        self::assertNotNull($em->getRepository(TreasuryCashAlert::class)->find($ouverte->getId()));
    }

    private function nouvelleAlerte(Etablissement $etablissement): TreasuryCashAlert
    {
        return (new TreasuryCashAlert())
            ->setEstablishment($etablissement)
            ->setThresholdCentsAtDetection(0)
            ->setHorizonDaysAtDetection(30)
            ->setProjectedBreachDate(new \DateTimeImmutable('today +5 days'))
            ->setProjectedBalanceCents(-1000);
    }
}
