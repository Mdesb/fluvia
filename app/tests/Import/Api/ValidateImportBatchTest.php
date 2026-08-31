<?php

declare(strict_types=1);

namespace App\Tests\Import\Api;

use App\Tests\Import\ImportApiTestCase;

/**
 * `POST /imports` (plan-import-i1.md §0.2/§0.9, SPEC-REPRISE-INITIALE.md §1/§2) — D98 : refuse tout,
 * en nommant les lignes ; deux temps stricts, aucune écriture en base métier tant que `/appliquer`
 * n'a pas été appelé.
 */
final class ValidateImportBatchTest extends ImportApiTestCase
{
    public function testFichierAvecUneLigneMauvaiseRejetteToutEtNommeLesLignes(): void
    {
        [$client, $entete] = $this->adminSurA();

        $lignes = ['externalRef;type;nom;prenom;email'];
        for ($i = 1; $i <= 10; ++$i) {
            // Ligne 4 (donnée) invalide : nom manquant sur un client physique.
            $nom = $i === 4 ? '' : ('Nom' . $i);
            $lignes[] = sprintf('EXT-%d;physique;%s;Prenom%d;client%d@test.fr', $i, $nom, $i, $i);
        }
        $csv = implode("\n", $lignes) . "\n";

        $reponse = $this->deposerImport($client, $entete, $csv);

        self::assertSame('rejected', $reponse['status']);
        self::assertNotEmpty($reponse['errors']);
        // En-tête = ligne 1 -> la 4e ligne de données est la ligne 5 du fichier.
        self::assertArrayHasKey('5', $reponse['errors']);
        self::assertSame(0, $this->compterClients(), 'Aucun Client créé, y compris les 9 lignes valides (D98).');
    }

    public function testValidationNecriteRienEnBaseMetier(): void
    {
        [$client, $entete] = $this->adminSurA();
        $csv = $this->csvClientsValides(5);

        $reponse = $this->deposerImport($client, $entete, $csv);

        self::assertSame('validated', $reponse['status']);
        self::assertSame(5, $reponse['rowCount']);
        self::assertSame(0, $this->compterClients(), 'Deux temps stricts (D98) : rien avant /appliquer.');
    }

}
