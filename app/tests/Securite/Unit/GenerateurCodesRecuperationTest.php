<?php

declare(strict_types=1);

namespace App\Tests\Securite\Unit;

use App\Securite\Service\GenerateurCodesRecuperation;
use PHPUnit\Framework\TestCase;

/**
 * §2.3 plan-backoffice.md — codes de récupération MFA : usage unique, hachés au repos.
 */
final class GenerateurCodesRecuperationTest extends TestCase
{
    public function testGenereHuitCodesUniques(): void
    {
        $generateur = new GenerateurCodesRecuperation();
        $codes = $generateur->genererCodesClair();

        self::assertCount(8, $codes);
        self::assertCount(8, array_unique($codes));
    }

    public function testConsommerUnCodeValideLeRetireEtEmpecheReutilisation(): void
    {
        $generateur = new GenerateurCodesRecuperation();
        $codesClair = $generateur->genererCodesClair();
        $hashes = $generateur->hacher($codesClair);

        $restants = $generateur->consommer($hashes, $codesClair[2]);
        self::assertNotNull($restants);
        self::assertCount(7, $restants);

        // Le code déjà consommé n'est plus accepté.
        self::assertNull($generateur->consommer($restants, $codesClair[2]));
    }

    public function testCodeInconnuRefuse(): void
    {
        $generateur = new GenerateurCodesRecuperation();
        $hashes = $generateur->hacher($generateur->genererCodesClair());

        self::assertNull($generateur->consommer($hashes, 'CODE-INEXISTANT'));
    }
}
