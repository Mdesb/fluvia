<?php

declare(strict_types=1);

namespace App\Tests\Dms\Unit;

use App\DataFixtures\SocleFixtures;
use App\Dms\DataFixtures\DmsFixtures;
use App\Dms\Entity\Document;
use App\Dms\Enum\DocumentCategory;
use App\Dms\Service\ReplaceVersionHandler;
use App\Dms\Service\UploadDocumentHandler;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * §5.1 du plan : deux remplacements de version passent par le même verrou pessimiste
 * (`LockMode::PESSIMISTIC_WRITE`). PHPUnit étant mono-processus, ce test ne reproduit pas une vraie
 * concurrence réseau (pas de deux connexions simultanées) — il exerce le même chemin de code
 * séquentiellement et vérifie l'invariant observable exigé par le cas limite spec §10 : **aucune des
 * deux écritures n'est perdue**, chaque appel produit sa propre version, numérotée séquentiellement,
 * `previousVersion` chaîné correctement, `UNIQUE(document, versionNumber)` jamais violée.
 */
final class ReplaceVersionConcurrencyTest extends KernelTestCase
{
    public function testDeuxRemplacementsSequentielsProduisentDeuxVersionsDistinctes(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();

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

        /** @var UploadDocumentHandler $uploadHandler */
        $uploadHandler = $container->get(UploadDocumentHandler::class);
        /** @var ReplaceVersionHandler $replaceHandler */
        $replaceHandler = $container->get(ReplaceVersionHandler::class);

        $document = $this->upload($uploadHandler, $etablissement, 'v1');
        self::assertSame(1, $document->getCurrentVersion()?->getVersionNumber());

        // Doctrine (identity map) renvoie la MÊME instance `Document` aux deux appels : le numéro de
        // version doit être lu immédiatement après chaque remplacement, pas après coup (sinon les
        // deux variables refléteraient l'état final, faussant l'assertion).
        $documentApresRemplacement1 = $this->replace($replaceHandler, $document->getId(), 'v2');
        $numeroApres1 = $documentApresRemplacement1->getCurrentVersion()?->getVersionNumber();
        $documentApresRemplacement2 = $this->replace($replaceHandler, $document->getId(), 'v3');
        $numeroApres2 = $documentApresRemplacement2->getCurrentVersion()?->getVersionNumber();

        self::assertSame(2, $numeroApres1);
        self::assertSame(3, $numeroApres2);

        /** @var list<\App\Dms\Entity\DocumentVersion> $versions */
        $versions = $em->getRepository(\App\Dms\Entity\DocumentVersion::class)->findBy(['document' => $document->getId()]);
        self::assertCount(3, $versions, 'Aucune écriture perdue : 3 versions distinctes (v1, v2, v3).');

        $numeros = array_map(static fn ($v) => $v->getVersionNumber(), $versions);
        sort($numeros);
        self::assertSame([1, 2, 3], $numeros);

        $v3 = $documentApresRemplacement2->getCurrentVersion();
        self::assertNotNull($v3?->getPreviousVersion());
        self::assertSame(2, $v3->getPreviousVersion()->getVersionNumber());
    }

    private function upload(UploadDocumentHandler $handler, Etablissement $etablissement, string $contenu): Document
    {
        $source = fopen('php://temp', 'r+b');
        \assert($source !== false);
        fwrite($source, $contenu);
        rewind($source);
        try {
            return $handler->upload($etablissement, DocumentCategory::Other, 'Titre', null, null, $source, 'f.pdf', 'application/pdf', null);
        } finally {
            fclose($source);
        }
    }

    private function replace(ReplaceVersionHandler $handler, \Symfony\Component\Uid\Uuid $documentId, string $contenu): Document
    {
        $source = fopen('php://temp', 'r+b');
        \assert($source !== false);
        fwrite($source, $contenu);
        rewind($source);
        try {
            return $handler->replace($documentId, $source, 'f.pdf', 'application/pdf', null);
        } finally {
            fclose($source);
        }
    }
}
