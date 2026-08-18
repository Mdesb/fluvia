<?php

declare(strict_types=1);

namespace App\Boutique\Billet;

/**
 * Résultat de la génération d'un QR image (US-L8-08, RG-M3-04/14, CA-12) : porte à la fois le
 * `payload` (code de support signé, `App\Vente\Service\GenerateurCodeSupport`) et l'image rendue
 * (SVG — cf. `GenerateurImageQr` pour le choix du format).
 */
final readonly class ImageQr
{
    public function __construct(
        public string $payload,
        public string $contenu,
        public string $mimeType,
    ) {
    }

    /** Data URI directement embarquable dans un `<img src="...">` (e-mail HTML, gabarit PDF). */
    public function dataUri(): string
    {
        return 'data:' . $this->mimeType . ';base64,' . base64_encode($this->contenu);
    }

    public function extension(): string
    {
        return match ($this->mimeType) {
            'image/svg+xml' => 'svg',
            'image/png' => 'png',
            default => 'bin',
        };
    }

    public function nomFichier(string $prefixe = 'qr'): string
    {
        return $prefixe . '.' . $this->extension();
    }
}
