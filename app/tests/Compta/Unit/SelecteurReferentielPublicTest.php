<?php

declare(strict_types=1);

namespace App\Tests\Compta\Unit;

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\QualificationEquipement;
use App\Compta\Enum\Qualification;
use App\Compta\Enum\ReferentielComptable;
use App\Compta\Enum\TypeExploitant;
use App\Compta\Regime\SelecteurReferentielPublic;
use App\Compta\ValueObject\ParametresRegime;
use App\Organisation\Entity\Espace;
use PHPUnit\Framework\TestCase;

/**
 * Point EXPERT #1 (§4.1 spec) : `SelecteurReferentielPublic` est le **seul** endroit qui teste
 * SPIC/SPA — défaut SPA/M57 si l'équipement n'a pas de qualification déclarée.
 */
final class SelecteurReferentielPublicTest extends TestCase
{
    public function testChoisitM4SiEquipementQualifieSpic(): void
    {
        $profil = new ProfilExploitant();
        $profil->setType(TypeExploitant::RegieDirecte);
        $profil->setReferentielComptable(ReferentielComptable::M57);

        $espace = new Espace();
        $qualif = (new QualificationEquipement())->setEspace($espace)->setQualification(Qualification::Spic);

        $selecteur = new SelecteurReferentielPublic();
        self::assertSame(ReferentielComptable::M4Spic, $selecteur->choisir($profil, $qualif));
    }

    public function testChoisitM57SiEquipementQualifieSpa(): void
    {
        $profil = new ProfilExploitant();
        $espace = new Espace();
        $qualif = (new QualificationEquipement())->setEspace($espace)->setQualification(Qualification::Spa);

        $selecteur = new SelecteurReferentielPublic();
        self::assertSame(ReferentielComptable::M57, $selecteur->choisir($profil, $qualif));
    }

    public function testDefautSpaSiEquipementNonQualifie(): void
    {
        $profil = new ProfilExploitant();
        $profil->setParametresRegime(new ParametresRegime(qualificationParDefaut: Qualification::Spa));

        $selecteur = new SelecteurReferentielPublic();
        self::assertSame(ReferentielComptable::M57, $selecteur->choisir($profil, null));
    }

    public function testDefautParametrableVersSpic(): void
    {
        $profil = new ProfilExploitant();
        $profil->setParametresRegime(new ParametresRegime(qualificationParDefaut: Qualification::Spic));

        $selecteur = new SelecteurReferentielPublic();
        self::assertSame(ReferentielComptable::M4Spic, $selecteur->choisir($profil, null));
    }
}
