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
    /**
     * ⚠ CETTE CHAÎNE S'AFFICHE SUR LE TÉLÉPHONE DE L'UTILISATEUR, PAS DANS NOTRE CODE.
     *
     * Elle part dans l'URI `otpauth://`, donc dans le QR code, donc dans Google Authenticator ou
     * Authy — où elle reste à côté du compte, tous les jours. Elle disait encore « Billetterie »,
     * le nom d'un dépôt que personne d'autre que nous ne connaît. C'est **Fluvia**.
     *
     * Plus durable que le titre de page qui portait le même défaut : un titre se corrige au
     * déploiement suivant, une entrée d'authentificateur reste telle quelle jusqu'à ce que la
     * personne la supprime.
     *
     * Sans danger pour les comptes déjà enrôlés : l'émetteur ne participe pas au calcul du code
     * (RFC 6238 — seuls le secret et le temps comptent). Seules les nouvelles inscriptions
     * afficheront le bon nom.
     *
     * « Fluvia » seul plutôt que « Fluvia — IT Cotation » : une entrée d'authentificateur nomme LE
     * SERVICE, pas l'éditeur, et un cadratin n'a rien à faire dans une URI.
     */
    private const EMETTEUR = 'Fluvia';

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
