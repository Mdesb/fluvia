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

    /**
     * ⚠ CE TEST LIT LA VRAIE DOC VIVANTE, PAS UN FICHIER FABRIQUÉ POUR L'OCCASION.
     *
     * Les autres cas de ce fichier écrivent leurs propres `.md` dans un répertoire temporaire : ils
     * vérifient que l'importeur fonctionne, jamais que NOS articles passent. Un front-matter mal
     * fermé, un champ `categorie` oublié, un répertoire mal nommé — rien ne le disait avant le
     * déploiement, et l'article manquait en silence dans la base de connaissance.
     *
     * ⚠ LA SECONDE ASSERTION EST CELLE QUI COMPTE. « 0 en erreur » est vrai quand on ne lit rien :
     * un répertoire entier ignoré laisserait ce test vert. On exige donc que le TOTAL traité égale
     * le nombre de fichiers sur le disque.
     *
     * Le compte se lit sur le disque et ne se code pas en dur : ajouter un article ne doit pas
     * demander de penser à incrémenter un nombre ici — sinon quelqu'un le décrémentera un jour pour
     * faire passer le test.
     */
    public function testLaVraieDocVivanteEstImportableEnEntier(): void
    {
        $racine = static::getContainer()->getParameter('kernel.project_dir') . '/docs/aide';
        self::assertDirectoryExists($racine, 'La doc vivante est la base de connaissance générique livrée à chaque client.');

        $surLeDisque = 0;
        $iterateur = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterateur as $fichier) {
            if ($fichier->isFile() && $fichier->getExtension() === 'md') {
                ++$surLeDisque;
            }
        }
        self::assertGreaterThan(0, $surLeDisque, 'Sans article, ce test serait vert sans rien mesurer.');

        // Sans `--chemin` : on emprunte le chemin par défaut, celui qu'utilise le déploiement.
        $tester = new CommandTester(static::getContainer()->get(ImporterAideCommand::class));
        $code = $tester->execute(['--dry-run' => true, '--strict' => true]);

        self::assertSame(Command::SUCCESS, $code, $tester->getDisplay());
        self::assertStringContainsString('0 en erreur', $tester->getDisplay());
        self::assertStringContainsString(
            sprintf('total %d)', $surLeDisque),
            $tester->getDisplay(),
            sprintf('%d fichier(s) sur le disque, un autre nombre traité : un article est ignoré en silence.', $surLeDisque),
        );
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
