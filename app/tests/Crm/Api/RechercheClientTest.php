<?php

declare(strict_types=1);

namespace App\Tests\Crm\Api;

use App\Tests\Crm\CrmApiTestCase;

/**
 * US-L5-01 : recherche & filtres clients.
 */
final class RechercheClientTest extends CrmApiTestCase
{
    /** CA-1 — Recherche par nom/e-mail/téléphone tolérante à la casse, paginée. */
    public function testCa1RechercheParNomEmailTelephoneTolueranteCasse(): void
    {
        [$client, $entete] = $this->adminSurA();

        $parNom = $client->request('GET', '/api/crm/clients/recherche', $entete + ['query' => ['q' => 'DUPONT']])->toArray();
        self::assertResponseIsSuccessful();
        self::assertGreaterThanOrEqual(3, $parNom['total']);

        $parEmail = $client->request('GET', '/api/crm/clients/recherche', $entete + ['query' => ['q' => 'jean.dupont']])->toArray();
        self::assertSame(1, $parEmail['total']);
        self::assertSame('Jean', $parEmail['items'][0]['prenom']);

        $parTel = $client->request('GET', '/api/crm/clients/recherche', $entete + ['query' => ['q' => '0601020304']])->toArray();
        self::assertSame(1, $parTel['total']);

        // Pagination.
        $page = $client->request('GET', '/api/crm/clients/recherche', $entete + ['query' => ['q' => 'dupont', 'itemsPerPage' => 1, 'page' => 1]])->toArray();
        self::assertCount(1, $page['items']);
        self::assertSame(1, $page['page']);
    }

    /** CA-2 — Filtres combinés (statut, avecPmv, mineur) ; aucune fiche candidate à fusion masquée. */
    public function testCa2FiltresCombinesEtFichesFusionneesVisiblesSurDemande(): void
    {
        [$client, $entete] = $this->adminSurA();

        $avecPmv = $client->request('GET', '/api/crm/clients/recherche', $entete + ['query' => ['avecPmv' => '1']])->toArray();
        self::assertSame(1, $avecPmv['total']);
        self::assertSame('Jean', $avecPmv['items'][0]['prenom']);

        $mineurs = $client->request('GET', '/api/crm/clients/recherche', $entete + ['query' => ['mineur' => '1']])->toArray();
        self::assertSame(1, $mineurs['total']);
        self::assertTrue($mineurs['items'][0]['estMineur']);

        $majeurs = $client->request('GET', '/api/crm/clients/recherche', $entete + ['query' => ['mineur' => '0']])->toArray();
        self::assertGreaterThanOrEqual(2, $majeurs['total']);

        // Simule une fusion pour vérifier que le statut « fusionne » reste consultable sur demande.
        $payeurId = $this->idPayeur();
        $conjointId = $this->idConjoint();
        $client->request('POST', '/api/crm/fusions', $entete + [
            'json' => ['portee' => 'client', 'sources' => ['/api/clients/' . $conjointId], 'maitre' => '/api/clients/' . $payeurId],
        ]);
        self::assertResponseIsSuccessful();

        $defaut = $client->request('GET', '/api/crm/clients/recherche', $entete + ['query' => ['q' => 'marie']])->toArray();
        self::assertSame(0, $defaut['total'], 'Par défaut, une fiche fusionnée est exclue de la recherche standard.');

        $explicite = $client->request('GET', '/api/crm/clients/recherche', $entete + ['query' => ['statut' => 'fusionne']])->toArray();
        self::assertSame(1, $explicite['total'], 'La recherche explicite « candidates à fusion » ne masque aucune fiche fusionnée.');
    }
}
