<?php

declare(strict_types=1);

namespace App\Tests\Import\Api;

use App\Crm\Entity\Client as CrmClient;
use App\DataFixtures\SocleFixtures;
use App\Import\Entity\ImportBatch;
use App\Tests\Import\ImportApiTestCase;

/**
 * D41 (plan-import-i1.md §0.7, SPEC-REPRISE-INITIALE.md §5) — l'établissement est estampillé au
 * serveur, une colonne du fichier ne désigne jamais où écrire.
 */
final class EstablishmentStampTest extends ImportApiTestCase
{
    public function testColonneEtablissementDuFichierIgnoreeEtablissementVientDuServeur(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();

        $csv = "externalRef;type;nom;establishment\nEXT-1;physique;Dupont;00000000-0000-4000-8000-000000000999\n";
        $import = $this->deposerImport($client, $entete, $csv);
        $client->request('POST', '/api/imports/' . $import['id'] . '/appliquer', $entete);

        $creeClient = $this->entite(CrmClient::class, ['nom' => 'Dupont']);
        self::assertSame($idA, (string) $creeClient->getEtablissementCreation()?->getId(), 'L\'établissement vient de la session, jamais du fichier.');
    }

    public function testAucunEtablissementActifRefuse422(): void
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);
        // Volontairement sans en-tête X-Etablissement.
        $entete = ['auth_bearer' => $token];

        $reponse = $client->request('POST', '/api/imports', $entete + [
            'json' => [
                'type' => 'customers',
                'fileName' => 'clients.csv',
                'mimeType' => 'text/csv',
                'content' => base64_encode($this->csvClientsValides(1)),
            ],
        ]);

        self::assertSame(422, $reponse->getStatusCode());
        self::assertSame(0, $this->em()->getRepository(ImportBatch::class)->count([]));
    }
}
