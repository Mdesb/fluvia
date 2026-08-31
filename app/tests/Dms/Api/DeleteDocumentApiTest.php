<?php

declare(strict_types=1);

namespace App\Tests\Dms\Api;

use App\DataFixtures\SocleFixtures;
use App\Dms\Entity\Document;
use App\Dms\Enum\DocumentCategory;
use App\Dms\Service\UploadDocumentHandler;
use App\Organisation\Entity\Etablissement;
use App\Tests\Dms\DmsApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * CA-5 (RG-DMS-13) : rétention active -> 409, document intact, `document.deletion_refused` publié.
 * CA-6 (RG-DMS-12) : rétention expirée -> succès, `status = deleted`.
 */
final class DeleteDocumentApiTest extends DmsApiTestCase
{
    public function testSuppressionRefuseeSiRetentionActive(): void
    {
        // AccountingPiece -> politique par défaut fr_accounting_10y (retainUntil dans le futur).
        $document = $this->createDocument(DocumentCategory::AccountingPiece);
        [$client, $entete] = $this->dmsUserOn(SocleFixtures::ETAB_A_NOM, ['read', 'delete']);
        $client->disableReboot();

        $captures = $this->captureEvents(['document.deletion_refused']);

        $client->request('DELETE', '/api/documents/' . $document->getId(), $entete);
        self::assertResponseStatusCodeSame(409, (string) $client->getResponse()->getContent(false));

        self::assertCount(1, $captures);
        self::assertSame('retention_active', $captures[0]->payload['reasonCode']);
        self::assertNotNull($captures[0]->payload['retainUntil']);

        $em = $this->em();
        $em->clear();
        $rechargee = $em->getRepository(Document::class)->find($document->getId());
        self::assertNotNull($rechargee);
        self::assertSame('active', $rechargee->getStatus()->value, 'Le document reste intact après un refus (RG-DMS-13).');
    }

    public function testSuppressionReussieSiRetentionExpiree(): void
    {
        // « other » -> aucune politique par défaut -> retentionStatus = none, suppression libre.
        $document = $this->createDocument(DocumentCategory::Other);
        [$client, $entete] = $this->dmsUserOn(SocleFixtures::ETAB_A_NOM, ['read', 'delete']);
        $client->disableReboot();

        $captures = $this->captureEvents(['document.deleted']);

        $client->request('DELETE', '/api/documents/' . $document->getId(), $entete);
        self::assertResponseStatusCodeSame(204, (string) $client->getResponse()->getContent(false));

        self::assertCount(1, $captures);
        self::assertSame('other', $captures[0]->payload['category']);

        $em = $this->em();
        $em->clear();
        $rechargee = $em->getRepository(Document::class)->find($document->getId());
        self::assertNotNull($rechargee);
        self::assertSame('deleted', $rechargee->getStatus()->value);
        self::assertNotNull($rechargee->getDeletedAt());
    }

    public function testSuppressionRefuseeMemePourUnAdministrateur(): void
    {
        // RG-DMS-13 : le refus s'applique quel que soit le rôle de l'appelant, y compris admin.
        $document = $this->createDocument(DocumentCategory::AccountingPiece);
        [$client, $entete] = $this->adminOnA();

        $client->request('DELETE', '/api/documents/' . $document->getId(), $entete);
        self::assertResponseStatusCodeSame(409);
    }

    private function createDocument(DocumentCategory $category): Document
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
