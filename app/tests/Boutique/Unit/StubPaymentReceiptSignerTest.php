<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Unit;

use App\Boutique\Paiement\StubPaymentReceiptSigner;
use PHPUnit\Framework\TestCase;

/**
 * Fail-closed du signataire de recus de DEMONSTRATION (audit du 14/09, bloquant 1, issue #104).
 *
 * Ce bouchon fabrique ET atteste des recus SANS qu'aucun paiement reel ait eu lieu : il ne doit rien
 * produire tant que PAIEMENT_EN_LIGNE_BOUCHON_AUTORISE n'est pas explicitement pose (preproduction /
 * test). On prouve le refus NON autorise, et — temoin positif, sans lequel un refus pourrait masquer
 * un signataire casse — le fonctionnement UNE FOIS autorise.
 */
final class StubPaymentReceiptSignerTest extends TestCase
{
    public function testReceiptsRefuseSansAutorisation(): void
    {
        $signer = new StubPaymentReceiptSigner('secret-de-test', autorise: false);
        $this->expectException(\RuntimeException::class);
        $signer->receipts('REF-1', 1500);
    }

    public function testVerifyRefuseSansAutorisation(): void
    {
        $signer = new StubPaymentReceiptSigner('secret-de-test', autorise: false);
        $this->expectException(\RuntimeException::class);
        $signer->verify('REF-1', 1500, 'un-recu-quelconque');
    }

    public function testAutoriseMinteEtAtteste(): void
    {
        $signer = new StubPaymentReceiptSigner('secret-de-test', autorise: true);

        $recus = $signer->receipts('REF-1', 1500);
        self::assertNotEmpty($recus, 'Autorise, le signataire doit produire au moins un recu.');

        // Round-trip sans nommer d'enum : le recu qu'il vient de signer, il l'atteste ; un autre
        // montant ou un recu forge, non.
        $statutValue = array_key_first($recus);
        $recu = $recus[$statutValue];

        $atteste = $signer->verify('REF-1', 1500, $recu);
        self::assertNotNull($atteste, 'Le recu qu\'il vient de signer doit etre atteste.');
        self::assertSame($statutValue, $atteste->value);

        self::assertNull($signer->verify('REF-1', 9999, $recu), 'Un montant different ne doit rien attester.');
        self::assertNull($signer->verify('REF-1', 1500, 'recu-forge'), 'Un recu non signe ne doit rien attester.');
    }
}
