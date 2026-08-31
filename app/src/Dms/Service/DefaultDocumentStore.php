<?php

declare(strict_types=1);

namespace App\Dms\Service;

use App\Dms\Crypto\DocumentStreamCipher;
use App\Dms\Dto\StoreDocumentRequest;
use App\Dms\Dto\StoredDocument;
use App\Dms\Dto\StoredDocumentVersion;
use App\Dms\DocumentStore;
use App\Dms\Entity\Document;
use App\Dms\Entity\DocumentVersion;
use App\Dms\Storage\Storage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/**
 * Implémentation par défaut du port `DocumentStore` (plan-dms.md §5, aliasée en DI vers
 * `App\Dms\DocumentStore`). Réutilise `UploadDocumentHandler` pour garantir exactement le même
 * invariant transactionnel (§0.1) que la voie HTTP — aucune duplication de logique de stockage.
 */
#[AsAlias(id: DocumentStore::class)]
final class DefaultDocumentStore implements DocumentStore
{
    public function __construct(
        private readonly UploadDocumentHandler $uploadHandler,
        private readonly EntityManagerInterface $em,
        private readonly DocumentStreamCipher $cipher,
        private readonly Storage $storage,
    ) {
    }

    public function store(StoreDocumentRequest $request): StoredDocument
    {
        $document = $this->uploadHandler->upload(
            $request->establishment,
            $request->category,
            $request->title,
            null,
            $request->sourceModule,
            $request->content,
            $request->originalFilename,
            $request->mimeType,
            $request->actor,
        );

        $version = $document->getCurrentVersion();
        \assert($version instanceof DocumentVersion);

        return new StoredDocument($document->getId(), $version->getId());
    }

    public function currentVersion(Uuid $documentId): ?StoredDocumentVersion
    {
        $document = $this->em->find(Document::class, $documentId);
        if (!$document instanceof Document) {
            return null;
        }
        $version = $document->getCurrentVersion();
        if (!$version instanceof DocumentVersion) {
            return null;
        }

        return new StoredDocumentVersion(
            $version->getId(),
            $version->getVersionNumber(),
            $version->getFileHash(),
            $version->getSizeBytes(),
            $version->getMimeType(),
        );
    }

    public function readContent(Uuid $documentId, ?Uuid $versionId = null)
    {
        $document = $this->em->find(Document::class, $documentId);
        if (!$document instanceof Document) {
            throw new \RuntimeException('dms.error.document_not_found');
        }

        $version = $versionId !== null
            ? $this->em->find(DocumentVersion::class, $versionId)
            : $document->getCurrentVersion();

        if (!$version instanceof DocumentVersion || !$version->getDocument()?->getId()->equals($documentId)) {
            throw new \RuntimeException('dms.error.document_version_not_found');
        }

        if (!$this->storage->exists($version->getStorageKey())) {
            throw new \RuntimeException('dms.error.document_version_purged');
        }

        $source = $this->storage->get($version->getStorageKey());
        $destination = fopen('php://temp/maxmemory:1048576', 'w+b');
        \assert($destination !== false);

        $this->cipher->decrypt($source, $destination);
        fclose($source);
        rewind($destination);

        return $destination;
    }
}
