<?php

declare(strict_types=1);

namespace App\Tests\Stay\Unit;

use App\Stay\StayModule;
use PHPUnit\Framework\TestCase;

/**
 * Contrat `ModuleManifest` pour `stay` (patron `FinanceModuleManifestTest`).
 *
 * Le test le plus important de ce fichier est `testAucunEvenementNestDeclareTantQueLeCatalogueNeLesPorte` :
 * il **fige volontairement un manque**. Tant que `claude-A` n'a pas catalogué `stay.*`, ce module ne
 * doit rien déclarer — et le jour où il les catalogue, ce test échouera, ce qui est exactement le
 * rappel voulu. Un test qui tombe quand un blocage se lève vaut mieux qu'un commentaire qu'on oublie.
 */
final class StayModuleManifestTest extends TestCase
{
    public function testManifestConstructibleSansArgument(): void
    {
        $constructeur = (new \ReflectionClass(StayModule::class))->getConstructor();

        self::assertSame(0, $constructeur?->getNumberOfRequiredParameters() ?? 0);

        $manifest = new StayModule();
        self::assertSame('stay', $manifest->id());
        self::assertSame('stay', $manifest->capability());
    }

    public function testLeSejourEstVenduDoncPorteUneCapacite(): void
    {
        // `null` désignerait un service transverse (OCR, GED) : le séjour n'en est pas un, il n'a de
        // sens que pour un exploitant qui héberge, et il doit pouvoir être vendu à l'unité.
        self::assertNotNull((new StayModule())->capability());
    }

    public function testPermissionsRespectentLeFormatModuleAction(): void
    {
        $permissions = (new StayModule())->permissions();

        // **Une quantite avant la boucle.** Sans elle, ce test passe au vert sur une liste vide :
        // il n aurait alors rien verifie tout en l affirmant. C est la meme faute qu un garde-fou
        // qui annonce « aucun defaut » sans dire combien de fichiers il a lus.
        self::assertCount(4, $permissions, 'Le manifeste doit declarer ses quatre permissions.');

        foreach ($permissions as $permission) {
            self::assertMatchesRegularExpression(
                '/^stay\.[a-z_]+$/',
                $permission,
                sprintf('Permission « %s » hors format module.action, ou hors du module (D5).', $permission),
            );
        }
    }

    public function testAucunEvenementNestDeclareTantQueLeCatalogueNeLesPorte(): void
    {
        $manifest = new StayModule();

        self::assertSame([], $manifest->eventsEmitted(), 'RG-PLAT-06 : catalogue d\'abord, déclaration ensuite.');
        self::assertSame([], $manifest->eventsConsumed(), 'On déclare ce qu\'on écoute réellement, pas ce qu\'on prévoit.');
    }

    public function testAucuneDependanceDeclareeVersUnModuleSansManifeste(): void
    {
        // RG-PLAT-07 : le registre refuse de démarrer si une dépendance nomme un module inconnu.
        // `App\Crm` n'a pas de manifeste — le lien existe dans le typage, pas dans le contrat.
        self::assertSame([], (new StayModule())->dependencies());
    }
}
