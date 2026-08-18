<?php

declare(strict_types=1);

namespace App\Tests\Autorisation\Unit;

use App\Vente\Service\ContrePassationHandler;
use PHPUnit\Framework\TestCase;

/**
 * Contrôle statique (§0 n°2 plan, non-régression M2) : `App\Vente\Service\ContrePassationHandler`
 * n'est pas modifié par ce lot — aucune dépendance vers `App\Autorisation` (constructeur), aucun
 * couplage inverse M2 → module transverse au niveau du service métier. Le point d'intégration réel
 * (`ServiceAutorisation::evaluer()`) est porté exclusivement par les Processors
 * (`AnnulerVenteProcessor`/`RembourserVenteProcessor`), jamais par ce handler.
 */
final class ArchitectureContrePassationTest extends TestCase
{
    public function testAucuneDependanceVersAutorisationDansConstructeur(): void
    {
        $reflection = new \ReflectionClass(ContrePassationHandler::class);
        $constructeur = $reflection->getConstructor();
        self::assertNotNull($constructeur);

        foreach ($constructeur->getParameters() as $parametre) {
            $type = $parametre->getType();
            $nomType = $type instanceof \ReflectionNamedType ? $type->getName() : (string) $type;
            self::assertStringNotContainsString(
                'App\\Autorisation',
                $nomType,
                sprintf('Le paramètre « %s » de ContrePassationHandler ne doit référencer aucune classe App\\Autorisation.', $parametre->getName())
            );
        }
    }

    public function testAucuneReferenceTextuelleAAutorisationDansLeFichierSource(): void
    {
        $chemin = (new \ReflectionClass(ContrePassationHandler::class))->getFileName();
        self::assertIsString($chemin);
        $source = file_get_contents($chemin);
        self::assertIsString($source);

        self::assertStringNotContainsString('App\\Autorisation', $source);
        self::assertStringNotContainsString('ServiceAutorisation', $source);
    }
}
