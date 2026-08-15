<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Enum\FormatExport;
use App\Compta\Enum\TypeExploitant;
use App\Compta\Regime\RegimeComptableResolver;
use App\Compta\Regime\RegimeDspPcg;
use App\Compta\Regime\RegimeGroupePrive;
use App\Compta\Regime\RegimeRegieDirecte;
use App\Tests\Compta\ComptaApiTestCase;

/**
 * §0/§12 du plan : `RegimeComptableResolver` retourne l'implémentation correspondant au type du
 * profil exploitant — itérateur taggé, aucun `switch`.
 */
final class RegimeResolverTest extends ComptaApiTestCase
{
    public function testResolutionParTypeExploitant(): void
    {
        static::createClient(); // boot du kernel pour accéder au container.

        /** @var RegimeComptableResolver $resolver */
        $resolver = static::getContainer()->get(RegimeComptableResolver::class);

        $regie = new ProfilExploitant();
        $regie->setType(TypeExploitant::RegieDirecte);
        self::assertInstanceOf(RegimeRegieDirecte::class, $resolver->pour($regie));
        self::assertContains(FormatExport::PesV2Helios, $resolver->pour($regie)->formatsExportDisponibles());
        self::assertNotContains(FormatExport::Fec, $resolver->pour($regie)->formatsExportDisponibles());

        $dsp = new ProfilExploitant();
        $dsp->setType(TypeExploitant::Dsp);
        self::assertInstanceOf(RegimeDspPcg::class, $resolver->pour($dsp));
        self::assertContains(FormatExport::Fec, $resolver->pour($dsp)->formatsExportDisponibles());

        $groupe = new ProfilExploitant();
        $groupe->setType(TypeExploitant::GroupePrive);
        self::assertInstanceOf(RegimeGroupePrive::class, $resolver->pour($groupe));
    }
}
