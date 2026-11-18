<?php

declare(strict_types=1);

namespace App\Tests\Platform\Unit;

use App\Caisse\Entity\PointDeVente;
use App\Compta\Entity\Journal;
use App\Compta\Entity\ProfilExploitant;
use App\Platform\Nf525\SealChainRef;
use App\Platform\Nf525\SealChainType;
use PHPUnit\Framework\TestCase;

/** Identité d'une chaîne pour l'alerte NF525 : clé stable et distincte, libellé public, clé ≤ 100. */
final class SealChainRefTest extends TestCase
{
    private const UUID_PATTERN = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i';

    private static function pointOfSale(string $label): PointDeVente
    {
        return (new PointDeVente())->setLibelle($label);
    }

    private static function journal(ProfilExploitant $profile, string $code): Journal
    {
        return (new Journal())->setProfilExploitant($profile)->setCode($code)->setLibelle('Journal ' . $code);
    }

    public function testKeyIsStableAndDistinctPerChain(): void
    {
        $a = self::pointOfSale('Caisse piscine');
        $b = self::pointOfSale('Caisse piscine');

        self::assertSame(SealChainRef::forSales($a)->key(), SealChainRef::forSales($a)->key());
        self::assertNotSame(SealChainRef::forSales($a)->key(), SealChainRef::forSales($b)->key(), 'deux points de vente, même libellé : deux clés');
        self::assertSame(SealChainType::Sales, SealChainRef::forSales($a)->type);
    }

    public function testTwoJournalsOfTheSameProfileHaveTwoKeys(): void
    {
        $profile = new ProfilExploitant();
        $ventes = self::journal($profile, 'VE');
        $banque = self::journal($profile, 'BQ');

        $refVentes = SealChainRef::forAccounting($profile, $ventes);
        $refBanque = SealChainRef::forAccounting($profile, $banque);

        self::assertNotSame($refVentes->key(), $refBanque->key());
        self::assertSame($refVentes->key(), SealChainRef::forAccounting($profile, $ventes)->key());
        self::assertSame(SealChainType::Accounting, $refVentes->type);
        self::assertEquals($ventes->getId(), $refVentes->journalId);
        self::assertEquals($profile->getId(), $refVentes->operatorProfileId);
    }

    public function testInvoicingKeyIsPerProfileAndDiffersFromAccounting(): void
    {
        $p1 = new ProfilExploitant();
        $p2 = new ProfilExploitant();

        self::assertNotSame(SealChainRef::forInvoicing($p1)->key(), SealChainRef::forInvoicing($p2)->key());
        self::assertNotSame(SealChainRef::forInvoicing($p1)->key(), SealChainRef::forAccounting($p1, self::journal($p1, 'VE'))->key());
        self::assertSame(SealChainType::Invoicing, SealChainRef::forInvoicing($p1)->type);
    }

    public function testPublicLabelsCarryNoUuidNorVariableName(): void
    {
        $profile = new ProfilExploitant();
        $refs = [
            SealChainRef::forSales(self::pointOfSale('Bassin')),
            SealChainRef::forAccounting($profile, self::journal($profile, 'VE')),
            SealChainRef::forInvoicing($profile),
        ];

        foreach ($refs as $ref) {
            self::assertSame(0, preg_match(self::UUID_PATTERN, $ref->label()), $ref->label());
            self::assertStringNotContainsString('NF525_', $ref->label());
            self::assertNotSame('', $ref->label());
            // Témoin positif : la clé, elle, porte bien les identifiants (sinon le test ci-dessus ne prouve rien).
            self::assertSame(1, preg_match(self::UUID_PATTERN, $ref->key()), $ref->key());
        }
        self::assertSame('Caisse Bassin', $refs[0]->label());
        self::assertSame('Comptabilité — journal VE', $refs[1]->label());
        self::assertSame('Facturation', $refs[2]->label());
    }

    public function testEmptyLabelOrCodeLeavesNoTrailingSpace(): void
    {
        $profile = new ProfilExploitant();

        self::assertSame('Caisse', SealChainRef::forSales(self::pointOfSale(''))->label());
        self::assertSame('Caisse', SealChainRef::forSales(self::pointOfSale("  \t "))->label());
        self::assertSame('Comptabilité', SealChainRef::forAccounting($profile, self::journal($profile, ' '))->label());
        // Témoin : un libellé renseigné est bien repris, et rogné.
        self::assertSame('Caisse Bassin', SealChainRef::forSales(self::pointOfSale(' Bassin '))->label());
    }

    public function testKeyNeverExceedsOneHundredCharacters(): void
    {
        $profile = new ProfilExploitant();
        $longest = SealChainRef::forAccounting($profile, self::journal($profile, 'ABCDEFGH'));

        self::assertLessThanOrEqual(100, strlen($longest->key()));
        self::assertLessThanOrEqual(100, strlen(SealChainRef::forSales(self::pointOfSale(str_repeat('é', 500)))->key()));
        self::assertLessThanOrEqual(160, mb_strlen(SealChainRef::forSales(self::pointOfSale(str_repeat('é', 500)))->label()));
    }
}
