<?php

declare(strict_types=1);

namespace App\Tests\Support\Unit;

use App\Support\Entity\ArticleAide;
use App\Support\Entity\JournalImportAide;
use App\Support\Entity\VersionArticle;
use App\Support\Enum\ResultatImport;
use App\Support\Enum\StatutArticle;
use App\Support\Service\ImporteurAideService;
use App\Tests\Support\SupportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `ImporteurAideService` (US-SUP-08, RG-SUP-07/08, CA-7) : création, idempotence stricte, nouvelle
 * version + règle de republication après import, isolement d'un fichier en erreur.
 */
final class ImporteurAideServiceTest extends SupportApiTestCase
{
    private string $cheminTemp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cheminTemp = sys_get_temp_dir() . '/support_import_test_' . bin2hex(random_bytes(6));
        mkdir($this->cheminTemp . '/vente', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->supprimerRecursivement($this->cheminTemp);
        parent::tearDown();
    }

    public function testCa7FichierNouveauCreeArticleAuStatutDuFrontMatter(): void
    {
        $this->ecrireFichier('vente/encaisser-guichet.md', $this->contenuArticle('brouillon'));

        $resume = $this->service()->executer($this->cheminTemp);

        self::assertSame(1, $resume->cree);
        self::assertSame(0, $resume->erreur);

        $article = $this->entite(ArticleAide::class, ['cleImport' => 'vente/encaisser-guichet']);
        self::assertSame(StatutArticle::Brouillon, $article->getStatut());
        self::assertSame('vente', $article->getModuleLie());
    }

    public function testCa7ReExecutionSansChangementIdempotente(): void
    {
        $this->ecrireFichier('vente/encaisser-guichet.md', $this->contenuArticle('brouillon'));

        $resume1 = $this->service()->executer($this->cheminTemp);
        self::assertSame(1, $resume1->cree);

        $resume2 = $this->service()->executer($this->cheminTemp);
        self::assertSame(0, $resume2->cree);
        self::assertSame(0, $resume2->maj);
        self::assertSame(1, $resume2->inchange);

        $article = $this->entite(ArticleAide::class, ['cleImport' => 'vente/encaisser-guichet']);
        $versions = $this->em()->getRepository(VersionArticle::class)->findBy(['article' => $article->getId()]);
        self::assertCount(1, $versions, 'Aucune nouvelle version, aucun doublon.');
    }

    public function testCa7ContenuModifieCreeNouvelleVersionEtRepublicationConditionnelle(): void
    {
        $this->ecrireFichier('vente/encaisser-guichet.md', $this->contenuArticle('brouillon'));
        $this->service()->executer($this->cheminTemp);

        // Publication manuelle (simulateur de ArticlePublierProcessor) pour tester RG-SUP-08.
        $article = $this->entite(ArticleAide::class, ['cleImport' => 'vente/encaisser-guichet']);
        $premiereVersion = $this->em()->getRepository(VersionArticle::class)->findOneBy(['article' => $article->getId()]);
        $article->setStatut(StatutArticle::Publie)->setVersionPubliee($premiereVersion);
        $this->em()->flush();

        // Modification du contenu, sans mention explicite `statut: publie` (défaut brouillon) :
        // l'article publié doit repasser en brouillon (RG-SUP-08), `versionPubliee` inchangée.
        $this->ecrireFichier('vente/encaisser-guichet.md', $this->contenuArticle('brouillon', 'Corps modifié v2.'));
        $resume = $this->service()->executer($this->cheminTemp);
        self::assertSame(1, $resume->maj);

        $this->em()->clear();
        $article = $this->entite(ArticleAide::class, ['cleImport' => 'vente/encaisser-guichet']);
        self::assertSame(StatutArticle::Brouillon, $article->getStatut());
        self::assertSame((string) $premiereVersion->getId(), (string) $article->getVersionPubliee()?->getId());

        $versions = $this->em()->getRepository(VersionArticle::class)->findBy(['article' => $article->getId()]);
        self::assertCount(2, $versions);

        // Nouvelle modification, avec mention explicite `statut: publie` : republication assumée.
        $this->ecrireFichier('vente/encaisser-guichet.md', $this->contenuArticle('publie', 'Corps modifié v3, republié.'));
        $this->service()->executer($this->cheminTemp);

        $this->em()->clear();
        $article = $this->entite(ArticleAide::class, ['cleImport' => 'vente/encaisser-guichet']);
        self::assertSame(StatutArticle::Publie, $article->getStatut());
        self::assertNotSame((string) $premiereVersion->getId(), (string) $article->getVersionPubliee()?->getId());
    }

    public function testFrontMatterInvalideDansUnFichierParmiTroisIsole(): void
    {
        $this->ecrireFichier('vente/article-un.md', $this->contenuArticle('brouillon', 'Corps 1.', 'vente/article-un'));
        $this->ecrireFichier('vente/article-invalide.md', "---\ntitre: \"Sans public cible\"\ncategorie: caisse-vente\nportee: global\n---\nCorps invalide.\n");
        $this->ecrireFichier('vente/article-trois.md', $this->contenuArticle('brouillon', 'Corps 3.', 'vente/article-trois'));

        $resume = $this->service()->executer($this->cheminTemp);

        self::assertSame(2, $resume->cree);
        self::assertSame(1, $resume->erreur);

        $journalErreur = $this->em()->getRepository(JournalImportAide::class)->findOneBy(['resultat' => ResultatImport::Erreur]);
        self::assertNotNull($journalErreur);
        self::assertStringContainsString('vente/article-invalide.md', $journalErreur->getCheminFichier());
    }

    public function testDryRunNeModifieRien(): void
    {
        $this->ecrireFichier('vente/encaisser-guichet.md', $this->contenuArticle('brouillon'));

        $resume = $this->service()->executer($this->cheminTemp, true);
        self::assertSame(1, $resume->cree);

        $article = $this->em()->getRepository(ArticleAide::class)->findOneBy(['cleImport' => 'vente/encaisser-guichet']);
        self::assertNull($article, 'Le dry-run ne doit rien persister.');
    }

    private function service(): ImporteurAideService
    {
        return static::getContainer()->get(ImporteurAideService::class);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    private function contenuArticle(string $statut, string $corps = 'Corps de test.', string $cleImport = 'vente/encaisser-guichet'): string
    {
        return <<<MD
---
titre: "Encaisser une vente au guichet"
categorie: caisse-vente
publicCible: agent
portee: global
moduleLie: vente
statut: {$statut}
resume: "Étapes pour encaisser une vente au comptoir."
motsCles: [caisse, encaissement, vente]
cleImport: {$cleImport}
---
{$corps}
MD;
    }

    private function ecrireFichier(string $chemin, string $contenu): void
    {
        $cheminComplet = $this->cheminTemp . '/' . $chemin;
        @mkdir(\dirname($cheminComplet), 0777, true);
        file_put_contents($cheminComplet, $contenu);
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
