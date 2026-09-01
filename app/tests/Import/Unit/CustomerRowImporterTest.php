<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit;

use App\DataFixtures\SocleFixtures;
use App\Import\Dto\ParsedImportRow;
use App\Import\Service\CustomerRowImporter;
use App\Organisation\Entity\Etablissement;
use App\Tests\SchemaDuHarnais;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Validation métier propre à `customers` (plan-import-i1.md §0.5) — `validate()` est **pure**, testée
 * sans passer par l'API.
 */
final class CustomerRowImporterTest extends KernelTestCase
{
    private CustomerRowImporter $importer;
    private Etablissement $etablissement;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();
        SchemaDuHarnais::reinitialiser($em);
        $container->get(SocleFixtures::class)->load($em);

        /** @var CustomerRowImporter $importer */
        $importer = $container->get(CustomerRowImporter::class);
        $this->importer = $importer;

        $etablissement = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etablissement);
        $this->etablissement = $etablissement;
    }

    public function testExternalRefManquantRefuseLaLigne(): void
    {
        $rows = [new ParsedImportRow(2, ['externalref' => '', 'type' => 'physique', 'nom' => 'Dupont'])];

        $errors = $this->importer->validate($rows, $this->etablissement);

        self::assertArrayHasKey(2, $errors);
        self::assertStringContainsString('externalRef', $errors[2]);
    }

    public function testExternalRefDupliqueDansLeMemeFichierRefuseLesDeuxLignes(): void
    {
        $rows = [
            new ParsedImportRow(2, ['externalref' => 'EXT-1', 'type' => 'physique', 'nom' => 'Dupont']),
            new ParsedImportRow(3, ['externalref' => 'EXT-1', 'type' => 'physique', 'nom' => 'Martin']),
        ];

        $errors = $this->importer->validate($rows, $this->etablissement);

        self::assertArrayHasKey(2, $errors, 'La première ligne du doublon doit être en erreur.');
        self::assertArrayHasKey(3, $errors, 'La seconde ligne du doublon doit être en erreur (aucune sélection arbitraire).');
        self::assertStringContainsString('dupliqué', $errors[2]);
    }

    public function testPhysiqueSansNomRefuse(): void
    {
        $rows = [new ParsedImportRow(2, ['externalref' => 'EXT-1', 'type' => 'physique', 'nom' => ''])];

        $errors = $this->importer->validate($rows, $this->etablissement);

        self::assertArrayHasKey(2, $errors);
        self::assertStringContainsString('nom', $errors[2]);
    }

    public function testMoraleSansRaisonSocialeRefuse(): void
    {
        $rows = [new ParsedImportRow(2, ['externalref' => 'EXT-1', 'type' => 'morale', 'raisonsociale' => ''])];

        $errors = $this->importer->validate($rows, $this->etablissement);

        self::assertArrayHasKey(2, $errors);
        self::assertStringContainsString('raisonSociale', $errors[2]);
    }

    public function testLigneCompleteSansErreur(): void
    {
        $rows = [
            new ParsedImportRow(2, ['externalref' => 'EXT-1', 'type' => 'physique', 'nom' => 'Dupont']),
            new ParsedImportRow(3, ['externalref' => 'EXT-2', 'type' => 'morale', 'raisonsociale' => 'ACME SARL']),
        ];

        $errors = $this->importer->validate($rows, $this->etablissement);

        self::assertSame([], $errors);
    }
}
