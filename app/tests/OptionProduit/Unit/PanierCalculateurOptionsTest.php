<?php

declare(strict_types=1);

namespace App\Tests\OptionProduit\Unit;

use App\Vente\Entity\LigneVente;
use App\Vente\Enum\RemiseType;
use App\Vente\Service\PanierCalculateur;
use PHPUnit\Framework\TestCase;

/**
 * §2.3 du plan — intégration de `LigneVente.impactOptionsUnitaire` dans le calcul de
 * `PanierCalculateur::recalculerLigne()` : brut = (prixUnitaire + impactOptionsUnitaire) × quantité,
 * non-régression garantie par la valeur par défaut '0.00' (App\OptionProduit).
 */
final class PanierCalculateurOptionsTest extends TestCase
{
    public function testRecalculerLigneIntegreImpactOptions(): void
    {
        $calc = new PanierCalculateur();

        $ligne = (new LigneVente())->setPrixUnitaire('20.00')->setQuantite(1)->setImpactOptionsUnitaire('4.00');
        $calc->recalculerLigne($ligne);

        self::assertSame('24.00', $ligne->getMontantLigne());
    }

    public function testRecalculerLigneOptionsEtRemiseCumulees(): void
    {
        $calc = new PanierCalculateur();

        // Brut = (20,00 + 4,00) = 24,00 ; remise 10% = 2,40 ; montant = 21,60.
        $ligne = (new LigneVente())
            ->setPrixUnitaire('20.00')
            ->setQuantite(1)
            ->setImpactOptionsUnitaire('4.00')
            ->setRemiseLigne('10.00')
            ->setRemiseType(RemiseType::Pourcentage);
        $calc->recalculerLigne($ligne);

        self::assertSame('21.60', $ligne->getMontantLigne());
    }

    public function testImpactZeroParDefautCalculIdentiqueExistant(): void
    {
        $calc = new PanierCalculateur();

        // Aucune option (impactOptionsUnitaire par défaut '0.00') : calcul strictement inchangé.
        $ligne = (new LigneVente())->setPrixUnitaire('5.50')->setQuantite(2);
        $calc->recalculerLigne($ligne);

        self::assertSame('0.00', $ligne->getImpactOptionsUnitaire());
        self::assertSame('11.00', $ligne->getMontantLigne());
    }
}
