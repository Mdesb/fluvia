<?php

declare(strict_types=1);

namespace App\Platform\Nf525;

/**
 * Une régression de clé constatée sur un maillon (#369) : le maillon n° `sequence` porte une clé que la
 * chaîne avait déjà quittée (`RETURNED_KEY`), ou aucun identifiant après un maillon signé par une clé
 * (`NULL_AFTER_KEY`). Identifiants seulement, jamais les clés.
 */
final readonly class KeyRegression
{
    public const RETURNED_KEY = 'returned_key';
    public const NULL_AFTER_KEY = 'null_after_key';

    /**
     * @param self::RETURNED_KEY|self::NULL_AFTER_KEY $kind
     * @param ?string $keyId         identifiant porté par le maillon fautif (`null` pour NULL_AFTER_KEY)
     * @param ?string $previousKeyId RETURNED_KEY : clé du maillon qui précède (`null` s'il n'en portait pas) ;
     *                               NULL_AFTER_KEY : dernière clé non NULL vue dans la chaîne
     */
    public function __construct(
        public string $kind,
        public int $sequence,
        public ?string $keyId,
        public ?string $previousKeyId,
    ) {
    }
}
