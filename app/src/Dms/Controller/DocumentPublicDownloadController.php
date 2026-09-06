<?php

declare(strict_types=1);

namespace App\Dms\Controller;

use App\Dms\Crypto\DocumentStreamCipher;
use App\Dms\Entity\DocumentPublicLink;
use App\Dms\Enum\DocumentStatus;
use App\Dms\Http\DocumentResponseHeaders;
use App\Dms\Storage\Storage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /dms/public/{token}` — **hors `/api`**, **aucune authentification, aucune session/cookie
 * applicatif** (RG-DMS-09, plan-dms.md §2.3) :
 *
 * 1. `hash('sha256', $token)` -> `findOneBy(['tokenHash' => ...])` — 404 si absent, **même statut
 *    404 constant** pour absent/révoqué/expiré (aucune distinction observable, cohérence RG-DMS-03).
 * 2. Vérifie dans l'ordre : `revokedAt === null`, `expiresAt > now()`,
 *    `document->getStatus() === Active` (CA-14 — un document supprimé logiquement invalide l'usage de
 *    tous ses liens, révoqués ou non).
 * 3. Incrémente `accessCount`/`lastAccessedAt`, `flush()` — **aucun événement de domaine émis**
 *    (RG-DMS-10).
 * 4. Sert le contenu de `$link->getVersion()` (**jamais** `document->getCurrentVersion()`, RG-DMS-20,
 *    CA-13) — version purgée : **410 Gone**, jamais 500.
 * 5. Les en-têtes de la réponse sont ceux de `DocumentResponseHeaders` : `inline` seulement pour ce
 *    qu'un navigateur affiche sans exécuter, `nosniff` toujours. ⚠ Avant le 06/09, ce contrôleur
 *    servait un `text/html` déclaré par le client en `inline` sur l'origine de l'application —
 *    XSS stocké, audit constat 6.
 */
#[AsController]
final class DocumentPublicDownloadController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Storage $storage,
        private readonly DocumentStreamCipher $cipher,
    ) {
    }

    #[Route('/dms/public/{token}', name: 'dms_document_public_download', methods: ['GET'])]
    public function __invoke(string $token): StreamedResponse
    {
        $hash = hash('sha256', $token);
        /** @var DocumentPublicLink|null $lien */
        $lien = $this->em->getRepository(DocumentPublicLink::class)->findOneBy(['tokenHash' => $hash]);
        if (!$lien instanceof DocumentPublicLink) {
            throw new NotFoundHttpException('dms.error.public_link_not_found');
        }

        $maintenant = new \DateTimeImmutable();
        if (!$lien->isValid($maintenant)) {
            throw new NotFoundHttpException('dms.error.public_link_not_found');
        }

        $document = $lien->getDocument();
        if ($document === null || $document->getStatus() !== DocumentStatus::Active) {
            throw new NotFoundHttpException('dms.error.public_link_not_found');
        }

        $version = $lien->getVersion();
        if ($version === null) {
            throw new NotFoundHttpException('dms.error.public_link_not_found');
        }

        // Vérif d'existence du contenu AVANT d'enregistrer l'accès : une version physiquement purgée ne
        // doit pas être comptée comme « accédée » (410 propre, jamais un accès fantôme sur du vide).
        if (!$this->storage->exists($version->getStorageKey())) {
            throw new GoneHttpException('dms.error.document_version_purged');
        }

        $lien->recordAccess($maintenant);
        $this->em->flush(); // aucun événement de domaine émis (RG-DMS-10) — accès tracé sur l'entité seule.

        $mimeType = $version->getMimeType();
        $storageKey = $version->getStorageKey();

        $response = new StreamedResponse(function () use ($storageKey): void {
            $source = $this->storage->get($storageKey);
            $destination = fopen('php://output', 'wb');
            \assert($destination !== false);
            $this->cipher->decrypt($source, $destination);
            fclose($source);
            fclose($destination);
        });
        DocumentResponseHeaders::apply($response, $mimeType, $version->getOriginalFilename(), inlineAllowed: true);

        return $response;
    }
}
