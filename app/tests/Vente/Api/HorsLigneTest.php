<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Tests\Vente\VenteApiTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Mode dégradé hors-ligne & resynchronisation (CA-16 / RG-M2-08) : remontée idempotente sans doublon,
 * rejeu chronologique, indicateur d'état.
 */
final class HorsLigneTest extends VenteApiTestCase
{
    /** CA-16 — Resynchro : rejeu chronologique, anti-doublon (clé rejouée = no-op), ventes hors-ligne. */
    public function testCa16ResynchroSansDoublon(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);

        $cle = (string) Uuid::v4();
        $lot = [
            'session' => '/api/session_caisses/' . $session['id'],
            'operations' => [[
                'cleIdempotence' => $cle,
                'sequenceLocale' => 1,
                'lignes' => [[
                    'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_CARTE),
                    'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                    'quantite' => 1,
                ]],
                'paiements' => [['moyen' => 'especes', 'montant' => '45.00']],
            ]],
        ];

        // Première remontée : la vente hors-ligne est ancrée et scellée.
        $premier = $client->request('POST', '/api/synchro/operations', $entete + ['json' => $lot])->toArray();
        self::assertResponseIsSuccessful();
        self::assertCount(1, $premier['inseres']);
        self::assertEmpty($premier['doublons']);

        // Rejeu du même lot : la clé déjà remontée est un no-op (anti-doublon).
        $second = $client->request('POST', '/api/synchro/operations', $entete + ['json' => $lot])->toArray();
        self::assertEmpty($second['inseres']);
        self::assertContains($cle, $second['doublons']);

        // Indicateur d'état + comptage des ventes d'origine hors-ligne.
        $etat = $client->request('GET', '/api/synchro/etat', $entete)->toArray();
        self::assertSame('en_ligne', $etat['etat']);
        self::assertGreaterThanOrEqual(1, $etat['ventesHorsLigne']);
    }

    /** CA-16 — Trou de séquence dans le lot → rejet (alerte de contrôle), pas d'insertion partielle. */
    public function testCa16TrouDeSequenceRejete(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);

        $lot = [
            'session' => '/api/session_caisses/' . $session['id'],
            'operations' => [
                ['cleIdempotence' => (string) Uuid::v4(), 'sequenceLocale' => 1, 'lignes' => [], 'paiements' => []],
                ['cleIdempotence' => (string) Uuid::v4(), 'sequenceLocale' => 3, 'lignes' => [], 'paiements' => []],
            ],
        ];

        $client->request('POST', '/api/synchro/operations', $entete + ['json' => $lot]);
        self::assertResponseStatusCodeSame(422);
    }
}
