<?php

declare(strict_types=1);

namespace App\Tests\Support\Unit;

use App\Support\Command\ImporterAideCommand;
use App\Support\Entity\ArticleAide;
use App\Tests\Support\SupportApiTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** `support:importer-aide` (§5.2 plan-support.md) : options `--dry-run` et `--strict`. */
final class ImporterAideCommandTest extends SupportApiTestCase
{
    private string $cheminTemp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cheminTemp = sys_get_temp_dir() . '/support_cmd_test_' . bin2hex(random_bytes(6));
        mkdir($this->cheminTemp . '/vente', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->supprimerRecursivement($this->cheminTemp);
        parent::tearDown();
    }

    public function testDryRunNePersistePasEtRenvoieCodeSucces(): void
    {
        file_put_contents($this->cheminTemp . '/vente/article.md', $this->contenu());

        $tester = new CommandTester(static::getContainer()->get(ImporterAideCommand::class));
        $code = $tester->execute(['--chemin' => $this->cheminTemp, '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('1 créé', $tester->getDisplay());

        $em = static::getContainer()->get('doctrine')->getManager();
        self::assertNull($em->getRepository(ArticleAide::class)->findOneBy(['cleImport' => 'vente/article']));
    }

    public function testStrictRenvoieCodeNonZeroSiFichierEnErreur(): void
    {
        file_put_contents($this->cheminTemp . '/vente/invalide.md', "---\ntitre: \"Sans champs requis\"\n---\nCorps.\n");

        $tester = new CommandTester(static::getContainer()->get(ImporterAideCommand::class));
        $code = $tester->execute(['--chemin' => $this->cheminTemp, '--strict' => true]);

        self::assertSame(Command::FAILURE, $code);
    }

    public function testSansStrictCodeSuccesMemeAvecUneErreur(): void
    {
        file_put_contents($this->cheminTemp . '/vente/invalide.md', "---\ntitre: \"Sans champs requis\"\n---\nCorps.\n");

        $tester = new CommandTester(static::getContainer()->get(ImporterAideCommand::class));
        $code = $tester->execute(['--chemin' => $this->cheminTemp]);

        self::assertSame(Command::SUCCESS, $code);
    }

    private function contenu(): string
    {
        return <<<MD
---
titre: "Article de test commande"
categorie: caisse-vente
publicCible: agent
portee: global
moduleLie: vente
statut: brouillon
cleImport: vente/article
---
Corps de test.
MD;
    }

    private function supprimerRecursivement(string $chemin): void
    {
        if (!is_dir($chemin)) {
            return;
        }
        $items = scandir($chemin) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $cheminItem = $chemin . '/' . $item;
            if (is_dir($cheminItem)) {
                $this->supprimerRecursivement($cheminItem);
            } else {
                @unlink($cheminItem);
            }
        }
        @rmdir($chemin);
    }
}
