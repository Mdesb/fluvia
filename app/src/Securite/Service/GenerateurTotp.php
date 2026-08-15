<?php

declare(strict_types=1);

namespace App\Securite\Service;

use OTPHP\InternalClock;
use OTPHP\TOTP;

/**
 * Wrapper `spomky-labs/otphp` (RFC 6238) : génère un secret TOTP + son URI `otpauth://` de
 * provisionnement, vérifie un code fourni à la connexion (§2.3 plan-backoffice.md).
 */
final class GenerateurTotp
{
    private const EMETTEUR = 'Billetterie IT Cotation';

    public function genererSecret(): string
    {
        return TOTP::generate(new InternalClock())->getSecret();
    }

    public function uriProvisionnement(string $secret, string $identifiantUtilisateur): string
    {
        $totp = TOTP::createFromSecret($secret, new InternalClock());
        $totp->setLabel($identifiantUtilisateur);
        $totp->setIssuer(self::EMETTEUR);

        return $totp->getProvisioningUri();
    }

    public function verifier(string $secret, string $code): bool
    {
        if ($code === '') {
            return false;
        }

        $totp = TOTP::createFromSecret($secret, new InternalClock());

        return $totp->verify($code);
    }

    /** Code TOTP courant (tests/outillage). */
    public function codeActuel(string $secret): string
    {
        return TOTP::createFromSecret($secret, new InternalClock())->now();
    }
}
