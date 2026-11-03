<?php

declare(strict_types=1);

namespace App\Tests\Caisse\Api;

use App\Caisse\Entity\PointDeVente;
use App\Tests\Caisse\CaisseClotureRoleApiTestCase;

/**
 * « Un billet par unité » se règle sur le point de vente : 3 entrées vendues sortent 3 billets
 * séparés, chacun avec le code de son support. Désactivé par défaut (un billet récapitulatif).
 */
final class OneTicketPerUnitSettingTest extends CaisseClotureRoleApiTestCase
{
    public function testThePointOfSaleCarriesTheSetting(): void
    {
        [$client, $entete] = $this->adminSurA();
        $url = '/api/point_de_ventes/' . $this->idPointDeVente();

        self::assertFalse($client->request('GET', $url, $entete)->toArray()['unBilletParUnite'] ?? null, 'Désactivé par défaut.');

        $client->request('PATCH', $url, $this->entetePatch($entete) + ['json' => ['unBilletParUnite' => true]]);
        self::assertResponseIsSuccessful();

        self::assertTrue($client->request('GET', $url, $entete)->toArray()['unBilletParUnite'] ?? null);
        $pdv = $this->entite(PointDeVente::class, ['id' => $this->idPointDeVente()]);
        self::assertTrue($pdv->isUnBilletParUnite(), 'Écrit en base, pas seulement relu.');
    }
}
