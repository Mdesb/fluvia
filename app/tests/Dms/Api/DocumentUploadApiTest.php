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

        // Un VRAI en-tête PDF : depuis le 06/09 le serveur devine le type sur le contenu, il ne croit
        // plus la déclaration du client. Du texte brut annoncé PDF serait rangé `text/plain`.
        $chemin = $this->temporaryFile("%PDF-1.4\n% contenu du contrat de test\n%%EOF");
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

    /**
     * AUDIT DU 06/09, CONSTAT 6. Le type MIME stocké était celui DÉCLARÉ par le client : un HTML annoncé
     * « application/pdf » était rangé, puis servi, comme un PDF. Le serveur regarde désormais le contenu,
     * et c'est ce qu'il a vu qui est stocké — et publié dans `document.stored`.
     */
    public function testLeTypeDeclareParLeClientNEstPasCruLeServeurDevineSurLeContenu(): void
    {
        [$client, $entete] = $this->dmsUserOn(SocleFixtures::ETAB_A_NOM, ['read', 'write'], 'sniff');
        $client->disableReboot();
        $captures = $this->captureEvents(['document.stored']);

        $chemin = $this->temporaryFile('<html><body><script>alert(1)</script></body></html>');
        $client->request('POST', '/api/documents', $entete + [
            'extra' => [
                'parameters' => ['category' => 'contract', 'title' => 'Faux PDF', 'sourceModule' => 'test_module'],
                'files' => ['file' => new UploadedFile($chemin, 'faux.pdf', 'application/pdf', null, true)],
            ],
        ]);

        self::assertResponseStatusCodeSame(201, (string) $client->getResponse()->getContent(false));
        self::assertCount(1, $captures);
        /** @var DomainEvent $evenement */
        $evenement = $captures[0];
        self::assertSame('text/html', $evenement->payload['mimeType'], 'le type stocké est celui du contenu, pas celui déclaré');
    }
}
