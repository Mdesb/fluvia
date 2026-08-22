<?php

declare(strict_types=1);

namespace App\Dms\Storage;

/**
 * Génère une clé de stockage opaque et shardée (`aa/bb/<32 hex>.bin`) — évite un répertoire à plat
 * (`LocalFilesystemStorage`) et ne porte aucune information métier (RG-DMS-24) : ni id de document, ni
 * nom de fichier, ni établissement.
 */
final class StorageKeyGenerator
{
    public function generate(): string
    {
        $hex = bin2hex(random_bytes(16));

        return substr($hex, 0, 2) . '/' . substr($hex, 2, 2) . '/' . $hex . '.bin';
    }
}
