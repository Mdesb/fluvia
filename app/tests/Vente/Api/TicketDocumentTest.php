<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Offre\DataFixtures\OffreFixtures;
use App\Tests\Vente\VenteApiTestCase;
use App\Vente\Entity\BilletSupport;
use App\Vente\Entity\Vente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Le ticket vient d'un seul document (G-11), sans code d'accès (D124) ni mention d'avoir (D125).
 *
 * `DocumentTicketTest` prouve que l'extraction ne change pas la sortie. Ces deux gardes, vertes
 * avant comme après, tiennent ce que D124 et D125 interdisent d'ajouter au ticket d'ici les lots 7
 * et 9.
 */
final class TicketDocumentTest extends VenteApiTestCase
{
    public function testTheTicketCarriesNoAccessCode(): void
    {
        [$client, $headers] = $this->adminSurA();
        $saleId = $this->validatedSale($client, $headers);

        $codes = array_filter(array_map(
            static fn (BilletSupport $support): ?string => $support->getIdentifiantSupport(),
            $this->freshSale($saleId)->getSupports()->toArray(),
        ));
        self::assertNotEmpty($codes, 'Temoin : la vente a bien emis des codes d\'acces.');

        $printed = json_encode($this->ticket($client, $headers, $saleId), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        foreach ($codes as $code) {
            self::assertStringNotContainsString($code, $printed, 'Un ticket qui porte le code devient un second billet.');
        }
    }

    public function testARefundOrACancellationAddsNothingToTheTicket(): void
    {
        [$client, $headers] = $this->adminSurA();
        $saleId = $this->validatedSale($client, $headers);
        $original = $this->ticket($client, $headers, $saleId);

        $client->request('POST', '/api/ventes/' . $saleId . '/rembourser', $headers + [
            'json' => ['motif' => 'Geste commercial', 'montant' => '5.00'],
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($original, $this->ticket($client, $headers, $saleId), 'Remboursee : le duplicata est l\'original.');

        $client->request('POST', '/api/ventes/' . $saleId . '/annuler', $headers + ['json' => ['motif' => 'Client parti']]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($original, $this->ticket($client, $headers, $saleId), 'Annulee : le duplicata est l\'original.');
    }

    /** @param array<string, mixed> $headers @return array<string, mixed> */
    private function ticket(Client $client, array $headers, string $saleId): array
    {
        return $client->request('POST', '/api/ventes/' . $saleId . '/ticket', $headers + [
            'json' => ['mode' => 'imprimer'],
        ])->toArray();
    }

    /** Une carte au plein tarif et une entrée remisée de 10 %, réglées en espèces. @param array<string, mixed> $headers */
    private function validatedSale(Client $client, array $headers): string
    {
        $session = $this->ouvrirSession($client, $headers);
        $sale = $this->creerVente($client, $headers, $session['id']);
        foreach ([[OffreFixtures::PRODUIT_CARTE, []], [OffreFixtures::PRODUIT_ENTREE, ['remiseLigne' => 10, 'remiseType' => 'pourcentage']]] as [$product, $discount]) {
            $client->request('POST', '/api/ventes/' . $sale['id'] . '/lignes', $headers + [
                'json' => [
                    'produit' => '/api/produits/' . $this->idProduit($product),
                    'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                    'quantite' => 1,
                ] + $discount,
            ]);
            self::assertResponseIsSuccessful();
        }
        $total = $client->request('GET', '/api/ventes/' . $sale['id'], $headers)->toArray()['total'];
        $client->request('POST', '/api/ventes/' . $sale['id'] . '/paiements', $headers + [
            'json' => ['moyen' => 'especes', 'montant' => $total],
        ]);
        $client->request('POST', '/api/ventes/' . $sale['id'] . '/valider', $headers + ['json' => []]);
        self::assertResponseIsSuccessful();

        return $sale['id'];
    }

    private function freshSale(string $saleId): Vente
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $sale = $em->find(Vente::class, Uuid::fromString($saleId));
        self::assertInstanceOf(Vente::class, $sale);

        return $sale;
    }
}
