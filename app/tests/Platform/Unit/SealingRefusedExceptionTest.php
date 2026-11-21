<?php

declare(strict_types=1);

namespace App\Tests\Platform\Unit;

use App\Platform\Nf525\SealingRefusedException;
use App\Platform\Nf525\SealRefusalCause;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** G-8 : le message du 409 est générique et fixe ; le détail technique ne vit que dans `technicalDetail()`. */
final class SealingRefusedExceptionTest extends TestCase
{
    private const GENERIC = 'Scellement refusé : la chaîne fiscale est bloquée. L\'administrateur et l\'éditeur sont prévenus.';
    private const TECHNICAL = 'Scellement refusé (NF525, Caisse X) : clé 0123456789abcdef absente, variable NF525_SEAL_KEY, '
        . 'point de vente 123e4567-e89b-12d3-a456-426614174000. Procédure : infra/README.md, « Trousseau NF525 ».';

    /** @return iterable<string, array{SealRefusalCause}> */
    public static function refusalCauses(): iterable
    {
        foreach ([SealRefusalCause::InvalidSignature, SealRefusalCause::UnknownKey, SealRefusalCause::KeyRegression, SealRefusalCause::UnreadableOrder] as $cause) {
            yield $cause->value => [$cause];
        }
    }

    #[DataProvider('refusalCauses')]
    public function testMessageIsGenericAndLeaksNothing(SealRefusalCause $cause): void
    {
        $e = new SealingRefusedException($cause, self::TECHNICAL);

        self::assertSame(self::GENERIC, $e->getMessage());
        self::assertSame(409, $e->getStatusCode());
        self::assertSame($cause, $e->cause());
        self::assertStringNotContainsString('NF525_', $e->getMessage());
        self::assertSame(0, preg_match('/[0-9a-f]{16}/i', $e->getMessage()), 'aucun identifiant de clé');
        self::assertSame(0, preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-/i', $e->getMessage()), 'aucun UUID');
        self::assertStringNotContainsString('Procédure', $e->getMessage());
        self::assertStringNotContainsString('infra/', $e->getMessage());

        // Témoin positif : le détail contient bien ces éléments, donc l'absence ci-dessus n'est pas vraie par vide.
        self::assertSame(self::TECHNICAL, $e->technicalDetail());
        self::assertStringContainsString('NF525_SEAL_KEY', $e->technicalDetail());
        self::assertStringContainsString('0123456789abcdef', $e->technicalDetail());
        self::assertStringContainsString('infra/', $e->technicalDetail());
    }

    public function testWithReferenceAppendsItAndLeavesTheOriginalUntouched(): void
    {
        $original = new SealingRefusedException(SealRefusalCause::UnknownKey, self::TECHNICAL);
        $referenced = $original->withReference('NF525-A1B2C3D4');

        self::assertNotSame($original, $referenced);
        self::assertSame(self::GENERIC, $original->getMessage());
        self::assertSame(self::GENERIC . ' Référence de l\'alerte : NF525-A1B2C3D4.', $referenced->getMessage());
        self::assertSame(SealRefusalCause::UnknownKey, $referenced->cause());
        self::assertSame(self::TECHNICAL, $referenced->technicalDetail());
        self::assertSame(409, $referenced->getStatusCode());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidReferences(): iterable
    {
        yield 'uuid' => ['123e4567-e89b-12d3-a456-426614174000'];
        yield 'variable' => ['NF525_SEAL_KEY'];
        yield 'minuscules' => ['NF525-a1b2c3d4'];
        yield 'trop court' => ['NF525-A1B2C3'];
        yield 'retour à la ligne' => ["NF525-A1B2C3D4\n"];
        yield 'vide' => [''];
    }

    #[DataProvider('invalidReferences')]
    public function testMalformedReferenceIsRejected(string $reference): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SealingRefusedException(SealRefusalCause::UnknownKey, self::TECHNICAL))->withReference($reference);
    }

    public function testMalformedReferenceIsRejectedByTheConstructorToo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SealingRefusedException(SealRefusalCause::UnknownKey, self::TECHNICAL, 'NF525_SEAL_KEY');
    }
}
