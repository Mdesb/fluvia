<?php

declare(strict_types=1);

namespace App\Tests\Dms\Unit;

use App\DataFixtures\SocleFixtures;
use App\Dms\DataFixtures\DmsFixtures;
use App\Dms\Entity\Document;
use App\Dms\Entity\DocumentVersion;
use App\Dms\Enum\DocumentCategory;
use App\Dms\Exception\DocumentEstablishmentImmutableException;
use App\Dms\Exception\DocumentVersionImmutableException;
use App\Dms\Service\UploadDocumentHandler;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * CA-7 : `preUpdate`/`preRemove` sur une `DocumentVersion` déjà persistée -> exception, via l'ORM
 * direct (pas seulement l'API). `preUpdate` sur `Document.establishment` -> exception (RG-DMS-04).
 */
final class DocumentIntegrityListenerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Document $document;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();
        $this->em = $em;

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        $container->get(SocleFixtures::class)->load($em);
        $container->get(DmsFixtures::class)->load($em);

        $etablissement = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etablissement);

        /** @var UploadDocumentHandler $handler */
        $handler = $container->get(UploadDocumentHandler::class);
        $source = fopen('php://temp', 'r+b');
        \assert($source !== false);
        fwrite($source, 'contenu initial');
        rewind($source);
        $this->document = $handler->upload($etablissement, DocumentCategory::Other, 'Titre', null, null, $source, 'f.pdf', 'application/pdf', null);
        fclose($source);
    }

    public function testPreUpdateSurVersionExistanteLeveException(): void
    {
        $version = $this->em->getRepository(DocumentVersion::class)->find($this->document->getCurrentVersion()->getId());
        self::assertInstanceOf(DocumentVersion::class, $version);

        $reflection = new \ReflectionProperty(DocumentVersion::class, 'mimeType');
        $reflection->setValue($version, 'text/plain');

        $this->expectException(DocumentVersionImmutableException::class);
        $this->em->flush();
    }

    public function testPreRemoveSurVersionExistanteLeveException(): void
    {
        $version = $this->em->getRepository(DocumentVersion::class)->find($this->document->getCurrentVersion()->getId());
        self::assertInstanceOf(DocumentVersion::class, $version);

        // Doctrine invoque `preRemove` immédiatement dans `EntityManager::remove()` (pas différé au
        // flush) — `expectException` doit donc être posé AVANT cet appel, pas avant `flush()`.
        $this->expectException(DocumentVersionImmutableException::class);
        $this->em->remove($version);
    }

    public function testPreUpdateSurEstablishmentDocumentLeveException(): void
    {
        $document = $this->em->getRepository(Document::class)->find($this->document->getId());
        self::assertInstanceOf(Document::class, $document);

        $autreEtablissement = $this->em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertInstanceOf(Etablissement::class, $autreEtablissement);
        $document->setEstablishment($autreEtablissement);

        $this->expectException(DocumentEstablishmentImmutableException::class);
        $this->em->flush();
    }

    public function testMarquerPurgeeEstToleree(): void
    {
        $version = $this->em->getRepository(DocumentVersion::class)->find($this->document->getCurrentVersion()->getId());
        self::assertInstanceOf(DocumentVersion::class, $version);
        self::assertNull($version->getPurgedAt());

        $version->markPurged(new \DateTimeImmutable());
        $this->em->flush(); // ne doit PAS lever — seule transition tolérée (plan §14 pt.10).

        $this->em->clear();
        $rechargee = $this->em->getRepository(DocumentVersion::class)->find($version->getId());
        self::assertInstanceOf(DocumentVersion::class, $rechargee);
        self::assertNotNull($rechargee->getPurgedAt());
    }
}
