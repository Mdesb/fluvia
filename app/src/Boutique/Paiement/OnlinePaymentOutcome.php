<?php

declare(strict_types=1);

namespace App\Boutique\Paiement;

use App\Vente\Entity\Vente;

/** What settling an online payment came to (#339). `pending` never invites the buyer to pay again. */
final class OnlinePaymentOutcome
{
    public const CONFIRMED = 'confirme';
    public const INVENTORY_CONFLICT = 'conflit_inventaire';
    public const FAILED = 'echec';
    public const PENDING = 'en_attente';

    private function __construct(
        public readonly string $status,
        public readonly ?Vente $vente = null,
    ) {
    }

    public static function confirmed(Vente $vente, bool $inventoryConflict): self
    {
        return new self($inventoryConflict ? self::INVENTORY_CONFLICT : self::CONFIRMED, $vente);
    }

    public static function failed(): self
    {
        return new self(self::FAILED);
    }

    public static function pending(): self
    {
        return new self(self::PENDING);
    }

    public function canRetry(): bool
    {
        return $this->status === self::FAILED;
    }
}
