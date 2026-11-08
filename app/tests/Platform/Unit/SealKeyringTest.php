<?php

declare(strict_types=1);

namespace App\Tests\Platform\Unit;

use App\Platform\Nf525\SealCheck;
use App\Platform\Nf525\SealingRefusedException;
use App\Platform\Nf525\SealKeyring;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * #361 — le trousseau NF525 : identifiant non réversible, choix de la clé par identifiant, clé
 * inconnue distinguée de signature invalide, compatibilité des maillons sans identifiant, échec fermé.
 */
final class SealKeyringTest extends TestCase
{
    private const KEY_A = 'cle-de-test-A-rotation';
    private const KEY_B = 'cle-de-test-B-rotation';

    public function testKeyIdIsStableDistinctAndRevealsNothing(): void
    {
        $idA = SealKeyring::keyIdOf(self::KEY_A);

        self::assertSame($idA, SealKeyring::keyIdOf(self::KEY_A), 'même clé, même identifiant');
        self::assertNotSame($idA, SealKeyring::keyIdOf(self::KEY_B), 'deux clés, deux identifiants');
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $idA);
        self::assertStringNotContainsString(self::KEY_A, $idA);
        // L'identifiant n'est pas une simple empreinte de la clé qu'on pourrait comparer à un dictionnaire
        // de sha256 publics : il est dérivé par HMAC sous un libellé propre.
        self::assertStringStartsNotWith($idA, hash('sha256', self::KEY_A));
    }

    public function testVerificationPicksTheKeyNamedByTheLink(): void
    {
        $old = new SealKeyring('NF525_SEAL_KEY', self::KEY_A);
        $signedByA = $old->sign('empreinte-1');

        $rotated = new SealKeyring('NF525_SEAL_KEY', self::KEY_B, self::KEY_A);
        self::assertSame(SealCheck::Valid, $rotated->check('empreinte-1', $signedByA, SealKeyring::keyIdOf(self::KEY_A)));
        // La clé est choisie PAR L'IDENTIFIANT : annoncer B pour une signature de A est faux.
        self::assertSame(SealCheck::InvalidSignature, $rotated->check('empreinte-1', $signedByA, SealKeyring::keyIdOf(self::KEY_B)));
        self::assertSame(SealCheck::InvalidSignature, $rotated->check('empreinte-1', $signedByA . '0', SealKeyring::keyIdOf(self::KEY_A)));
    }

    public function testUnknownKeyIsNotAnInvalidSignature(): void
    {
        $signedByA = (new SealKeyring('NF525_SEAL_KEY', self::KEY_A))->sign('empreinte-1');
        $withoutA = new SealKeyring('NF525_SEAL_KEY', self::KEY_B);

        $check = $withoutA->check('empreinte-1', $signedByA, SealKeyring::keyIdOf(self::KEY_A));
        self::assertSame(SealCheck::UnknownKey, $check);
        self::assertStringContainsString('clé inconnue du trousseau', (string) $withoutA->problem($check, SealKeyring::keyIdOf(self::KEY_A)));
        self::assertStringContainsString('NF525_SEAL_RETIRED_KEYS', (string) $withoutA->problem($check, SealKeyring::keyIdOf(self::KEY_A)));
    }

    public function testKeyIdIsBoundToTheSignature(): void
    {
        $signedByA = (new SealKeyring('NF525_SEAL_KEY', self::KEY_A))->sign('empreinte-1');
        $keyring = new SealKeyring('NF525_SEAL_KEY', self::KEY_A, self::KEY_B);

        self::assertSame(SealCheck::Valid, $keyring->check('empreinte-1', $signedByA, SealKeyring::keyIdOf(self::KEY_A)));
        // Effacer l'identifiant ne rouvre pas le chemin « toutes les clés ».
        self::assertSame(SealCheck::InvalidSignature, $keyring->check('empreinte-1', $signedByA, null));
        // Le remplacer par un autre identifiant connu non plus.
        self::assertSame(SealCheck::InvalidSignature, $keyring->check('empreinte-1', $signedByA, SealKeyring::keyIdOf(self::KEY_B)));
    }

    public function testLinkWithoutIdIsCheckedWithActiveThenRetiredKeys(): void
    {
        // Maillon antérieur au trousseau : signé sans identifiant.
        $signedByA = (new SealKeyring('NF525_SEAL_KEY', self::KEY_A))->signWithoutId('empreinte-1');

        self::assertSame(SealCheck::Valid, (new SealKeyring('NF525_SEAL_KEY', self::KEY_A))->check('empreinte-1', $signedByA, null));
        self::assertSame(SealCheck::Valid, (new SealKeyring('NF525_SEAL_KEY', self::KEY_B, 'autre, ' . self::KEY_A))->check('empreinte-1', $signedByA, null));
        self::assertSame(SealCheck::InvalidSignature, (new SealKeyring('NF525_SEAL_KEY', self::KEY_B))->check('empreinte-1', $signedByA, null));
    }

    public function testRetiredKeysNeverSign(): void
    {
        $keyring = new SealKeyring('NF525_SEAL_KEY', self::KEY_B, self::KEY_A);

        self::assertSame(hash_hmac('sha256', 'x|' . SealKeyring::keyIdOf(self::KEY_B), self::KEY_B), $keyring->sign('x'));
        self::assertSame(hash_hmac('sha256', 'x', self::KEY_B), $keyring->signWithoutId('x'));
        self::assertSame(SealKeyring::keyIdOf(self::KEY_B), $keyring->activeKeyId());
        self::assertSame([SealKeyring::keyIdOf(self::KEY_A)], $keyring->retiredKeyIds());
    }

    public function testEmptyActiveKeyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SealKeyring('NF525_SEAL_KEY', '  ');
    }

    public function testExtendingAnUnverifiableChainIsRefusedAndAlerted(): void
    {
        $signedByA = (new SealKeyring('NF525_SEAL_KEY', self::KEY_A))->sign('empreinte-1');
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, string, array<mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context];
            }
        };
        $withoutA = new SealKeyring('NF525_SEAL_KEY', self::KEY_B, null, $logger);

        try {
            $withoutA->assertCanExtend('empreinte-1', $signedByA, SealKeyring::keyIdOf(self::KEY_A), 'chaîne témoin');
            self::fail('Le scellement aurait dû être refusé.');
        } catch (SealingRefusedException $e) {
            self::assertStringContainsString('Scellement refusé', $e->getMessage());
            self::assertStringContainsString('NF525_SEAL_RETIRED_KEYS', $e->getMessage());
            self::assertSame(409, $e->getStatusCode());
        }

        self::assertCount(1, $logger->records);
        self::assertSame('critical', $logger->records[0][0]);
        // L'alerte ne contient que des identifiants, jamais une clé.
        self::assertStringNotContainsString(self::KEY_A, json_encode($logger->records, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(self::KEY_B, json_encode($logger->records, \JSON_THROW_ON_ERROR));

        // Témoin : avec A au trousseau, la même chaîne se prolonge sans alerte.
        (new SealKeyring('NF525_SEAL_KEY', self::KEY_B, self::KEY_A, $logger))
            ->assertCanExtend('empreinte-1', $signedByA, SealKeyring::keyIdOf(self::KEY_A), 'chaîne témoin');
        self::assertCount(1, $logger->records);
    }
}
