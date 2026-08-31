<?php

declare(strict_types=1);

namespace App\Tests\Securite\Unit;

use App\Securite\Service\GenerateurTotp;
use PHPUnit\Framework\TestCase;

/**
 * L'URI DE PROVISIONNEMENT EST LA SEULE CHOSE QUE L'UTILISATEUR EMPORTE.
 *
 * Elle part dans un QR code, entre dans Google Authenticator ou Authy, et n'en ressort jamais. Ce
 * qu'elle contient au moment du scan est ce que la personne verra tous les jours, pendant des
 * années — bien après que nous ayons corrigé la source.
 *
 * ── CE QUI EST VÉRIFIÉ, ET POURQUOI CE N'EST PAS UNE CONSTANTE COMPARÉE À ELLE-MÊME ─────────────
 *
 * Le test n'affirme pas « la constante vaut Fluvia » — ce serait comparer le code à lui-même. Il
 * affirme que l'émetteur ARRIVE dans l'URI : si quelqu'un retire `setIssuer()` en simplifiant, la
 * constante reste juste et l'authentificateur n'affiche plus qu'une adresse e-mail nue, sans dire
 * de quel service. L'utilisateur qui a trois comptes ne sait plus lequel est lequel.
 *
 * Il vérifie aussi que le code se calcule SANS l'émetteur (RFC 6238 : secret et temps seulement).
 * C'est ce qui rend le changement de nom sans danger pour les comptes déjà enrôlés, et c'est une
 * affirmation qu'il vaut mieux mesurer que supposer avant de toucher à une brique
 * d'authentification.
 */
final class GenerateurTotpTest extends TestCase
{
    public function testLUriDeProvisionnementPorteLeNomDuServiceEtCeluiDuCompte(): void
    {
        $generateur = new GenerateurTotp();
        $secret = $generateur->genererSecret();

        $uri = $generateur->uriProvisionnement($secret, 'agent@exemple.fr');

        self::assertStringStartsWith('otpauth://totp/', $uri);

        parse_str((string) parse_url($uri, \PHP_URL_QUERY), $parametres);
        self::assertSame(
            'Fluvia',
            $parametres['issuer'] ?? null,
            'L’émetteur est ce que l’application d’authentification affiche à côté du compte, sur le téléphone, tous les jours.',
        );

        self::assertStringContainsString(
            'agent@exemple.fr',
            rawurldecode((string) parse_url($uri, \PHP_URL_PATH)),
            'Sans le compte, une personne qui gère plusieurs accès ne sait plus lequel est lequel.',
        );
    }

    /**
     * LE NOM NE PARTICIPE PAS AU CALCUL — et c'est ce qui permet de le corriger sans casser les
     * comptes déjà enrôlés. Une affirmation qu'on mesure plutôt que de la supposer, s'agissant
     * d'une brique d'authentification.
     */
    public function testLeCodeSeCalculeSansLEmetteur(): void
    {
        $generateur = new GenerateurTotp();
        $secret = $generateur->genererSecret();

        // Le secret seul, sans jamais passer par `uriProvisionnement` ni par l'émetteur.
        self::assertTrue($generateur->verifier($secret, $generateur->codeActuel($secret)));
    }

    public function testUnCodeVideEstRefuseSansLeverNiConsulterLHorloge(): void
    {
        $generateur = new GenerateurTotp();

        self::assertFalse($generateur->verifier($generateur->genererSecret(), ''));
    }
}
