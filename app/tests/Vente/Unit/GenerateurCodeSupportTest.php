<?php

declare(strict_types=1);

namespace App\Tests\Vente\Unit;

use App\Vente\Enum\TypeSupport;
use App\Vente\Service\GenerateurCodeSupport;
use PHPUnit\Framework\TestCase;

/**
 * Génération/vérification du code de support signé (CA-12) : format opaque, unicité pratique,
 * signature HMAC vérifiable, rejet d'un code forgé/altéré ou mal formé.
 */
final class GenerateurCodeSupportTest extends TestCase
{
    private function generateur(string $cle = 'cle-de-test-hmac'): GenerateurCodeSupport
    {
        return new GenerateurCodeSupport($cle);
    }

    public function testCodeGenereEstNonVideEtRespecteLeFormat(): void
    {
        $generateur = $this->generateur();

        $code = $generateur->genererPourType(TypeSupport::Billet);

        self::assertNotSame('', $code);
        self::assertMatchesRegularExpression('/^BIL-[0-9A-HJKMNP-TV-Z]{16}-[0-9A-F]{10}$/', $code);
    }

    public function testPrefixeRefleteLeTypeDeSupport(): void
    {
        $generateur = $this->generateur();

        self::assertStringStartsWith('BIL-', $generateur->genererPourType(TypeSupport::Billet));
        self::assertStringStartsWith('CAR-', $generateur->genererPourType(TypeSupport::Carte));
        self::assertStringStartsWith('QRC-', $generateur->genererPourType(TypeSupport::Qr));
        self::assertStringStartsWith('BRA-', $generateur->genererPourType(TypeSupport::Bracelet));
        self::assertStringStartsWith('WAL-', $generateur->genererPourType(TypeSupport::Wallet));
    }

    public function testDeuxCodesGeneresSontDistincts(): void
    {
        $generateur = $this->generateur();

        $codes = [];
        for ($i = 0; $i < 50; ++$i) {
            $codes[] = $generateur->genererPourType(TypeSupport::Billet);
        }

        self::assertCount(50, array_unique($codes), 'Chaque code généré doit être unique (entropie ~80 bits).');
    }

    public function testCodeGenereEstVerifiableParLeMemeGenerateur(): void
    {
        $generateur = $this->generateur();

        $code = $generateur->genererPourType(TypeSupport::Carte);

        self::assertTrue($generateur->estCodeSigne($code));
        self::assertTrue($generateur->verifier($code));
    }

    public function testCodeForgeAvecMauvaiseSignatureEstRefuse(): void
    {
        $generateur = $this->generateur();

        $code = $generateur->genererPourType(TypeSupport::Billet);
        // Altère le dernier caractère de la signature (forge un code au format valide mais signé
        // incorrectement).
        $dernier = substr($code, -1);
        $remplacement = $dernier === 'A' ? 'B' : 'A';
        $code_force = substr($code, 0, -1) . $remplacement;

        self::assertTrue($generateur->estCodeSigne($code_force), 'Le format reste valide (attaque plausible).');
        self::assertFalse($generateur->verifier($code_force), 'La signature falsifiée doit être détectée.');
    }

    public function testCodeSigneAvecUneAutreCleEstRefuse(): void
    {
        $emetteur = $this->generateur('cle-A');
        $verificateur = $this->generateur('cle-B');

        $code = $emetteur->genererPourType(TypeSupport::Billet);

        self::assertFalse($verificateur->verifier($code), 'Une clé différente ne doit jamais valider un code.');
    }

    public function testIdentifiantLegacyNestPasConsidereCommeUnCodeSigne(): void
    {
        $generateur = $this->generateur();

        // Identifiants historiques/manuels (fixtures, RFID) : préfixe à 2 ou 4+ lettres, format libre.
        self::assertFalse($generateur->estCodeSigne('QR-DEMO-0001'));
        self::assertFalse($generateur->estCodeSigne('RFID-NEUF-0001'));
        self::assertFalse($generateur->estCodeSigne('ECHEC-CARTE'));
        self::assertFalse($generateur->verifier('QR-DEMO-0001'));
    }

    public function testChaineVideNestPasUnCodeSigne(): void
    {
        $generateur = $this->generateur();

        self::assertFalse($generateur->estCodeSigne(''));
        self::assertFalse($generateur->verifier(''));
    }
}
