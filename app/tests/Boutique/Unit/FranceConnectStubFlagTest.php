<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Unit;

use App\Boutique\Identite\FournisseurIdentiteStubAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * CA-7 (#101) : le bouchon FranceConnect refuse hors drapeau. Témoin : drapeau posé, il identifie.
 */
final class FranceConnectStubFlagTest extends TestCase
{
    private const CODE = 'sub-1|fc@example.test|Durand|Alix';

    public function testRefusesWithoutTheFlag(): void
    {
        $this->expectException(AccessDeniedHttpException::class);
        (new FournisseurIdentiteStubAdapter())->authentifier(self::CODE, '');
    }

    public function testAuthorizationUrlIsRefusedWithoutTheFlagToo(): void
    {
        $this->expectException(AccessDeniedHttpException::class);
        (new FournisseurIdentiteStubAdapter(false))->urlAutorisation('https://boutique.example.test/retour');
    }

    public function testIdentifiesWhenTheFlagIsSet(): void
    {
        $identite = (new FournisseurIdentiteStubAdapter(true))->authentifier(self::CODE, '');

        self::assertSame('sub-1', $identite->sub);
        self::assertSame('fc@example.test', $identite->email);
    }
}
