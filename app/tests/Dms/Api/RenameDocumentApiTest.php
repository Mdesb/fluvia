<?php

declare(strict_types=1);

namespace App\Tests\Dms\Api;

use App\DataFixtures\SocleFixtures;
use App\Dms\Entity\Document;
use App\Dms\Enum\DocumentCategory;
use App\Dms\Service\UploadDocumentHandler;
use App\Organisation\Entity\Etablissement;
use App\Tests\Dms\DmsApiTestCase;

/** Changer `category` ne recalcule jamais implicitement `retainUntil`/`retentionPolicy` (RG-DMS-11). */
final class RenameDocumentApiTest extends DmsApiTestCase
{
    public function testChangerCategorieNeRecalculeJamaisLaRetention(): void
    {
        // MarketingAsset -> aucune politique par défaut (retentionStatus = none à la création).
        $document = $this->createDocument(DocumentCategory::MarketingAsset);
        self::assertNull($document->getRetentionPolicy());

        [$client, $entete] = $this->dmsUserOn(SocleFixtures::ETAB_A_NOM, ['read', 'write']);

        // On bascule vers accounting_piece, qui porte pourtant une politique par défaut.
        $client->request('PATCH', '/api/documents/' . $document->getId(), [
            'headers' => $entete['headers'] + ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['category' => 'accounting_piece', 'title' => 'Titre renommé'],
        ] + $entete);
        self::assertResponseIsSuccessful();
        $corps = $client->getResponse()->toArray();
        self::assertSame('accounting_piece', $corps['category']);
        self::assertSame('Titre renommé', $corps['title']);
        self::assertNull($corps['retentionPolicy'], 'RG-DMS-11 : renommer/recatégoriser ne recalcule jamais implicitement la rétention.');
        self::assertSame('none', $corps['retentionStatus']);
    }

    public function testRenommerSurDocumentHorsPerimetreDonne404(): void
    {
        $documentB = $this->createDocument(DocumentCategory::Other, SocleFixtures::ETAB_B_NOM);
        [$client, $entete] = $this->dmsUserOn(SocleFixtures::ETAB_A_NOM, ['read', 'write']);

        $client->request('PATCH', '/api/documents/' . $documentB->getId(), [
            'headers' => $entete['headers'] + ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['title' => 'Tentative cross-tenant'],
        ] + $entete);
        self::assertResponseStatusCodeSame(404);
    }

    private function createDocument(DocumentCategory $category, string $nomEtablissement = SocleFixtures::ETAB_A_NOM): Document
    {
        /** @var UploadDocumentHandler $handler */
        $handler = static::getContainer()->get(UploadDocumentHandler::class);
        $etablissement = $this->entity(Etablissement::class, ['nom' => $nomEtablissement]);

        $source = fopen('php://temp', 'r+b');
        \assert($source !== false);
        fwrite($source, 'contenu');
        rewind($source);

        try {
            return $handler->upload($etablissement, $category, 'Titre', null, null, $source, 'f.pdf', 'application/pdf', null);
        } finally {
            fclose($source);
        }
    }
}
