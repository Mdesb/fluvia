<?php

declare(strict_types=1);

namespace App\Tests\Fonctionnalite\Unit;

use App\DataFixtures\SocleFixtures;
use App\Fonctionnalite\Entity\FonctionnaliteEtablissement;
use App\Fonctionnalite\Enum\Metier;
use App\Fonctionnalite\Service\Fonctionnalites;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Couvre directement le service `Fonctionnalites` (estActive/actives/definir/appliquerPreset), au
 * niveau domaine, indépendamment de la couche API (cf. `App\Tests\Fonctionnalite\Api` pour le contrat
 * HTTP).
 */
final class FonctionnalitesServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Fonctionnalites $fonctionnalites;
    private Etablissement $etablissement;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();
        $this->em = $em;

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        /** @var SocleFixtures $fixtures */
        $fixtures = $container->get(SocleFixtures::class);
        $fixtures->load($em);

        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etab);
        $this->etablissement = $etab;

        /** @var Fonctionnalites $service */
        $service = $container->get(Fonctionnalites::class);
        $this->fonctionnalites = $service;
    }

    public function testEstActiveEstFauxTantQuAucuneCapaciteNaEteDefinie(): void
    {
        self::assertFalse($this->fonctionnalites->estActive($this->etablissement, 'controle_acces'));
        self::assertSame([], $this->fonctionnalites->actives($this->etablissement));
    }

    public function testDefinirActiveUneCapaciteEtEstActiveLeReflete(): void
    {
        $this->fonctionnalites->definir($this->etablissement, 'controle_acces', true, ['mode' => 'strict']);

        self::assertTrue($this->fonctionnalites->estActive($this->etablissement, 'controle_acces'));
        self::assertSame(['controle_acces'], $this->fonctionnalites->actives($this->etablissement));

        // Un second appel « désactive » remet estActive à faux (idempotence de l'upsert).
        $this->fonctionnalites->definir($this->etablissement, 'controle_acces', false, null);
        self::assertFalse($this->fonctionnalites->estActive($this->etablissement, 'controle_acces'));
    }

    public function testDefinirLeveUneExceptionPourUnCodeInconnuDuCatalogue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->fonctionnalites->definir($this->etablissement, 'capacite_inexistante', true, null);
    }

    public function testAppliquerPresetActiveToutesLesCapacitesDeLaVerticale(): void
    {
        $codes = $this->fonctionnalites->appliquerPreset($this->etablissement, Metier::Piscine);

        self::assertNotEmpty($codes);
        foreach ($codes as $code) {
            self::assertTrue($this->fonctionnalites->estActive($this->etablissement, $code));
        }
        self::assertContains('poss', $this->fonctionnalites->actives($this->etablissement));
    }

    public function testEtatListeToutesLesCapacitesDuCatalogueMemeNonPersistees(): void
    {
        $this->fonctionnalites->definir($this->etablissement, 'controle_acces', true, null);

        $etat = $this->fonctionnalites->etat($this->etablissement);
        $codes = array_map(static fn (FonctionnaliteEtablissement $f): string => $f->getCapaciteCode(), $etat);

        self::assertGreaterThanOrEqual(12, \count($etat));
        self::assertContains('controle_acces', $codes);
        self::assertContains('boutique_en_ligne', $codes); // jamais définie, présente en transitoire inactive
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        parent::tearDown();
    }
}
