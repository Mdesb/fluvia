<?php

declare(strict_types=1);

namespace App\Tests\Sepa\Unit;

use App\Sepa\Adapter\CollecteurSepaStubAdapter;
use App\Sepa\Entity\RemiseSepa;
use PHPUnit\Framework\TestCase;

/**
 * Adaptateur SEPA stub (§4 du plan, Risque n°2 repris de Sport) : référence de transmission
 * déterministe, aucune remise bancaire réelle.
 */
final class CollecteurSepaStubAdapterTest extends TestCase
{
    public function testTransmettreProduitUneReferenceDeterministe(): void
    {
        $adapter = new CollecteurSepaStubAdapter();
        $remise = new RemiseSepa();
        $remise->setNbTxs(2);

        $reference1 = $adapter->transmettre($remise);
        $reference2 = $adapter->transmettre($remise);

        self::assertSame($reference1, $reference2);
        self::assertStringStartsWith('TRANSMISSION-', $reference1);
    }
}
