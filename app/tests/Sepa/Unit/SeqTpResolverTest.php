<?php

declare(strict_types=1);

namespace App\Tests\Sepa\Unit;

use App\Sepa\Entity\MandatSepa;
use App\Sepa\Enum\SeqTpSepa;
use App\Sepa\Service\SeqTpResolver;
use PHPUnit\Framework\TestCase;

/** `SeqTpResolver` (plan §3/§8) : FRST (0 collecte) / RCUR (≥1) / FNAL (dernière échéance) / OOFF (unique). */
final class SeqTpResolverTest extends TestCase
{
    public function testFrstSiMandatJamaisCollecteAvecSucces(): void
    {
        $mandat = new MandatSepa();
        self::assertSame(0, $mandat->getNbCollectesReussies());

        $seqTp = (new SeqTpResolver())->resoudre($mandat, false, false);

        self::assertSame(SeqTpSepa::Frst, $seqTp);
    }

    public function testRcurSiAuMoinsUneCollecteReussie(): void
    {
        $mandat = new MandatSepa();
        $mandat->incrementerCollectesReussies();

        $seqTp = (new SeqTpResolver())->resoudre($mandat, false, false);

        self::assertSame(SeqTpSepa::Rcur, $seqTp);
    }

    public function testFnalSiDerniereEcheanceDunEngagementADureeDeterminee(): void
    {
        $mandat = new MandatSepa();
        $mandat->incrementerCollectesReussies();

        // Prioritaire même si RCUR aurait été résolu autrement (nbCollectesReussies >= 1).
        $seqTp = (new SeqTpResolver())->resoudre($mandat, true, false);

        self::assertSame(SeqTpSepa::Fnal, $seqTp);
    }

    public function testOoffSiPaiementUniquePrioritaireSurToutLeReste(): void
    {
        $mandat = new MandatSepa();

        $seqTp = (new SeqTpResolver())->resoudre($mandat, true, true);

        self::assertSame(SeqTpSepa::Ooff, $seqTp);
    }
}
