<?php

declare(strict_types=1);

namespace App\Tests\Dms\Api;

use App\DataFixtures\SocleFixtures;
use App\Dms\Entity\Document;
use App\Dms\Entity\DocumentPublicLink;
use App\Dms\Enum\DocumentCategory;
use App\Dms\Enum\DocumentStatus;
use App\Dms\Service\PublicLinkTokenGenerator;
use App\Dms\Service\ReplaceVersionHandler;
use App\Dms\Service\UploadDocumentHandler;
use App\Organisation\Entity\Etablissement;
use App\Platform\Event\DomainEvent;
use App\Securite\Entity\Utilisateur;
use App\Tests\Dms\DmsApiTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * CA-3 : `expiresAt` dépassé -> échec même jeton syntaxiquement valide. CA-13 : lien épinglé sur v1,
 * document remplacé en v2 -> sert toujours v1 inchangée. CA-14 : document `status = deleted` avec lien
 * actif non révoqué -> échec. Incrémente `accessCount`/`lastAccessedAt`, aucun événement de domaine
 * émis par accès (RG-DMS-10).
 */
final class PublicDownloadControllerTest extends DmsApiTestCase
{
    public function testLienExpireEchoueMemeAvecJetonSyntaxiquementValide(): void
    {
        [$document, $token, ] = $this->createPublicLink('contenu', expiresAt: new \DateTimeImmutable('-1 minute'));
        unset($document);

        $client = static::createClient();
        $client->request('GET', '/dms/public/' . $token);
        self::assertResponseStatusCodeSame(404, (string) $client->getResponse()->getContent(false));
    }

    public function testLienEpingleSurV1ContinueDeServirV1ApresRemplacement(): void
    {
        [$document, $token, $lien] = $this->createPublicLink('contenu v1', expiresAt: new \DateTimeImmutable('+7 days'));

        /** @var ReplaceVersionHandler $replaceHandler */
        $replaceHandler = static::getContainer()->get(ReplaceVersionHandler::class);
        $source = fopen('php://temp', 'r+b');
        \assert($source !== false);
        fwrite($source, 'contenu v2');
        rewind($source);
        $replaceHandler->replace($document->getId(), $source, 'v2.pdf', 'application/pdf', null);
        fclose($source);

        $client = static::createClient();
        $client->request('GET', '/dms/public/' . $token);
        self::assertResponseIsSuccessful();
        self::assertSame('contenu v1', $client->getResponse()->getContent(), 'RG-DMS-20/CA-13 : le lien reste épinglé à v1, jamais currentVersion.');

        $em = $this->em();
        $em->clear();
        $lienRecharge = $em->getRepository(DocumentPublicLink::class)->find($lien->getId());
        self::assertNotNull($lienRecharge);
        self::assertSame(1, $lienRecharge->getAccessCount());
        self::assertNotNull($lienRecharge->getLastAccessedAt());
    }

    public function testDocumentSupprimeInvalideLUsageDesLiensNonRevoques(): void
    {
        [$document, $token, ] = $this->createPublicLink('contenu', expiresAt: new \DateTimeImmutable('+7 days'));

        $em = $this->em();
        $documentRecharge = $em->getRepository(Document::class)->find($document->getId());
        self::assertNotNull($documentRecharge);
        $documentRecharge->setStatus(DocumentStatus::Deleted);
        $documentRecharge->setDeletedAt(new \DateTimeImmutable());
        $em->flush();

        $client = static::createClient();
        $client->request('GET', '/dms/public/' . $token);
        self::assertResponseStatusCodeSame(404, 'CA-14 : document supprimé -> lien inutilisable même non révoqué. Corps : ' . $client->getResponse()->getContent(false));
    }

    public function testAccesPublicNEmetAucunEvenementDeDomaine(): void
    {
        [, $token, ] = $this->createPublicLink('contenu', expiresAt: new \DateTimeImmutable('+7 days'));

        $client = static::createClient();
        $client->disableReboot(); // le listener capturé doit rester sur le même conteneur que la requête.
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::getContainer()->get(EventDispatcherInterface::class);
        $captures = [];
        $listener = static function (DomainEvent $event) use (&$captures): void {
            $captures[] = $event;
        };
        foreach (['document.stored', 'document.version_added', 'document.retention_set', 'document.deletion_refused', 'document.deleted', 'document.purged', 'document.public_link_issued', 'document.public_link_revoked'] as $nom) {
            $dispatcher->addListener($nom, $listener);
        }

        $client->request('GET', '/dms/public/' . $token);
        self::assertResponseIsSuccessful();

        self::assertSame([], $captures, 'RG-DMS-10 : aucun événement de domaine émis par accès public individuel.');
    }

