<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit;

use App\Import\Service\CsvImportParser;
use PHPUnit\Framework\TestCase;

/**
 * `CsvImportParser` (plan-import-i1.md §0.4) : restitution par nom de colonne (clés normalisées),
 * numérotation des lignes en base 1 (en-tête = ligne 1).
 */
final class ImportFileParserTest extends TestCase
{
    public function testCsvImportParserRestitueColonnesParNomEtNumeroDeLigne(): void
    {
        $parser = new CsvImportParser();
        $csv = "externalRef;Type;Nom;Prénom\nEXT-1;physique;Dupont;Jean\nEXT-2;physique;Martin;Paul\n";

        $resultat = $parser->parse($csv);

        self::assertCount(2, $resultat->rows);
        self::assertSame([], $resultat->globalErrors);

        $premiere = $resultat->rows[0];
        // En-tête = ligne 1 (§0.4) -> la première ligne de données est la ligne 2.
        self::assertSame(2, $premiere->lineNumber);
        self::assertSame('EXT-1', $premiere->columns['externalref']);
        self::assertSame('physique', $premiere->columns['type']);
        self::assertSame('Dupont', $premiere->columns['nom']);

        $seconde = $resultat->rows[1];
        self::assertSame(3, $seconde->lineNumber);
        self::assertSame('EXT-2', $seconde->columns['externalref']);
    }

    public function testColonneEtablissementRestitueeCommeUneColonneOrdinaire(): void
    {
        // Le parseur ne connaît pas la sémantique des colonnes (§0.7, D41) : une colonne
        // « establishment » n'est ni censurée ni source d'erreur, c'est au RowImporter de l'ignorer.
        $parser = new CsvImportParser();
        $csv = "externalRef;type;nom;establishment\nEXT-1;physique;Dupont;etablissement-pirate\n";

        $resultat = $parser->parse($csv);

        self::assertSame('etablissement-pirate', $resultat->rows[0]->columns['establishment']);
    }

    public function testLignesVidesIgnoreesSansDecalerLaNumerotation(): void
    {
        $parser = new CsvImportParser();
        $csv = "externalRef;type;nom\nEXT-1;physique;Dupont\n\nEXT-2;physique;Martin\n";

        $resultat = $parser->parse($csv);

        self::assertCount(2, $resultat->rows);
        self::assertSame(2, $resultat->rows[0]->lineNumber);
        // Ligne 3 est vide (ignorée) -> la ligne de données suivante est bien la ligne 4 du fichier.
        self::assertSame(4, $resultat->rows[1]->lineNumber);
    }

    public function testFichierVideRendUneErreurGlobale(): void
    {
        $parser = new CsvImportParser();

        $resultat = $parser->parse('');

        self::assertSame([], $resultat->rows);
        self::assertNotEmpty($resultat->globalErrors);
    }

    public function testSupportsRefuseUnFormatNonCsv(): void
    {
        $parser = new CsvImportParser();

        self::assertTrue($parser->supports('text/csv', 'clients.csv'));
        self::assertTrue($parser->supports('', ''));
        self::assertFalse($parser->supports('application/vnd.ms-excel', 'clients.xlsx'));
    }
}
