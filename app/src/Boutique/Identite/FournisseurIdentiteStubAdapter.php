<?php

declare(strict_types=1);

namespace App\Boutique\Identite;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Adaptateur par défaut — stub déterministe (⚠ Risque n°4 du plan) : ne contacte aucun IdP réel.
 * Le "code" attendu est de la forme "sub|email|nom|prenom" (usage test/démo uniquement).
 */
final class FournisseurIdentiteStubAdapter implements FournisseurIdentiteInterface
{
    public function urlAutorisation(string $redirectUri): string
    {
        return 'https://franceconnect.example.test/api/v1/authorize?redirect_uri=' . urlencode($redirectUri);
    }

    public function authentifier(string $code, string $redirectUri): IdentiteFranceConnect
    {
        $segments = explode('|', $code);
        if (\count($segments) < 4 || trim($segments[0]) === '') {
            throw new UnprocessableEntityHttpException('Code FranceConnect invalide ou expiré (stub).');
        }

        return new IdentiteFranceConnect(
            sub: $segments[0],
            email: $segments[1],
            nom: $segments[2],
            prenom: $segments[3],
            dateNaissance: isset($segments[4]) && $segments[4] !== '' ? new \DateTimeImmutable($segments[4]) : null,
        );
    }
}
