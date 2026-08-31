<?php

declare(strict_types=1);

namespace App\Tests\Dms\Api;

use App\DataFixtures\SocleFixtures;
use App\Dms\Entity\Document;
use App\Dms\Enum\DocumentCategory;
use App\Dms\Service\UploadDocumentHandler;
use App\Organisation\Entity\Etablissement;
use App\Tests\Dms\DmsApiTestCase;

/** `POST`/`PATCH`/`DELETE` non exposés (405/404) sur `DocumentVersion` — lecture seule stricte (RG-DMS-17). */
final class DocumentVersionApiTest extends DmsApiTestCase
{
    public function testGetEstAutorise(): void
    {
        $document = $this->createDocument();
        [$client, $entete] = $this->dmsUserOn(SocleFixtures::ETAB_A_NOM, ['read']);

        $client->request('GET', '/api/document_versions/' . $document->getCurrentVersion()->getId(), $entete);
        self::assertResponseIsSuccessful();
    }

    public function testPostNestPasExpose(): void
    {
        [$client, $entete] = $this->dmsUserOn(SocleFixtures::ETAB_A_NOM, ['read', 'write']);

        $client->request('POST', '/api/document_versions', $entete + [
            'headers' => $entete['headers'] + ['Content-Type' => 'application/ld+json'],
            'json' => ['versionNumber' => 99],
        ]);
        self::assertContains($client->getResponse()->getStatusCode(), [404, 405], 'Append-only : aucun Post exposé.');
    }

    public function testPatchNestPasExpose(): void
    {
        $document = $this->createDocument();
        [$client, $entete] = $this->dmsUserOn(SocleFixtures::ETAB_A_NOM, ['read', 'write']);

        $client->request('PATCH', '/api/document_versions/' . $document->getCurrentVersion()->getId(), $entete + [
            'headers' => $entete['headers'] + ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['mimeType' => 'text/plain'],
        ]);
        self::assertContains($client->getResponse()->getStatusCode(), [404, 405]);
    }

    public function testDeleteNestPasExpose(): void
    {
        $document = $this->createDocument();
        [$client, $entete] = $this->dmsUserOn(SocleFixtures::ETAB_A_NOM, ['read', 'delete']);

        $client->request('DELETE', '/api/document_versions/' . $document->getCurrentVersion()->getId(), $entete);
        self::assertContains($client->getResponse()->getStatusCode(), [404, 405]);
    }

    private function createDocument(): Document
    {
        /** @var UploadDocumentHandler $handler */
        $handler = static::getContainer()->get(UploadDocumentHandler::class);
        $etablissement = $this->entity(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);

        $source = fopen('php://temp', 'r+b');
        \assert($source !== false);
        fwrite($source, 'contenu');
        rewind($source);

        try {
            return $handler->upload($etablissement, DocumentCategory::Other, 'Titre', null, null, $source, 'f.pdf', 'application/pdf', null);
        } finally {
            fclose($source);
        }
    }
}
