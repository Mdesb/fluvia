<?php

declare(strict_types=1);

namespace App\Tests\Vente\Unit;

use App\Vente\Entity\LigneVente;
use App\Vente\Entity\Vente;
use App\Vente\Service\PanierCalculateur;
use App\Vente\Service\SaleVatBreakdown;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * LA VENTILATION PAR TAUX — l'arithmétique que le papier doit porter.
 *
 * Trois propriétés comptent plus que les valeurs : la base s'extrait du TTC (les prix du comptoir
 * sont TTC, `LigneVenteProjectionDto::$montantTtcCentimes` fait autorité), `base + TVA` retombe
 * TOUJOURS sur le TTC au centime, et une ligne sans taux n'est jamais rangée dans un groupe « 0 % ».
 */
final class SaleVatBreakdownTest extends TestCase
{
    /** 45,00 TTC à 10 % : base 40,91, TVA 4,09 — et la somme retombe juste. */
    public function testUnSeulTauxExtraitLaBaseDuTtc(): void
    {
        $resultat = $this->ventiler([['45.00', '10.00']]);

        self::assertSame([[
            'rate' => '10.00',
            'netAmount' => '40.91',
            'vatAmount' => '4.09',
            'grossAmount' => '45.00',
        ]], $resultat['breakdown']);
        self::assertTrue($resultat['complete']);
    }

    /**
     * ⚠ LE SENS DU CALCUL, ÉPINGLÉ.
     *
     * Si la base était le prix affiché et la TVA ajoutée par-dessus, 45,00 à 10 % donnerait un TTC de
     * 49,50. Le client a payé 45,00. Ce test tombe si quelqu'un inverse le sens.
     */
    public function testLeTotalTtcResteCeQueLeClientAPaye(): void
    {
        $resultat = $this->ventiler([['45.00', '10.00']]);

        self::assertSame('45.00', $resultat['breakdown'][0]['grossAmount']);
    }

    /** Deux taux, deux groupes, triés — et chacun se referme sur son propre TTC. */
    public function testPlusieursTauxSontGroupesEtTries(): void
    {
        $resultat = $this->ventiler([
            ['20.00', '20.00'],
            ['10.00', '5.50'],
            ['30.00', '20.00'],
        ]);

        self::assertCount(2, $resultat['breakdown']);
        self::assertSame('5.50', $resultat['breakdown'][0]['rate'], 'Trie par taux croissant.');
        self::assertSame('20.00', $resultat['breakdown'][1]['rate']);
        self::assertSame('50.00', $resultat['breakdown'][1]['grossAmount'], 'Les deux lignes a 20 % fusionnent.');
    }

    /** « 10.0 » et « 10.00 » sont le même taux : un seul groupe, pas deux lignes sur le papier. */
    public function testLeTauxEstNormaliseAvantDeGrouper(): void
    {
        $resultat = $this->ventiler([['10.00', '10.0'], ['10.00', '10.00']]);

        self::assertCount(1, $resultat['breakdown']);
        self::assertSame('20.00', $resultat['breakdown'][0]['grossAmount']);
    }

    /**
     * LA PROPRIÉTÉ QUI COMPTE : base + TVA = TTC, toujours.
     *
     * Arrondir la base et la TVA séparément les ferait diverger d'un centime sur certains montants,
     * et le client lirait trois nombres dont deux ne s'additionnent pas. On balaie donc une plage de
     * montants plutôt que d'en choisir un qui arrange.
     */
    public function testBasePlusTvaRetombeToujoursSurLeTtc(): void
    {
        foreach (['0.01', '0.02', '0.99', '1.00', '3.33', '7.77', '19.99', '45.00', '123.45'] as $ttc) {
            foreach (['5.50', '10.00', '20.00'] as $taux) {
                $groupe = $this->ventiler([[$ttc, $taux]])['breakdown'][0];
                $somme = number_format(
                    ((float) $groupe['netAmount']) + ((float) $groupe['vatAmount']),
                    2,
                    '.',
                    '',
                );
                self::assertSame($ttc, $somme, sprintf('%s TTC a %s %%', $ttc, $taux));
            }
        }
    }

    /**
     * ⚠ LE TEST QUI COMPTE LE PLUS : une ligne sans taux n'est PAS une ligne a 0 %.
     *
     * La ranger dans un groupe zero produirait une ventilation qui a l'air complete et qui est
     * fausse. Elle est comptee a part, et `complete` dit non — c'est ce qui permettra au papier
     * d'ecrire << ventilation incomplete >> plutot que de mentir par omission.
     */
    public function testUneLigneSansTauxEstComptseAPartEtRendLaVentilationIncomplete(): void
    {
        $resultat = $this->ventiler([['45.00', '10.00'], ['12.00', null]]);

        self::assertCount(1, $resultat['breakdown'], 'Aucun groupe fantome a 0 %.');
        self::assertSame(1, $resultat['withoutRate']['lines']);
        self::assertSame('12.00', $resultat['withoutRate']['grossAmount']);
        self::assertFalse($resultat['complete']);
    }

    /** Le temoin de ce que le mecanisme EPARGNE : tout ventile, rien a signaler. */
    public function testUneVenteEntierementVentileeSeDeclareComplete(): void
    {
        $resultat = $this->ventiler([['45.00', '10.00'], ['12.00', '20.00']]);

        self::assertTrue($resultat['complete']);
        self::assertSame(0, $resultat['withoutRate']['lines']);
        self::assertSame('0.00', $resultat['withoutRate']['grossAmount']);
    }

    /**
     * @param list<array{0: string, 1: ?string}> $lignes montant TTC, taux
     *
     * @return array{breakdown: list<array{rate: string, netAmount: string, vatAmount: string, grossAmount: string}>,
     *     withoutRate: array{lines: int, grossAmount: string}, complete: bool}
     */
    private function ventiler(array $lignes): array
    {
        $vente = new Vente();
        foreach ($lignes as [$montant, $taux]) {
            $ligne = new LigneVente();
            $ligne->setProduit(Uuid::v4());
            $ligne->setMontantLigne($montant);
            $ligne->setTauxTva($taux);
            $vente->addLigne($ligne);
        }

        return (new SaleVatBreakdown(new PanierCalculateur()))->of($vente);
    }
}
