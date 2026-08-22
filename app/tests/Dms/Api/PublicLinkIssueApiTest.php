<?php

declare(strict_types=1);

namespace App\Tests\Dms\Api;

use App\DataFixtures\SocleFixtures;
use App\Dms\DataFixtures\DmsFixtures;
use App\Dms\Entity\Document;
use App\Dms\Enum\DocumentCategory;
use App\Dms\Service\UploadDocumentHandler;
use App\Organisation\Entity\Etablissement;
use App\Tests\Dms\DmsApiTestCase;

/**
 * `dms.manage_public_link` requis (403 sinon, y compris pour un utilisateur `dms.manage_retention` ou
 * admin générique sans le rôle dédié) ; jeton en clair présent **une seule fois** dans la réponse
 * `PublicLinkIssued`, jamais dans `GetCollection`/`Get` ultérieurs. CA-10 : payload de
 * `document.public_link_issued` sans jeton.
 */
final class PublicLinkIssueApiTest extends DmsApiTestCase
{
    public function testEmissionParLeRoleDedieRenvoieLeJetonUneSeuleFois(): void
    {
        $document = $this->createDocument();
        [$client, $entete] = $this->userWithRoleOn(DmsFixtures::ROLE_LIENS_PUBLICS, SocleFixtures::ETAB_A_NOM, 'issue-link-role-dedie');
        $client->disableReboot();

        $captures = $this->captureEvents(['document.public_link_issued']);

        $reponse = $client->request('POST', '/api/documents/' . $document->getId() . '/public-links', $entete + [
            'json' => ['versionId' => null, 'expiresInDays' => 7],
        ]);
        self::assertResponseIsSuccessful();
        $corps = $reponse->toArray();
        self::assertArrayHasKey('url', $corps);
        self::assertStringContainsString('/dms/public/', $corps['url']);

        $token = substr($corps['url'], strrpos($corps['url'], '/') + 1);
        self::assertNotEmpty($token);

        self::assertCount(1, $captures);
        $payloadJson = json_encode($captures[0]->payload);
        self::assertIsString($payloadJson);
        self::assertStringNotContainsString($token, $payloadJson, 'CA-10 : le jeton en clair ne doit jamais figurer dans le payload de l\'événement.');
        self::assertArrayNotHasKey('token', $captures[0]->payload);

        // Le jeton en clair n'apparaît jamais dans une lecture ultérieure de la ressource.
        $listeReponse = $client->request('GET', '/api/document_public_links', $entete);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($token, (string) $listeReponse->getContent(false));

        $itemReponse = $client->request('GET', '/api/document_public_links/' . $corps['id'], $entete);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($token, (string) $itemReponse->getContent(false));
        self::assertStringNotContainsString('tokenHash', (string) $itemReponse->getContent(false));
    }

    public function testAdminGeneriqueSansRoleDedieRefuse(): void
    {
        // L'administrateur socle a dms.read/write/delete/manage_retention (DmsFixtures) mais PAS
        // dms.manage_public_link — §0.5 : aucun rôle générique ne doit l'hériter.
        $document = $this->createDocument();
        [$client, $entete] = $this->adminOnA();

        $client->request('POST', '/api/documents/' . $document->getId() . '/public-links', $entete + [
            'json' => ['versionId' => null, 'expiresInDays' => 7],
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testUtilisateurManageRetentionSeulRefuse(): void
    {
        $document = $this->createDocument();
        [$client, $entete] = $this->dmsUserOn(SocleFixtures::ETAB_A_NOM, ['read', 'manage_retention']);

        $client->request('POST', '/api/documents/' . $document->getId() . '/public-links', $entete + [
            'json' => ['versionId' => null, 'expiresInDays' => 7],
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testExpiresInDaysHorsBorneRefuse422(): void
    {
        $document = $this->createDocument();
        [$client, $entete] = $this->userWithRoleOn(DmsFixtures::ROLE_LIENS_PUBLICS, SocleFixtures::ETAB_A_NOM, 'issue-link-bornes');

        $client->request('POST', '/api/documents/' . $document->getId() . '/public-links', $entete + [
            'json' => ['versionId' => null, 'expiresInDays' => 31],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    private function createDocument(): Document
    {
        /** @var UploadDocumentHandler $handler */
        $handler = static::getContainer()->get(UploadDocumentHandler::class);
        $etablissement = $this->entity(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);

        $source = fopen('php://temp', 'r+b');
        \assert($source !== false);
        fwrite($source, 'contenu');
        rewind($source);

        try {
            return $handler->upload($etablissement, DocumentCategory::Other, 'Titre', null, null, $source, 'f.pdf', 'application/pdf', null);
        } finally {
            fclose($source);
        }
    }
}
