<?php

declare(strict_types=1);

namespace App\Tests\Membership\Unit;

use App\Crm\Enum\TypeClient;
use App\Membership\Regime\SubscriberRegime;
use PHPUnit\Framework\TestCase;

/**
 * Le régime par profil (#96, D113). C'est ici que le régime est « fixé » (le reproche de l'audit) :
 * le profil se déduit du payeur, et chaque protection consommateur en découle. Le validateur n'est
 * qu'un câblage par-dessus ces décisions.
 */
final class SubscriberRegimeTest extends TestCase
{
    public function testLeProfilSeDeduitDuTypeDePayeur(): void
    {
        self::assertSame(SubscriberRegime::Professional, SubscriberRegime::pour(TypeClient::Morale));
        self::assertSame(SubscriberRegime::Consumer, SubscriberRegime::pour(TypeClient::Physique));
        self::assertSame(SubscriberRegime::Consumer, SubscriberRegime::pour(null), 'défaut protecteur : consommateur');
    }

    public function testLesProtectionsConsoNeValentQuePourLeConsommateur(): void
    {
        $conso = SubscriberRegime::Consumer;
        $pro = SubscriberRegime::Professional;

        self::assertTrue($conso->retractationApplicable());
        self::assertFalse($pro->retractationApplicable());

        self::assertTrue($conso->reconductionTaciteInfoRequise());
        self::assertFalse($pro->reconductionTaciteInfoRequise());

        self::assertSame(30, $conso->preavisResiliationMaxJours());
        self::assertNull($pro->preavisResiliationMaxJours(), 'le marché fixe le préavis du professionnel');
    }

    public function testLePlafondDePreavisNeVautQuePourLeConsommateur(): void
    {
        $conso = SubscriberRegime::Consumer;

        self::assertFalse($conso->preavisResiliationDepasse(30), 'égal au plafond : accepté (borne stricte)');
        self::assertTrue($conso->preavisResiliationDepasse(31));
        self::assertFalse($conso->preavisResiliationDepasse(10));

        self::assertFalse(SubscriberRegime::Professional->preavisResiliationDepasse(90), 'professionnel : aucun plafond');
    }
}
