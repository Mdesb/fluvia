<?php

declare(strict_types=1);

namespace App\Tests\Offre\Unit;

use App\Offre\Entity\CarteMultiEntrees;
use App\Offre\Enum\RechargeValidityMode;
use PHPUnit\Framework\TestCase;

/**
 * CQ-7 / D26 — le paramètre de validité après recharge, côté offre.
 *
 * Ce que ces tests verrouillent, c'est le **défaut livré** : D26 dit « la prolongation est le
 * comportement livré, l'exploitant peut le désactiver », et un défaut qui n'est pas testé se
 * renverse au premier refactor sans que personne ne s'en aperçoive.
 */
final class RechargeValidityModeTest extends TestCase
{
    /** D26 — prolongation par défaut, sans rien avoir à configurer. */
    public function testLeDefautLivreEstLaProlongation(): void
    {
        $carte = new CarteMultiEntrees();

        self::assertSame(RechargeValidityMode::Extend, $carte->getRechargeValidityMode());
        self::assertFalse($carte->keepsValidityOnRecharge(), 'Par défaut, une recharge prolonge.');
    }

    /** L'option de D26 : la validité d'origine tient. */
    public function testConserverBasculeLePredicat(): void
    {
        $carte = (new CarteMultiEntrees())->setRechargeValidityMode(RechargeValidityMode::Keep);

        self::assertTrue($carte->keepsValidityOnRecharge());
    }

    /**
     * Deux valeurs, pas trois. Les deux garde-fous anti-grignotage évoqués par D26 (minimum de
     * recharge, plafond de prolongations cumulées) ne sont **pas** des modes : D26 dit
     * explicitement qu'on les ajoutera sur constat. Ce test est là pour que leur ajout soit une
     * décision, pas un glissement.
     */
    public function testDeuxModesEtPasDavantage(): void
    {
        self::assertSame(['extend', 'keep'], array_column(RechargeValidityMode::cases(), 'value'));
    }
}
