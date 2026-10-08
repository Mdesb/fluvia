<?php

declare(strict_types=1);

namespace App\Tests\Offre\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Offre\OffreApiTestCase;

/**
 * « Toute l'année » : un tarif SANS saison (décision de Maxime, CP-1 du 06/10/2026).
 *
 * L'écran des tarifs propose « Toute l'année » par défaut et envoie alors une case de grille sans
 * saison ; le serveur la refusait (saison obligatoire). Désormais un tarif sans saison vaut toute
 * l'année, et un tarif d'une saison précise l'emporte sur lui pendant cette saison.
 */
final class AllYearPriceTest extends OffreApiTestCase
{
    /** (a) Un produit qui n'a qu'un tarif « toute l'année » a ce prix à toute date. */
    public function testAllYearPriceIsAcceptedAndAppliesAtAnyDate(): void
    {
        [$client, $headers, $produit, $plein] = $this->newProduct();

        $this->postPrice($client, $headers, $produit, $plein, null, '10.00');
        self::assertResponseStatusCodeSame(201, 'Une case de grille sans saison (toute l\'année) doit être acceptée.');

        foreach (['2026-05-01', '2031-11-30'] as $date) {
            $quote = $this->quote($client, $headers, $produit, $plein, $date);
            self::assertSame('10.00', $quote['prixUnitaire'], 'Prix toute l\'année attendu le ' . $date);
            self::assertNull($quote['saison']);
        }
    }

    /** (b) Toute l'année 10 € + saison 8 € : 8 € pendant la saison, 10 € en dehors. */
    public function testSeasonPriceOverridesAllYearPriceDuringItsSeason(): void
    {
        [$client, $headers, $produit, $plein] = $this->newProduct();
        $saison = $this->idSaison(OffreFixtures::SAISON); // du 01/01/2026 au 31/12 de l'an prochain, priorité 0

        $this->postPrice($client, $headers, $produit, $plein, null, '10.00');
        self::assertResponseStatusCodeSame(201);
        $this->postPrice($client, $headers, $produit, $plein, $saison, '8.00');
        self::assertResponseStatusCodeSame(201);

        $enSaison = $this->quote($client, $headers, $produit, $plein, '2026-07-15');
        self::assertSame('8.00', $enSaison['prixUnitaire'], 'Le tarif de la saison l\'emporte pendant la saison.');
        self::assertSame($saison, $enSaison['saison'], 'La saison retenue est celle du tarif appliqué.');

        $horsSaison = $this->quote($client, $headers, $produit, $plein, '2025-03-01');
        self::assertSame('10.00', $horsSaison['prixUnitaire'], 'Hors saison, le tarif toute l\'année s\'applique.');
        self::assertNull($horsSaison['saison']);
    }

    /** (c) Deux tarifs « toute l'année » identiques (produit × type × tranche) sont refusés. */
    public function testTwoIdenticalAllYearPricesAreRefused(): void
    {
        [$client, $headers, $produit, $plein] = $this->newProduct();

        $this->postPrice($client, $headers, $produit, $plein, null, '10.00');
        self::assertResponseStatusCodeSame(201);

        // Une contrainte UNIQUE SQL laisse passer deux NULL : seule la validation applicative refuse.
        $this->postPrice($client, $headers, $produit, $plein, null, '12.00');
        self::assertResponseStatusCodeSame(422);
    }

    /** @return array{0: \ApiPlatform\Symfony\Bundle\Test\Client, 1: array<string, mixed>, 2: string, 3: string} */
    private function newProduct(): array
    {
        [$client, $token, $idA] = $this->adminSurA();
        $headers = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        $produit = $client->request('POST', '/api/produits', $headers + [
            'json' => [
                'libelle' => ['fr' => 'Entrée toute l\'année'],
                'type' => '/api/type_produits/' . $this->idType(OffreFixtures::TYPE_ENTREE),
                'canaux' => ['guichet'],
                'etablissements' => ['/api/etablissements/' . $idA],
            ],
        ])->toArray();
        self::assertResponseStatusCodeSame(201);

        return [$client, $headers, (string) $produit['id'], $this->idTarif(OffreFixtures::TARIF_PLEIN)];
    }

    /** @param array<string, mixed> $headers */
    private function postPrice(
        \ApiPlatform\Symfony\Bundle\Test\Client $client,
        array $headers,
        string $produit,
        string $typeTarif,
        ?string $saison,
        string $prix,
    ): void {
        $json = [
            'produit' => '/api/produits/' . $produit,
            'typeTarif' => '/api/type_tarifs/' . $typeTarif,
            'prix' => $prix,
        ];
        if ($saison !== null) {
            $json['saison'] = '/api/saisons/' . $saison;
        }
        $client->request('POST', '/api/grille_tarifaires', $headers + ['json' => $json]);
    }

    /**
     * @param array<string, mixed> $headers
     *
     * @return array<string, mixed>
     */
    private function quote(
        \ApiPlatform\Symfony\Bundle\Test\Client $client,
        array $headers,
        string $produit,
        string $typeTarif,
        string $date,
    ): array {
        $reponse = $client->request('GET', sprintf('/api/produits/%s/tarif?typeTarif=%s&date=%s', $produit, $typeTarif, $date), $headers);
        self::assertResponseIsSuccessful();

        return $reponse->toArray();
    }
}
