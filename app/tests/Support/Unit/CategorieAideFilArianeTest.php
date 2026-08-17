<?php

declare(strict_types=1);

namespace App\Tests\Support\Unit;

use App\Support\Entity\CategorieAide;
use PHPUnit\Framework\TestCase;

/** Fil d'Ariane (RG-SUP-01, US-SUP-03) : racine → … → catégorie complet, profondeur 3. */
final class CategorieAideFilArianeTest extends TestCase
{
    public function testFilArianeProfondeurTroisRetourneLeCheminRacineComplet(): void
    {
        $racine = (new CategorieAide())->setNom('Exploitation');
        $intermediaire = (new CategorieAide())->setNom('Caisse & Vente')->setParent($racine);
        $feuille = (new CategorieAide())->setNom('Encaissement guichet')->setParent($intermediaire);

        $chemin = $feuille->getFilAriane();

        self::assertCount(3, $chemin);
        self::assertSame('Exploitation', $chemin[0]['nom']);
        self::assertSame('Caisse & Vente', $chemin[1]['nom']);
        self::assertSame('Encaissement guichet', $chemin[2]['nom']);
    }

    public function testFilArianeCategorieRacineNeContientQuElleMeme(): void
    {
        $racine = (new CategorieAide())->setNom('Exploitation');

        self::assertCount(1, $racine->getFilAriane());
    }
}
