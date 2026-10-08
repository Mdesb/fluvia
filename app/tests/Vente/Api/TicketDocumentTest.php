<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Offre\DataFixtures\OffreFixtures;
use App\Tests\Vente\VenteApiTestCase;
use App\Vente\Entity\BilletSupport;
use App\Vente\Entity\Vente;
use App\Vente\Service\DocumentTicket;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Le ticket vient d'un seul document (G-11), sans code d'accès (D124) ni mention d'avoir (D125).
 *
 * `DocumentTicketTest` prouve que l'extraction ne change pas la sortie ; ce test-ci prouve que la
 * réponse EST le document que le rendu PDF du lot 9 lira, sur une vente à deux lignes dont une
 * remisée, et que la mention DUPLICATA n'est pas relue après coup. Les deux dernières méthodes
 * sont des gardes, vertes avant comme après : elles tiennent ce que D124 et D125 interdisent
 * d'ajouter au ticket d'ici les lots 7 et 9.
 */
final class TicketDocumentTest extends VenteApiTestCase
{
    public function testTheTicketResponseIsTheSingleDocument(): void
    {
        [$client, $headers] = $this->adminSurA();
        $saleId = $this->validatedSale($client, $headers);

        $response = $this->ticket($client, $headers, $saleId);
        $document = (new DocumentTicket())->pour($this->freshSale($saleId), $response['duplicata']);

        self::assertCount(2, $document['lignes']);
        self::assertContains('pourcentage', array_column($document['lignes'], 'remiseType'), 'Temoin : la remise passe.');
        self::assertSame($document, array_intersect_key($response, $document));
    }

    /**
     * `duplicata` se reçoit, il ne se recalcule pas. Les ventes du filet sont toutes au-dessus du seuil,
     * donc déjà marquées à la validation : il ne verrait pas un document qui relirait `imprime` après
     * l'avoir posé. Sous le seuil (une entrée à 4,95 €), le premier ticket est l'original.
     */
    public function testUnderTheThresholdTheFirstTicketIsTheOriginal(): void
    {
        [$client, $headers] = $this->adminSurA();
        $saleId = $this->validatedSale($client, $headers, [[OffreFixtures::PRODUIT_ENTREE, []]]);

        self::assertFalse($this->ticket($client, $headers, $saleId)['duplicata']);
        self::assertTrue($this->ticket($client, $headers, $saleId)['duplicata']);
    }

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

    /**
     * Par défaut, une carte au plein tarif et une entrée remisée de 10 %, réglées en espèces.
     *
     * @param array<string, mixed>                       $headers
     * @param list<array{string, array<string, mixed>}>|null $lines produit et remise, par ligne
     */
    private function validatedSale(Client $client, array $headers, ?array $lines = null): string
    {
        $lines ??= [[OffreFixtures::PRODUIT_CARTE, []], [OffreFixtures::PRODUIT_ENTREE, ['remiseLigne' => 10, 'remiseType' => 'pourcentage']]];
        $session = $this->ouvrirSession($client, $headers);
        $sale = $this->creerVente($client, $headers, $session['id']);
        foreach ($lines as [$product, $discount]) {
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
