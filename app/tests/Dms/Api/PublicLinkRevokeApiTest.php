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

/** CA-4 (RG-DMS-07) : révocation puis accès public -> échec immédiat, avant l'échéance naturelle. */
final class PublicLinkRevokeApiTest extends DmsApiTestCase
{
    public function testRevocationInvalideImmediatementLAccesPublic(): void
    {
        $document = $this->createDocument();
        [$client, $entete] = $this->userWithRoleOn(DmsFixtures::ROLE_LIENS_PUBLICS, SocleFixtures::ETAB_A_NOM, 'revoke-link-role-dedie');
        $client->disableReboot();

        $emission = $client->request('POST', '/api/documents/' . $document->getId() . '/public-links', $entete + [
            'json' => ['versionId' => null, 'expiresInDays' => 7],
        ])->toArray();
        $token = substr($emission['url'], strrpos($emission['url'], '/') + 1);

        // Avant révocation : le lien fonctionne.
        $client->request('GET', '/dms/public/' . $token);
        self::assertResponseIsSuccessful();

        $captures = $this->captureEvents(['document.public_link_revoked']);
        $client->request('POST', '/api/public-links/' . $emission['id'] . '/revoke', $entete);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $captures);
        self::assertSame($emission['id'], $captures[0]->payload['publicLinkId']);

        $client->request('GET', '/dms/public/' . $token);
        self::assertResponseStatusCodeSame(404, (string) $client->getResponse()->getContent(false));
    }

    public function testRevocationSansPermissionDedieeRefuse403(): void
    {
        $document = $this->createDocument();
        [$clientEmetteur, $enteteEmetteur] = $this->userWithRoleOn(DmsFixtures::ROLE_LIENS_PUBLICS, SocleFixtures::ETAB_A_NOM, 'revoke-link-emetteur');
        $emission = $clientEmetteur->request('POST', '/api/documents/' . $document->getId() . '/public-links', $enteteEmetteur + [
            'json' => ['versionId' => null, 'expiresInDays' => 7],
        ])->toArray();

        [$client, $entete] = $this->dmsUserOn(SocleFixtures::ETAB_A_NOM, ['read', 'write']);
        $client->request('POST', '/api/public-links/' . $emission['id'] . '/revoke', $entete);
        self::assertResponseStatusCodeSame(403);
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
