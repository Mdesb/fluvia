<?php

declare(strict_types=1);

namespace App\Tests\Dms\Api;

use App\DataFixtures\SocleFixtures;
use App\Dms\Entity\Document;
use App\Dms\Enum\DocumentCategory;
use App\Dms\Service\UploadDocumentHandler;
use App\Organisation\Entity\Etablissement;
use App\Tests\Dms\DmsApiTestCase;

/**
 * CA-1 (RG-DMS-01) : un utilisateur de l'établissement A ne voit jamais un document de B, même avec
 * l'UUID exact. CA-2 (RG-DMS-02/03, D8) : `download` sur un id hors périmètre -> 404 identique à un id
 * inexistant.
 */
final class CloisonnementDmsTest extends DmsApiTestCase
{
    public function testUtilisateurEtablissementANeVoitPasLeDocumentDeB(): void
    {
        $documentB = $this->createDocument(SocleFixtures::ETAB_B_NOM, 'Document confidentiel B');

        [$client, $entete] = $this->dmsUserOn(SocleFixtures::ETAB_A_NOM, ['read']);

        $client->request('GET', '/api/documents/' . $documentB->getId(), $entete);
        self::assertResponseStatusCodeSame(404, (string) $client->getResponse()->getContent(false));

        $client->request('GET', '/api/documents', $entete);
        self::assertResponseIsSuccessful();
        $corps = $client->getResponse()->getContent();
        self::assertStringNotContainsString((string) $documentB->getId(), $corps);
    }

    public function testDownloadSurIdHorsPerimetreDonne404IdentiqueAUnIdInexistant(): void
    {
        $documentB = $this->createDocument(SocleFixtures::ETAB_B_NOM, 'Document confidentiel B');
        [$client, $entete] = $this->dmsUserOn(SocleFixtures::ETAB_A_NOM, ['read']);

        $client->request('GET', '/dms/documents/' . $documentB->getId() . '/download', $entete);
        $statutHorsPerimetre = $client->getResponse()->getStatusCode();
        self::assertSame(404, $statutHorsPerimetre);

        $idInexistant = '00000000-0000-4000-8000-000000000000';
        $client->request('GET', '/dms/documents/' . $idInexistant . '/download', $entete);
        self::assertSame(404, $client->getResponse()->getStatusCode(), 'Même statut 404 qu\'un id hors périmètre — indiscernable (RG-DMS-03).');
    }

    public function testDownloadSansPermissionDmsReadDonne403(): void
    {
        $document = $this->createDocument(SocleFixtures::ETAB_A_NOM, 'Document A');
        [$client, $entete] = $this->dmsUserOn(SocleFixtures::ETAB_A_NOM, ['write']); // pas de dms.read

        $client->request('GET', '/dms/documents/' . $document->getId() . '/download', $entete);
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    private function createDocument(string $nomEtablissement, string $titre): Document
    {
        /** @var UploadDocumentHandler $handler */
        $handler = static::getContainer()->get(UploadDocumentHandler::class);
        $etablissement = $this->entity(Etablissement::class, ['nom' => $nomEtablissement]);

        $source = fopen('php://temp', 'r+b');
        \assert($source !== false);
        fwrite($source, 'contenu de ' . $titre);
        rewind($source);

        try {
            return $handler->upload($etablissement, DocumentCategory::Other, $titre, null, null, $source, 'f.pdf', 'application/pdf', null);
        } finally {
            fclose($source);
        }
    }
}
