<?php

declare(strict_types=1);

namespace App\Tests\Dms\Api;

use App\DataFixtures\SocleFixtures;
use App\Dms\Entity\Document;
use App\Dms\Entity\DocumentVersion;
use App\Dms\Enum\DocumentCategory;
use App\Dms\Enum\DocumentStatus;
use App\Dms\Service\UploadDocumentHandler;
use App\Dms\Storage\Storage;
use App\Organisation\Entity\Etablissement;
use App\Platform\Event\DomainEvent;
use App\Tests\Dms\DmsApiTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * CA-11 (RG-DMS-15) : document `expired` jamais supprimé explicitement -> rien purgé. Document
 * `deleted` depuis > 30j et `expired`/`none` -> `Storage::delete` appelé, `document.purged` publié.
 * Document `deleted` depuis < 30j (grâce) -> non purgé.
 */
final class PurgeDocumentsCommandTest extends DmsApiTestCase
{
    public function testDocumentExpireJamaisSupprimeExplicitementNestJamaisPurge(): void
    {
        // AccountingPiece -> retainUntil futur à la création ; on force son expiration sans jamais
        // le supprimer (status reste `active`) — CA-11.
        $document = $this->createDocument(DocumentCategory::AccountingPiece);
        $em = $this->em();
        $document->setRetainUntil(new \DateTimeImmutable('-1 day'));
        $em->flush();
        self::assertSame('active', $document->getStatus()->value);

        $captures = $this->captureEvents(['document.purged']);
        $this->runCommand();

        self::assertCount(0, $captures, 'CA-11 : expiration seule -> aucune purge automatique.');

        $em->clear();
        $version = $em->getRepository(DocumentVersion::class)->find($document->getCurrentVersion()->getId());
        self::assertNotNull($version);
        self::assertNull($version->getPurgedAt());
    }

    public function testDocumentSupprimeDepuisPlusDe30JoursEtExpireEstPurge(): void
    {
        $document = $this->createDocument(DocumentCategory::Other); // retentionStatus = none.
        $em = $this->em();
        $document->setStatus(DocumentStatus::Deleted);
        $document->setDeletedAt(new \DateTimeImmutable('-31 days'));
        $em->flush();

        $storageKey = $document->getCurrentVersion()->getStorageKey();
        /** @var Storage $storage */
        $storage = static::getContainer()->get(Storage::class);
        self::assertTrue($storage->exists($storageKey));

        $captures = $this->captureEvents(['document.purged']);
        $this->runCommand();

        self::assertCount(1, $captures);
        /** @var DomainEvent $evenement */
        $evenement = $captures[0];
        self::assertSame('other', $evenement->payload['category']);

        self::assertFalse($storage->exists($storageKey), 'Storage::delete doit avoir retiré le contenu physique.');

        $em->clear();
        $version = $em->getRepository(DocumentVersion::class)->find($document->getCurrentVersion()->getId());
        self::assertNotNull($version);
        self::assertNotNull($version->getPurgedAt());
    }

    public function testDocumentSupprimeDepuisMoinsDe30JoursNestPasPurge(): void
    {
        $document = $this->createDocument(DocumentCategory::Other);
        $em = $this->em();
        $document->setStatus(DocumentStatus::Deleted);
        $document->setDeletedAt(new \DateTimeImmutable('-5 days')); // dans le délai de grâce.
        $em->flush();

        $captures = $this->captureEvents(['document.purged']);
        $this->runCommand();

        self::assertCount(0, $captures, 'Délai de grâce de 30 jours (arbitrage D18 pt.6) : pas encore purgeable.');

        $em->clear();
        $version = $em->getRepository(DocumentVersion::class)->find($document->getCurrentVersion()->getId());
        self::assertNotNull($version);
        self::assertNull($version->getPurgedAt());
    }

    public function testDocumentSupprimeAvecRetentionActiveNestJamaisPurgeMemeApres30Jours(): void
    {
        // Rétention encore active malgré une suppression logique ancienne — ne devrait normalement
        // jamais arriver via l'API (DeleteDocumentProcessor refuse), mais défense en profondeur de la
        // commande de purge (seul Active bloque, RG-DMS-12/15).
        $document = $this->createDocument(DocumentCategory::AccountingPiece);
        $em = $this->em();
        $document->setStatus(DocumentStatus::Deleted);
        $document->setDeletedAt(new \DateTimeImmutable('-31 days'));
        $em->flush();
        self::assertSame('active', $document->getRetentionStatus());

        $captures = $this->captureEvents(['document.purged']);
        $this->runCommand();

        self::assertCount(0, $captures);
    }

    private function runCommand(): void
    {
        $application = new Application(static::$kernel);
        $application->setAutoExit(false);
        $tester = new CommandTester($application->find('dms:purge-expired-documents'));
        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());
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
