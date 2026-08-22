<?php

declare(strict_types=1);

namespace App\Dms;

use App\Dms\Dto\StoreDocumentRequest;
use App\Dms\Dto\StoredDocument;
use App\Dms\Dto\StoredDocumentVersion;
use Symfony\Component\Uid\Uuid;

/**
 * Port PHP synchrone pour les modules consommateurs (plan-dms.md §5) — même famille que
 * `App\Ocr\DocumentExtractor` : vit à la racine `App\Dms` (pas de sous-namespace), aucune dépendance à
 * un domaine consommateur (Finance, etc.).
 *
 * `readContent()` **ne revérifie aucune permission `dms.*`** — le module appelant est responsable de son
 * propre contrôle d'accès (acteur « Consommateur applicatif », spec-dms.md §3), exactement comme
 * `DocumentExtractor` ne vérifie pas `ocr.*`.
 */
interface DocumentStore
{
    public function store(StoreDocumentRequest $request): StoredDocument;

    /** Métadonnées seules (jamais le contenu) — `null` si le document n'existe pas. */
    public function currentVersion(Uuid $documentId): ?StoredDocumentVersion;

    /**
     * Contenu déchiffré en flux de la version demandée (défaut = `currentVersion`).
     *
     * @return resource
     */
    public function readContent(Uuid $documentId, ?Uuid $versionId = null);
}
