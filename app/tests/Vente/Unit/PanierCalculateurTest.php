<?php

declare(strict_types=1);

namespace App\Tests\Vente\Unit;

use App\Vente\Entity\LigneVente;
use App\Vente\Entity\Paiement;
use App\Vente\Entity\Vente;
use App\Vente\Enum\RemiseType;
use App\Vente\Service\PanierCalculateur;
use PHPUnit\Framework\TestCase;

/**
 * Recalcul du panier (CA-4/8/9) : montant de ligne (remise + promo), total, reste dû et imputation
 * du rendu de monnaie.
 */
final class PanierCalculateurTest extends TestCase
{
    public function testMontantLigneAvecRemiseEtPromo(): void
    {
        $calc = new PanierCalculateur();

        $ligne = (new LigneVente())->setPrixUnitaire('5.50')->setQuantite(2);
        $calc->recalculerLigne($ligne);
        self::assertSame('11.00', $ligne->getMontantLigne());

        // Remise 10 % : 11,00 − 1,10 = 9,90.
        $ligne->setRemiseLigne('10.00')->setRemiseType(RemiseType::Pourcentage);
        $calc->recalculerLigne($ligne);
        self::assertSame('9.90', $ligne->getMontantLigne());

        // Promotion supplémentaire 1 € (montant) : 9,90 − 1,00 = 8,90.
        $ligne->setPromotionsAppliquees([['type' => 'montant', 'valeur' => '1.00']]);
        $calc->recalculerLigne($ligne);
        self::assertSame('8.90', $ligne->getMontantLigne());
    }

    public function testResteDuEtImputationDuRendu(): void
    {
        $calc = new PanierCalculateur();

        $vente = new Vente();
        $ligne = (new LigneVente())->setPrixUnitaire('45.00')->setQuantite(1);
        $calc->recalculerLigne($ligne);
        $vente->addLigne($ligne);
        $calc->recalculerVente($vente);
        self::assertSame('45.00', $vente->getTotal());
        self::assertSame('45.00', $vente->getResteAPayer());

        // Paiement espèces 50 avec rendu 5 → imputé 45 → reste 0 (le rendu n'entame pas le dû).
        $vente->addPaiement((new Paiement())->setMoyenCode('especes')->setMontant('50.00')->setRendu('5.00'));
        $calc->recalculerVente($vente);
        self::assertSame('0.00', $vente->getResteAPayer());
    }
}
