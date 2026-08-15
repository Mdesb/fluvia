<?php

declare(strict_types=1);

namespace App\Tests\Fonctionnalite\Unit;

use App\Fonctionnalite\Enum\CapaciteCode;
use App\Fonctionnalite\Service\CatalogueCapacites;
use PHPUnit\Framework\TestCase;

/**
 * Registre des capacités (`GET /fonctionnalites/catalogue`) : couvre le jeu minimal imposé par la
 * spec (12 codes) et la validation des codes inconnus.
 */
final class CatalogueCapacitesTest extends TestCase
{
    private const CODES_MINIMUM = [
        'controle_acces', 'reservation', 'no_show', 'recouvrement', 'sepa', 'porte_monnaie',
        'casiers', 'location_materiel', 'poss', 'acces_nocturne', 'encadrants', 'boutique_en_ligne',
    ];

    public function testCatalogueContientAuMoinsLesDouzeCapacitesImposeesParLaSpec(): void
    {
        $catalogue = new CatalogueCapacites();
        $codes = array_map(static fn ($d) => $d->code, $catalogue->toutes());

        foreach (self::CODES_MINIMUM as $codeAttendu) {
            self::assertContains($codeAttendu, $codes);
        }
    }

    public function testChaqueDescripteurPorteUnLibelleUneDescriptionEtUneCategorieNonVides(): void
    {
        $catalogue = new CatalogueCapacites();
        foreach ($catalogue->toutes() as $descripteur) {
            self::assertNotSame('', $descripteur->libelle);
            self::assertNotSame('', $descripteur->description);
            self::assertNotSame('', $descripteur->categorie);
        }
    }

    public function testExisteEtTrouveCoherentsAvecLenumCapaciteCode(): void
    {
        $catalogue = new CatalogueCapacites();

        foreach (CapaciteCode::cases() as $case) {
            self::assertTrue($catalogue->existe($case->value));
            self::assertNotNull($catalogue->trouve($case->value));
            self::assertSame($case->value, $catalogue->trouve($case->value)?->code);
        }

        self::assertFalse($catalogue->existe('capacite_inconnue'));
        self::assertNull($catalogue->trouve('capacite_inconnue'));
    }
}
