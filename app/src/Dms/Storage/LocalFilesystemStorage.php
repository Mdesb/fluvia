<?php

declare(strict_types=1);

namespace App\Dms\Storage;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Adaptateur v1 du port `Storage` (arbitrage D18 pt.2, spec-dms.md §8.1) : système de fichiers du VPS,
 * racine **hors `public/`** (`var/dms/storage`, jamais servi directement par nginx — tout accès passe
 * par `DocumentDownloadController`/`DocumentPublicDownloadController`, qui appliquent le cloisonnement).
 * Clé shardée (`aa/bb/<32 hex>.bin`, cf. `App\Dms\Storage\StorageKeyGenerator`) pour éviter un
 * répertoire à plat avec des centaines de milliers d'entrées. Aliasé en DI vers `App\Dms\Storage\Storage`
 * (`#[AsAlias]`, même patron que `App\Ocr\Service\TenantAwareDocumentExtractor`).
 */
#[AsAlias(id: Storage::class)]
final class LocalFilesystemStorage implements Storage
{
    private readonly string $root;

    public function __construct(#[Autowire('%kernel.project_dir%')] string $projectDir)
    {
        $this->root = rtrim($projectDir, '/\\') . '/var/dms/storage';
    }

    public function put(string $storageKey, $stream): void
    {
        $chemin = $this->pathFor($storageKey);
        $repertoire = \dirname($chemin);
        if (!is_dir($repertoire) && !mkdir($repertoire, 0775, true) && !is_dir($repertoire)) {
            throw new \RuntimeException(sprintf('dms.error.storage_directory_unwritable (%s)', $repertoire));
        }

        $destination = fopen($chemin, 'wb');
        if ($destination === false) {
            throw new \RuntimeException(sprintf('dms.error.storage_write_failed (%s)', $storageKey));
        }
        try {
            if (stream_copy_to_stream($stream, $destination) === false) {
                throw new \RuntimeException(sprintf('dms.error.storage_write_failed (%s)', $storageKey));
            }
        } finally {
            fclose($destination);
        }
    }

    public function get(string $storageKey)
    {
        $ressource = @fopen($this->pathFor($storageKey), 'rb');
        if ($ressource === false) {
            throw new \RuntimeException(sprintf('dms.error.storage_content_missing (%s)', $storageKey));
        }

        return $ressource;
    }

    public function delete(string $storageKey): void
    {
        $chemin = $this->pathFor($storageKey);
        if (is_file($chemin)) {
            unlink($chemin);
        }
    }

    public function exists(string $storageKey): bool
    {
        return is_file($this->pathFor($storageKey));
    }

    private function pathFor(string $storageKey): string
    {
        // `$storageKey` est une valeur opaque déjà shardée par l'appelant (aa/bb/<hex>.bin) — jamais
        // reconstruite ici à partir d'un identifiant métier (RG-DMS-24).
        return $this->root . '/' . $storageKey;
    }
}
