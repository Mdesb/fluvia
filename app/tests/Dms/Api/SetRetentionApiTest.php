<?php

declare(strict_types=1);

namespace App\Tests\Dms\Api;

use App\DataFixtures\SocleFixtures;
use App\Dms\Entity\Document;
use App\Dms\Enum\DocumentCategory;
use App\Dms\Service\UploadDocumentHandler;
use App\Organisation\Entity\Etablissement;
use App\Tests\Dms\DmsApiTestCase;

/** `dms.manage_retention` requis (403 sinon) ; `document.retention_set` publié avec les bonnes valeurs. */
final class SetRetentionApiTest extends DmsApiTestCase
{
    public function testAttacherPolitiqueDeRetentionPublieEvenement(): void
    {
        $document = $this->createDocument();
        [$client, $entete] = $this->dmsUserOn(SocleFixtures::ETAB_A_NOM, ['read', 'manage_retention']);
        $client->disableReboot();

        $captures = $this->captureEvents(['document.retention_set']);

        $client->request('POST', '/api/documents/' . $document->getId() . '/retention', $entete + [
            'json' => ['retentionPolicyCode' => 'fr_hr_5y', 'retainUntilOverride' => null],
        ]);
        self::assertResponseIsSuccessful();
        $corps = $client->getResponse()->toArray();
        self::assertSame('active', $corps['retentionStatus']);

        self::assertCount(1, $captures);
        self::assertSame('fr_hr_5y', $captures[0]->payload['retentionPolicyCode']);
        self::assertNotNull($captures[0]->payload['retainUntil']);
    }

    public function testLeverLaPolitiquePublieValeursNulles(): void
    {
        $document = $this->createAccountingPieceDocument();
        [$client, $entete] = $this->dmsUserOn(SocleFixtures::ETAB_A_NOM, ['read', 'manage_retention']);
        $client->disableReboot();

        $captures = $this->captureEvents(['document.retention_set']);

        $client->request('POST', '/api/documents/' . $document->getId() . '/retention', $entete + [
            'json' => ['retentionPolicyCode' => null, 'retainUntilOverride' => null],
        ]);
        self::assertResponseIsSuccessful();
        $corps = $client->getResponse()->toArray();
        self::assertSame('none', $corps['retentionStatus']);

        self::assertCount(1, $captures);
        self::assertNull($captures[0]->payload['retentionPolicyCode']);
        self::assertNull($captures[0]->payload['retainUntil']);
    }

    public function testSansPermissionManageRetentionDonne403(): void
    {
        $document = $this->createDocument();
        [$client, $entete] = $this->dmsUserOn(SocleFixtures::ETAB_A_NOM, ['read', 'write']);

        $client->request('POST', '/api/documents/' . $document->getId() . '/retention', $entete + [
            'json' => ['retentionPolicyCode' => 'fr_hr_5y', 'retainUntilOverride' => null],
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    private function createDocument(): Document
    {
        return $this->create(DocumentCategory::Other);
    }

    private function createAccountingPieceDocument(): Document
    {
        return $this->create(DocumentCategory::AccountingPiece);
    }

    private function create(DocumentCategory $category): Document
    {
        /** @var UploadDocumentHandler $handler */
        $handler = static::getContainer()->get(UploadDocumentHandler::class);
        $etablissement = $this->entity(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);

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
