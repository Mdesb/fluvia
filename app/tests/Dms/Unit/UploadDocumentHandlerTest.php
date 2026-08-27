<?php

declare(strict_types=1);

namespace App\Tests\Dms\Unit;

use App\Tests\SchemaDuHarnais;
use App\DataFixtures\SocleFixtures;
use App\Dms\DataFixtures\DmsFixtures;
use App\Dms\Entity\DocumentVersion;
use App\Dms\Enum\DocumentCategory;
use App\Dms\Service\UploadDocumentHandler;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * §0.1 du plan : `Document.currentVersion` n'est **jamais** observable `null` immédiatement après le
 * retour de `UploadDocumentHandler::upload()` — défense en profondeur en complément de la
 * transaction unique. Vérifie aussi `fileHash` (sha256 du contenu) et l'auto-assignation de rétention
 * (RG-DMS-11).
 */
final class UploadDocumentHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UploadDocumentHandler $handler;
    private Etablissement $etablissement;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();
        $this->em = $em;

        // Le schéma est construit UNE FOIS par processus, puis vidé entre les tests. Le faire
        // détruire et reconstruire par chaque `setUp()` coûtait ~10 s par test — six heures sur
        // la suite complète, et donc une suite que personne ne lançait.
        SchemaDuHarnais::reinitialiser($em);

        $container->get(SocleFixtures::class)->load($em);
        $container->get(DmsFixtures::class)->load($em);

        /** @var UploadDocumentHandler $handler */
        $handler = $container->get(UploadDocumentHandler::class);
        $this->handler = $handler;

        $etablissement = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etablissement);
        $this->etablissement = $etablissement;
    }

    public function testCurrentVersionJamaisNullApresRetour(): void
    {
        $document = $this->upload(DocumentCategory::Other, 'contenu quelconque');

        self::assertNotNull($document->getCurrentVersion(), 'Document.currentVersion doit toujours être renseigné en sortie (§0.1).');
        self::assertInstanceOf(DocumentVersion::class, $document->getCurrentVersion());
        self::assertSame(1, $document->getCurrentVersion()->getVersionNumber());
    }

    public function testFileHashEstLeSha256DuContenu(): void
    {
        $contenu = 'contenu déterministe pour vérifier le hash';
        $document = $this->upload(DocumentCategory::Other, $contenu);

        self::assertSame(hash('sha256', $contenu), $document->getCurrentVersion()?->getFileHash());
    }

    public function testRetentionAutoAssigneeSiPolitiqueParDefautCorrespond(): void
    {
        $document = $this->upload(DocumentCategory::AccountingPiece, 'pièce comptable');

        self::assertNotNull($document->getRetentionPolicy());
        self::assertSame(DmsFixtures::POLICY_ACCOUNTING, $document->getRetentionPolicy()?->getCode());
        self::assertNotNull($document->getRetainUntil());
    }

    public function testRetentionNoneSiAucunePolitiqueParDefaut(): void
    {
        $document = $this->upload(DocumentCategory::MarketingAsset, 'visuel marketing');

        self::assertNull($document->getRetentionPolicy());
        self::assertNull($document->getRetainUntil());
        self::assertSame('none', $document->getRetentionStatus());
    }

    private function upload(DocumentCategory $category, string $contenu): \App\Dms\Entity\Document
    {
        $source = fopen('php://temp', 'r+b');
        \assert($source !== false);
        fwrite($source, $contenu);
        rewind($source);

        try {
            return $this->handler->upload(
                $this->etablissement,
                $category,
                'Titre de test',
                null,
                null,
                $source,
                'fichier.pdf',
                'application/pdf',
                null,
            );
        } finally {
            fclose($source);
        }
    }
}
