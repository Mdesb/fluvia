<?php

declare(strict_types=1);

namespace App\Tests\Dms\Api;

use App\DataFixtures\SocleFixtures;
use App\Dms\Entity\Document;
use App\Dms\Enum\DocumentCategory;
use App\Dms\Service\UploadDocumentHandler;
use App\Organisation\Entity\Etablissement;
use App\Tests\Dms\DmsApiTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** CA-8 (RG-DMS-18/19) : v2 créée, currentVersion pointe v2, v1 reste GET-able et téléchargeable inchangée. */
final class ReplaceDocumentVersionApiTest extends DmsApiTestCase
{
    public function testRemplacementCreeV2SansAltererV1(): void
    {
        $document = $this->createDocumentWithV1('contenu version 1');
        $idV1 = (string) $document->getCurrentVersion()->getId();

        [$client, $entete] = $this->dmsUserOn(SocleFixtures::ETAB_A_NOM, ['read', 'write']);

        $chemin = $this->temporaryFile('contenu version 2');
        $reponse = $client->request('POST', '/api/documents/' . $document->getId() . '/replace-version', $entete + [
            'extra' => ['files' => ['file' => new UploadedFile($chemin, 'v2.pdf', 'application/pdf', null, true)]],
        ]);

        self::assertResponseIsSuccessful();
        $corps = $reponse->toArray();
        // `currentVersion` sortait en IRI nue ; depuis que `DocumentVersion` expose ses champs
        // dans `document:read`, la relation est embarquee et le champ est un objet. L'intention ne
        // change pas -- l'identifiant est lu quel que soit le format, pour que l'assertion survive
        // au prochain changement de groupe.
        $version = $corps['currentVersion'];
        $iriVersion = \is_array($version) ? (string) ($version['@id'] ?? '') : (string) $version;
        self::assertNotSame('', $iriVersion, 'currentVersion absent de la reponse.');
        self::assertStringNotContainsString($idV1, $iriVersion, 'currentVersion doit pointer une nouvelle version, pas v1.');

        // v1 reste GET-able (historique des versions).
        $client->request('GET', '/api/document_versions/' . $idV1, $entete);
        self::assertResponseIsSuccessful();

        // v1 reste téléchargeable, contenu inchangé.
        $client->request('GET', '/dms/documents/' . $document->getId() . '/download?version=' . $idV1, $entete);
        self::assertResponseIsSuccessful();
        self::assertSame('contenu version 1', $client->getResponse()->getContent());

        // Le téléchargement par défaut sert désormais v2.
        $client->request('GET', '/dms/documents/' . $document->getId() . '/download', $entete);
        self::assertResponseIsSuccessful();
        self::assertSame('contenu version 2', $client->getResponse()->getContent());
    }

    public function testRemplacementSurVersionDUnAutreDocumentDonne404(): void
    {
        $documentA = $this->createDocumentWithV1('contenu A');
        $documentB = $this->createDocumentWithV1('contenu B', SocleFixtures::ETAB_B_NOM);

        [$client, $entete] = $this->dmsUserOn(SocleFixtures::ETAB_A_NOM, ['read', 'write']);

        $client->request('GET', '/dms/documents/' . $documentA->getId() . '/download?version=' . $documentB->getCurrentVersion()->getId(), $entete);
        self::assertSame(404, $client->getResponse()->getStatusCode(), 'Pas de traversée inter-documents via un id de version deviné.');
    }

    private function createDocumentWithV1(string $contenu, string $nomEtablissement = SocleFixtures::ETAB_A_NOM): Document
    {
        /** @var UploadDocumentHandler $handler */
        $handler = static::getContainer()->get(UploadDocumentHandler::class);
        $etablissement = $this->entity(Etablissement::class, ['nom' => $nomEtablissement]);

        $source = fopen('php://temp', 'r+b');
        \assert($source !== false);
        fwrite($source, $contenu);
        rewind($source);

        try {
            return $handler->upload($etablissement, DocumentCategory::Other, 'Titre', null, null, $source, 'v1.pdf', 'application/pdf', null);
        } finally {
            fclose($source);
        }
    }
}
