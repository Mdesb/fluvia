<?php

declare(strict_types=1);

namespace App\Dms\Storage;

/**
 * Port de stockage enfichable (RG-DMS-24) — le domaine `App\Dms` ne dépend jamais d'un SDK concret.
 * Adaptateur v1 : `LocalFilesystemStorage` (arbitrage D18 pt.2, système de fichiers du VPS). Le contenu
 * transitant par ce port est **toujours déjà chiffré** (`DocumentStreamCipher`) — `Storage` ne connaît
 * ni la clé ni le format en clair, il persiste des octets opaques sous une clé opaque.
 */
interface Storage
{
    /**
     * Écrit le contenu de `$stream` (ressource ouverte en lecture) sous `$storageKey`. Copie en flux
     * (`stream_copy_to_stream`), jamais un chargement mémoire complet (RG-DMS-28).
     *
     * @param resource $stream
     */
    public function put(string $storageKey, $stream): void;

    /**
     * Ouvre `$storageKey` en lecture. Lève une exception si absent — l'appelant (contrôleur de
     * téléchargement) doit distinguer ce cas via `exists()` avant d'appeler `get()` s'il veut répondre
     * `410 Gone`/`404` proprement plutôt que laisser échouer.
     *
     * @return resource
     */
    public function get(string $storageKey);

    /** Retire le contenu physique de `$storageKey` (purge, RG-DMS-15). Idempotent : absent = no-op. */
    public function delete(string $storageKey): void;

    /** Vrai si un contenu existe sous cette clé — utilisé pour détecter une version purgée. */
    public function exists(string $storageKey): bool;
}
