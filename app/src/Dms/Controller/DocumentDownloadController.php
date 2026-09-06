<?php

declare(strict_types=1);

namespace App\Dms\Controller;

use App\Dms\Crypto\DocumentStreamCipher;
use App\Dms\Entity\Document;
use App\Dms\Entity\DocumentVersion;
use App\Dms\Http\DocumentResponseHeaders;
use App\Dms\Security\DmsScopeGuard;
use App\Dms\Storage\Storage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /dms/documents/{id}/download` (+ `?version={uuid}` optionnel, défaut = `currentVersion`,
 * plan-dms.md §2.3) — hors CRUD API Platform (négociation JSON par défaut, mauvais candidat pour un
 * `StreamedResponse`), même choix que `App\Securite\Controller\ExportAuditController`.
 *
 * 1. `is_granted('PERM', 'dms.read')` — 403 sinon.
 * 2. Résolution manuelle (`find`, pas de query extension) + revérification explicite du périmètre
 *    (`DmsScopeGuard`, RG-DMS-02) — **404 uniforme**, hors périmètre = id inexistant (CA-2).
 * 3. `?version` fourni : vérifie `version->getDocument() === $document`, sinon 404 (pas de traversée
 *    inter-documents via un id de version deviné).
 * 4. Version purgée (`Storage::exists() === false`) : **410 Gone**, jamais 500.
 *
 * @cloisonnement-verifie : le contrôle de périmètre est délégué à `App\Dms\Security\DmsScopeGuard`
 *   (méthode `verify()`, appelée ci-dessous juste après la résolution `find()`). Annotation posée à
 *   titre documentaire — le nom `DmsScopeGuard` satisfait déjà le motif historique `Verificateur|Guard`
 *   du garde-fou cloisonnement (bin/garde-fou-cloisonnement.php). Revue : Claude (implémentation DMS-1
 *   + renommage D5, 22/08/2026) — voir MESSAGES.md.
 */
#[AsController]
final class DocumentDownloadController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly DmsScopeGuard $scopeGuard,
        private readonly Storage $storage,
        private readonly DocumentStreamCipher $cipher,
    ) {
    }

    #[Route('/dms/documents/{id}/download', name: 'dms_document_download', methods: ['GET'])]
    public function __invoke(string $id, Request $request): StreamedResponse
    {
        if (!$this->security->isGranted('PERM', 'dms.read')) {
            throw new AccessDeniedHttpException('dms.error.forbidden');
        }
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('dms.error.document_not_found');
        }

        /** @var Document|null $document */
        $document = $this->em->find(Document::class, Uuid::fromString($id));
        $this->scopeGuard->verify($document?->getEstablishment());
        \assert($document instanceof Document);

        $versionParam = $request->query->get('version');
        if (\is_string($versionParam) && $versionParam !== '') {
            if (!Uuid::isValid($versionParam)) {
                throw new NotFoundHttpException('dms.error.document_version_not_found');
            }
            $version = $this->em->find(DocumentVersion::class, Uuid::fromString($versionParam));
            if (!$version instanceof DocumentVersion || $version->getDocument()?->getId()->equals($document->getId()) !== true) {
                throw new NotFoundHttpException('dms.error.document_version_not_found');
            }
        } else {
            $version = $document->getCurrentVersion();
        }
        \assert($version instanceof DocumentVersion);

        if (!$this->storage->exists($version->getStorageKey())) {
            throw new GoneHttpException('dms.error.document_version_purged');
        }

        return $this->streamResponse($version->getStorageKey(), $version->getMimeType(), $version->getOriginalFilename());
    }

    private function streamResponse(string $storageKey, string $mimeType, string $originalFilename): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($storageKey): void {
            $source = $this->storage->get($storageKey);
            $destination = fopen('php://output', 'wb');
            \assert($destination !== false);
            $this->cipher->decrypt($source, $destination);
            fclose($source);
            fclose($destination);
        });
        // Toujours en pièce jointe ici (c'était déjà le cas), et désormais `nosniff` + CSP `sandbox`
        // par la même règle que le lien public — voir `DocumentResponseHeaders`.
        DocumentResponseHeaders::apply($response, $mimeType, $originalFilename, inlineAllowed: false);

        return $response;
    }
}
