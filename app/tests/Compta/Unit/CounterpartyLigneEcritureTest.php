<?php

declare(strict_types=1);

namespace App\Tests\Compta\Unit;

use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\LigneEcriture;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Compta\Enum\SensCompte;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * RG-M6-13 : le compte auxiliaire / tiers sur `LigneEcriture` est une extension additive nullable —
 * une ligne sans tiers reste valide (aucune contrainte bloquante), une ligne avec tiers porte
 * correctement les 3 champs.
 */
final class CounterpartyLigneEcritureTest extends TestCase
{
    public function testLigneSansTiersResteValide(): void
    {
        $ligne = $this->ligneDeBase();

        self::assertNull($ligne->getCounterpartyType());
        self::assertNull($ligne->getCounterpartyId());
        self::assertNull($ligne->getCounterpartyLabel());
    }

    public function testLigneAvecTiersPorteLesTroisChamps(): void
    {
        $ligne = $this->ligneDeBase();
        $id = Uuid::v4();

        $ligne->setCounterpartyType('stock_fournisseur');
        $ligne->setCounterpartyId($id);
        $ligne->setCounterpartyLabel('Fournisseur Piscine SARL');

        self::assertSame('stock_fournisseur', $ligne->getCounterpartyType());
        self::assertTrue($id->equals($ligne->getCounterpartyId()));
        self::assertSame('Fournisseur Piscine SARL', $ligne->getCounterpartyLabel());
    }

    private function ligneDeBase(): LigneEcriture
    {
        $profil = new ProfilExploitant();
        $compte = (new CompteComptable())->setProfilExploitant($profil)->setNumero('401000')->setLibelle('Fournisseurs')->setSens(SensCompte::Credit);
        $taux = (new TauxTva())->setProfilExploitant($profil)->setTaux('20.00')->setLibelle('Taux normal 20 %');

        return (new LigneEcriture())->setCompte($compte)->setCreditCentimes(1000)->setTauxTva($taux);
    }
}
