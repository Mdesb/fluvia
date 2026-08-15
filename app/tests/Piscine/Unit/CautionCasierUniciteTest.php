<?php

declare(strict_types=1);

namespace App\Tests\Piscine\Unit;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Piscine\Entity\Casier;
use App\Piscine\Entity\CautionCasier;
use App\Piscine\Enum\EtatCasier;
use App\Piscine\Enum\StatutCaution;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Intégrité « un seul CautionCasier actif par casier » (CA-9) : la colonne dénormalisée
 * `casierActif` (= id du casier tant que le statut n'est pas `liberee`) est protégée par un index
 * unique partiel — même technique que `Appairage.supportActif` (L3). Une deuxième caution active
 * concurrente sur le même casier est rejetée au niveau base, indépendamment de la garde applicative
 * (`AttribuerCasierHandler`).
 */
final class CautionCasierUniciteTest extends KernelTestCase
{
    public function testDeuxCautionsActivesSurLeMemeCasierSontRefusees(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        // FK_CHECKS désactivé le temps du drop/create (nombreuses tables inter-référencées) : évite les
        // échecs d'ordonnancement DROP/CREATE observés après l'introduction du schéma recouvrement_*.
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        /** @var SocleFixtures $socle */
        $socle = $container->get(SocleFixtures::class);
        $socle->load($em);

        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        $casier = (new Casier())->setNumero(1)->setZone('Test unicité')->setEtat(EtatCasier::Occupe)->setEtablissement($etab);
        $em->persist($casier);

        $premiere = (new CautionCasier())->setCasier($casier)->setMontant('10.00')->setStatut(StatutCaution::Encaissee);
        $em->persist($premiere);
        $em->flush();

        $seconde = (new CautionCasier())->setCasier($casier)->setMontant('10.00')->setStatut(StatutCaution::Encaissee);
        $em->persist($seconde);

        $this->expectException(UniqueConstraintViolationException::class);
        $em->flush();
    }
}
