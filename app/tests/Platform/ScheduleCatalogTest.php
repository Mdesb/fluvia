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

    /** §4.6/§8 point 4 de la spec — constat repris, non corrigé par ce lot : les commandes sœurs restent absentes. */
    public function testCommandesSoeursToujoursAbsentesConstatNonCorrige(): void
    {
        $catalogue = new ScheduleCatalog();

        self::assertNull($catalogue->find('finance:treasury:detecter-ecarts'));
        self::assertNull($catalogue->find('finance:treasury:suggerer-rapprochements'));
    }
}
