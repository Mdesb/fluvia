<?php

declare(strict_types=1);

namespace App\Tests\Finance\Unit;

use App\Platform\Module\ModuleRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * §7 point 7 du plan — vérifie que le **vrai** conteneur (tous les modules réellement tagués
 * `platform.module`, dont `App\Ocr\OcrModule` et `App\Finance\FinanceModule`) démarre sans lever
 * `\LogicException` : `FinanceModule::dependencies(): []` ne fait pas échouer
 * `ModuleRegistry::assertDependenciesAreResolved()`, tant que `stock`/`compta`/`sepa`/`personnel`/
 * `autorisation` n'implémentent pas eux-mêmes `ModuleManifest`.
 */
final class FinanceModuleRegistryBootTest extends KernelTestCase
{
    public function testRegistreDemarreSansDependanceNonResolue(): void
    {
        self::bootKernel();

        /** @var ModuleRegistry $registre */
        $registre = static::getContainer()->get(ModuleRegistry::class);

        self::assertTrue($registre->has('finance'), 'FinanceModule doit être enregistré (tag platform.module automatique).');
        self::assertSame([], $registre->get('finance')->dependencies());
        self::assertContains('supplier_invoice.recorded', $registre->get('finance')->eventsEmitted());
    }
}
