<?php

declare(strict_types=1);

namespace App\Tests\I18n;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Les gabarits de documents reçoivent la langue (`locale`) sans être encore traduits : elle se lit
 * dans `<html lang>`, et son absence retombe sur la langue source.
 */
final class DocumentLocaleTest extends KernelTestCase
{
    public function testTemplateCarriesTheLocaleItReceives(): void
    {
        $twig = static::getContainer()->get(Environment::class);

        self::assertStringContainsString('<html lang="es">', $twig->render('boutique/email/relance_panier.html.twig', ['nbLignes' => 1, 'locale' => 'es']));
        self::assertStringContainsString('<html lang="fr">', $twig->render('boutique/email/relance_panier.html.twig', ['nbLignes' => 1]));
    }
}
