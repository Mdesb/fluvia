<?php

declare(strict_types=1);

namespace App\Tests\Fonctionnalite\Api;

use App\Tests\Fonctionnalite\FonctionnaliteApiTestCase;

/**
 * GET /fonctionnalites/catalogue : référentiel des capacités connues du socle.
 */
final class CatalogueTest extends FonctionnaliteApiTestCase
{
    public function testLeCatalogueExposeAuMoinsLesDouzeCapacitesDuSocle(): void
    {
        [$client, $entete] = $this->adminSurA();

        $reponse = $client->request('GET', '/api/fonctionnalites/catalogue', $entete);
        self::assertResponseIsSuccessful();

        $donnees = $reponse->toArray();
        $membres = $donnees['member'] ?? $donnees['hydra:member'];
        $codes = array_map(static fn (array $item): string => $item['code'], $membres);

        foreach ([
            'controle_acces', 'reservation', 'no_show', 'recouvrement', 'sepa', 'porte_monnaie',
            'casiers', 'location_materiel', 'poss', 'acces_nocturne', 'encadrants', 'boutique_en_ligne',
        ] as $codeAttendu) {
            self::assertContains($codeAttendu, $codes);
        }

        // Chaque entrée porte un libellé et une catégorie exploitables par l'UI.
        foreach ($membres as $item) {
            self::assertNotSame('', $item['libelle']);
            self::assertNotSame('', $item['categorie']);
        }
    }

    public function testLeCatalogueRequiertUneAuthentification(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/fonctionnalites/catalogue');
        self::assertResponseStatusCodeSame(401);
    }
}