    /** @return array{0: Document, 1: string, 2: DocumentPublicLink} document, jeton en clair, lien */
    /**
     * AUDIT DU 06/09, CONSTAT 6. Un document déclaré `text/html` par celui qui l'avait téléversé était
     * servi `inline`, sur l'origine de l'application : son script s'exécutait chez nous et lisait le
     * JWT. Il part désormais en pièce jointe, et le navigateur a interdiction de « deviner » autre chose.
     */
    public function testUnDocumentDeclareHtmlPartEnPieceJointeEtLeNavigateurNeDevineRien(): void
    {
        [, $token, ] = $this->createPublicLink('<html><script>alert(1)</script></html>', new \DateTimeImmutable('+7 days'), 'text/html', 'piège.html');

        $client = static::createClient();
        $client->request('GET', '/dms/public/' . $token);
        self::assertResponseIsSuccessful();

        $entetes = $client->getResponse()->getHeaders(false);
        self::assertStringStartsWith('attachment', $entetes['content-disposition'][0] ?? '', 'un HTML ne s\'affiche jamais en ligne');
        self::assertSame('nosniff', $entetes['x-content-type-options'][0] ?? null, 'sans nosniff, la liste blanche ne protège de rien');
        self::assertStringContainsString('sandbox', $entetes['content-security-policy'][0] ?? '', 'une pièce jointe rendue malgré tout tourne sans origine');
    }

    /** Ce que la règle ÉPARGNE : un PDF s'affiche toujours en ligne — c'est l'usage du lien public. */
    public function testUnPdfSAfficheEnLigneMaisSansSniffing(): void
    {
        [, $token, ] = $this->createPublicLink("%PDF-1.4\n%%EOF", new \DateTimeImmutable('+7 days'));

        $client = static::createClient();
        $client->request('GET', '/dms/public/' . $token);
        self::assertResponseIsSuccessful();

        $entetes = $client->getResponse()->getHeaders(false);
        self::assertStringStartsWith('inline', $entetes['content-disposition'][0] ?? '');
        self::assertSame('application/pdf', $entetes['content-type'][0] ?? null);
        self::assertSame('nosniff', $entetes['x-content-type-options'][0] ?? null);
    }

    /** Témoin de la liste blanche : le SVG est une image, mais il porte du script — il se télécharge. */
    public function testUnSvgEstUneImageQuiPorteDuScriptDoncSeTelecharge(): void
    {
        [, $token, ] = $this->createPublicLink('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', new \DateTimeImmutable('+7 days'), 'image/svg+xml', 'dessin.svg');

        $client = static::createClient();
        $client->request('GET', '/dms/public/' . $token);
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('attachment', $client->getResponse()->getHeaders(false)['content-disposition'][0] ?? '');
    }

    private function createPublicLink(string $contenu, \DateTimeImmutable $expiresAt, string $mimeType = 'application/pdf', string $filename = 'f.pdf'): array
    {
        /** @var UploadDocumentHandler $handler */
        $handler = static::getContainer()->get(UploadDocumentHandler::class);
        $etablissement = $this->entity(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);

        $source = fopen('php://temp', 'r+b');
        \assert($source !== false);
        fwrite($source, $contenu);
        rewind($source);
        try {
            $document = $handler->upload($etablissement, DocumentCategory::Other, 'Titre', null, null, $source, $filename, $mimeType, null);
        } finally {
            fclose($source);
        }

        $em = $this->em();
        $createdBy = $em->getRepository(Utilisateur::class)->findOneBy(['email' => SocleFixtures::ADMIN_EMAIL]);
        self::assertInstanceOf(Utilisateur::class, $createdBy);

        /** @var PublicLinkTokenGenerator $generateur */
        $generateur = static::getContainer()->get(PublicLinkTokenGenerator::class);
        $token = $generateur->generateToken();
        $hash = $generateur->hash($token);

        $version = $document->getCurrentVersion();
        self::assertNotNull($version);
        $lien = new DocumentPublicLink($document, $version, $hash, $expiresAt, $createdBy);
        $em->persist($lien);
        $em->flush();

        return [$document, $token, $lien];
    }
}
