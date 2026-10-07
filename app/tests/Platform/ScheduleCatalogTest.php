<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use App\Platform\Scheduling\ScheduleCatalog;
use PHPUnit\Framework\TestCase;

/**
 * T8 du plan (RG-TRE-16, §0.9 de `plan-treasury-cash-alerts.md`) — `finance:treasury:verifier-seuils`
 * est enregistrée dans `ScheduleCatalog::all()`, `safeOnFirstRun = false` (effet visible au dehors : la
 * commande notifie une personne réelle), `critical = true`.
 */
final class ScheduleCatalogTest extends TestCase
{
    public function testVerifierSeuilsEnregistreeEtNonSurAutomatique(): void
    {
        $tache = (new ScheduleCatalog())->find('finance:treasury:verifier-seuils');

        self::assertNotNull($tache, 'finance:treasury:verifier-seuils doit être enregistrée dans le catalogue (RG-TRE-16).');
        self::assertFalse($tache->safeOnFirstRun, 'La commande notifie une personne réelle : jamais sûre au premier passage.');
        self::assertTrue($tache->critical);
        self::assertSame(1440, $tache->everyMinutes);
    }

    /**
     * Les deux commandes sœurs sont au catalogue depuis l'arbitrage du 15/09 (#193 : « les activer, les
     * trois »).
     *
     * ⚠ CE TEST FIGEAIT LE DÉFAUT. Il s'appelait `…ToujoursAbsentesConstatNonCorrige` et exigeait leur
     * absence (§4.6/§8 point 4 de la spec). #193 a corrigé le défaut ; le test est devenu rouge, et
     * personne ne l'a vu, la CI ne lançant pas PHPUnit. Il protège maintenant la correction.
     */
    public function testCommandesSoeursCatalogueesDepuisLArbitrageDu1509(): void
    {
        $catalogue = new ScheduleCatalog();

        $ecarts = $catalogue->find('finance:treasury:detecter-ecarts');
        self::assertNotNull($ecarts, 'finance:treasury:detecter-ecarts doit être au catalogue (#193).');
        self::assertSame(1440, $ecarts->everyMinutes);
        self::assertFalse($ecarts->safeOnFirstRun, 'Elle émet un événement par ligne non appariée : jamais sûre au premier passage.');

        $suggestions = $catalogue->find('finance:treasury:suggerer-rapprochements');
        self::assertNotNull($suggestions, 'finance:treasury:suggerer-rapprochements doit être au catalogue (#193).');
        self::assertSame(1440, $suggestions->everyMinutes);
        self::assertTrue($suggestions->safeOnFirstRun, 'Elle n\'écrit qu\'un statut interne « suggested » et ne notifie personne.');
    }
}
