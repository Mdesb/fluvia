<?php

declare(strict_types=1);

namespace App\Tests\Dms\Api;

use App\DataFixtures\SocleFixtures;
use App\Platform\Event\DomainEvent;
use App\Tests\Dms\DmsApiTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Upload multipart -> 201 + `document.stored` publié (CA-9). `UploadDocumentHandler` ne reçoit jamais
 * `ContexteEtablissement` (seul le processor HTTP le lit, une fois, pour construire l'entité) : le
 * tenant de l'événement est construit à partir de `Document.establishment` (garantie structurelle, pas
 * seulement observée par coïncidence).
 */
final class DocumentUploadApiTest extends DmsApiTestCase
{
    public function testUploadMultipartCree201EtPublieDocumentStored(): void
    {
        [$client, $entete] = $this->dmsUserOn(SocleFixtures::ETAB_A_NOM, ['read', 'write']);
        $client->disableReboot(); // le listener capturé doit rester sur le même conteneur que la requête.
        $idEtablissementA = $this->establishmentId(SocleFixtures::ETAB_A_NOM);

        $captures = $this->captureEvents(['document.stored']);

        $chemin = $this->temporaryFile('contenu du contrat de test');
        $reponse = $client->request('POST', '/api/documents', $entete + [
            'extra' => [
                'parameters' => ['category' => 'contract', 'title' => 'Contrat de test', 'sourceModule' => 'test_module'],
                'files' => ['file' => new UploadedFile($chemin, 'contrat.pdf', 'application/pdf', null, true)],
            ],
        ]);

        self::assertResponseStatusCodeSame(201, (string) $client->getResponse()->getContent(false));
        $document = $reponse->toArray();
        self::assertSame('contract', $document['category']);
        self::assertSame('Contrat de test', $document['title']);
        self::assertNotNull($document['currentVersion'], '§0.1 : currentVersion jamais null en sortie API.');

        self::assertCount(1, $captures);
        /** @var DomainEvent $evenement */
        $evenement = $captures[0];
        self::assertSame('document.stored', $evenement->name->value);
        self::assertSame('Document', $evenement->subject->type);
        self::assertSame($idEtablissementA, $evenement->tenant->establishmentId->toRfc4122(), 'CA-9 : tenant dérivé de Document.establishment.');
        self::assertSame('contract', $evenement->payload['category']);
        self::assertSame('application/pdf', $evenement->payload['mimeType']);
        self::assertSame('test_module', $evenement->payload['sourceModule']);
        self::assertGreaterThan(0, $evenement->payload['sizeBytes']);
    }

    public function testUploadSansPermissionDmsWriteDonne403(): void
    {
        [$client, $entete] = $this->dmsUserOn(SocleFixtures::ETAB_A_NOM, ['read']);

        $chemin = $this->temporaryFile('contenu');
        $client->request('POST', '/api/documents', $entete + [
            'extra' => [
                'parameters' => ['category' => 'other', 'title' => 'x'],
                'files' => ['file' => new UploadedFile($chemin, 'f.pdf', 'application/pdf', null, true)],
            ],
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testUploadSansFichierDonne422(): void
    {
        [$client, $entete] = $this->dmsUserOn(SocleFixtures::ETAB_A_NOM, ['read', 'write']);

        $client->request('POST', '/api/documents', $entete + [
            'extra' => ['parameters' => ['category' => 'other', 'title' => 'x']],
        ]);
        self::assertResponseStatusCodeSame(422);
    }
}
