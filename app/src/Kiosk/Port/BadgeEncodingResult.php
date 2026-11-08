<?php

declare(strict_types=1);

namespace App\Kiosk\Port;

/** L'issue d'un encodage de badge : encodé (avec la référence du badge), indisponible, ou en échec. */
final class BadgeEncodingResult
{
    public const ISSUED = 'issued';
    public const UNAVAILABLE = 'unavailable';
    public const FAILED = 'failed';

    private function __construct(
        public readonly string $status,
        public readonly ?string $badgeReference,
        public readonly ?string $message,
    ) {
    }

    public static function issued(string $badgeReference): self
    {
        return new self(self::ISSUED, $badgeReference, null);
    }

    public static function unavailable(string $message): self
    {
        return new self(self::UNAVAILABLE, null, $message);
    }

    public static function failed(string $message): self
    {
        return new self(self::FAILED, null, $message);
    }

    public function isIssued(): bool
    {
        return $this->status === self::ISSUED;
    }
}
