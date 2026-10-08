<?php

declare(strict_types=1);

namespace App\Tests\Vente\Support;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Offre\DataFixtures\OffreFixtures;
use App\Vente\Tpe\TpeMock;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Le montage commun des tests de tentatives de règlement (lot 2 du ticket opposable). Les témoins
 * sont lus EN BASE, par SQL : la réponse de l'appel qu'on juge ne dit rien d'un débit parti avant
 * une écriture refusée.
 */
trait SettlementScenarios
{
    /**
     * Une vente de 45,00 (une carte, plein tarif).
     *
     * @param array<string, mixed> $entete
     */
    private function cardSale(Client $client, array $entete, ?string $sessionId = null): string
    {
        $sessionId ??= $this->ouvrirSession($client, $entete)['id'];
        $vente = $client->request('POST', '/api/ventes', $entete + [
            'json' => ['session' => '/api/session_caisses/' . $sessionId],
        ])->toArray();
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + ['json' => [
            'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_CARTE),
            'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
            'quantite' => 1,
        ]]);
        self::assertResponseIsSuccessful();

        return $vente['id'];
    }

    /**
     * @param array<string, mixed> $entete
     * @param array<string, mixed> $corps
     */
    private function pay(Client $client, array $entete, string $vente, array $corps, ?string $tpe = null): ResponseInterface
    {
        $options = $entete + ['json' => $corps];
        if ($tpe !== null) {
            $options['headers'][TpeMock::HEADER_SIMULATION] = $tpe;
        }

        return $client->request('POST', '/api/ventes/' . $vente . '/paiements', $options);
    }

    private function paymentCount(string $vente): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM vente_paiement WHERE vente_id = UNHEX(:v)', ['v' => $this->hex($vente)]);
    }

    /** @return array<string, mixed>|null la tentative de cette clé, lue en base */
    private function attemptOf(string $cle): ?array
    {
        $ligne = $this->db()->fetchAssociative(
            'SELECT status, terminal_status, open_sale_id IS NOT NULL AS holds_sale, requested_amount, amount FROM sale_payment_attempt WHERE idempotency_key = UNHEX(:k)',
            ['k' => $this->hex($cle)],
        );

        return $ligne === false ? null : $ligne;
    }

    /**
     * Une tentative « pending » écrite par une AUTRE demande, démarrée il y a `$age` (« -5 seconds »).
     *
     * @return string sa clé
     */
    private function attemptInFlight(string $vente, string $moyen, bool $terminal, string $age): string
    {
        $cle = (string) Uuid::v4();
        $this->db()->executeStatement(
            'INSERT INTO sale_payment_attempt (id, sale_id, open_sale_id, idempotency_key, payment_method_code, requested_amount, amount, uses_terminal, status, started_at) '
            . 'VALUES (UNHEX(:id), UNHEX(:v), UNHEX(:v), UNHEX(:k), :m, \'45.00\', \'45.00\', :t, \'pending\', :d)',
            [
                'id' => $this->hex((string) Uuid::v4()),
                'v' => $this->hex($vente),
                'k' => $this->hex($cle),
                'm' => $moyen,
                't' => (int) $terminal,
                'd' => (new \DateTimeImmutable($age))->format('Y-m-d H:i:s'),
            ],
        );

        return $cle;
    }

    /** @param array<string, mixed> $entete */
    private function balance(Client $client, array $entete): string
    {
        return $client->request('GET', '/api/clients/' . $this->idPayeur() . '/pmv', $entete)->toArray()['solde'];
    }

    private function db(): Connection
    {
        /** @var Connection $connexion */
        $connexion = static::getContainer()->get('doctrine')->getConnection();

        return $connexion;
    }

    private function hex(string $uuid): string
    {
        return str_replace('-', '', $uuid);
    }
}
