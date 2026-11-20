<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Caisse\Entity\Caisse;
use App\Caisse\Entity\PointDeVente;
use App\Caisse\Enum\EtatCaisse;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Organisation\Entity\Etablissement;
use App\Platform\Nf525\SealingRefusedException;
use App\Platform\Nf525\SealKeyring;
use App\Tests\Vente\VenteApiTestCase;
use App\Vente\DataFixtures\VenteFixtures;
use App\Vente\Enum\TypeOperationScellee;
use App\Vente\Nf525\HashChainSignataire;
use App\Vente\Nf525\OperationAScellerDto;
use App\Vente\Nf525\ScellementHandler;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * G-2 DE BOUT EN BOUT — une chaîne de vente bloquée, validée par l'API : 409, la vente reste « en cours »
 * (rollback), et l'alerte, elle, survit au rollback (seconde connexion).
 *
 * ── LES PIÈGES ──────────────────────────────────────────────────────────────────────────────────
 *
 * - « Aucune alerte » est vrai aussi d'une alerte qui ne s'écrit jamais : chaque refus a son témoin positif
 *   (clé correcte -> 201, aucune alerte) et le rollback est prouvé par l'état de la vente.
 * - Le maillon bloquant est signé par une clé que le trousseau du conteneur ne connaît pas ; le « rétablir »
 *   le re-signe par la clé active de l'environnement (le geste de l'exploitant qui restaure sa clé).
 */
final class SealAlertEndToEndTest extends VenteApiTestCase
{
    private const FOREIGN_KEY = 'cle-etrangere-alerte-bout-en-bout';
    private const BELL_SOURCE = 'nf525.chain_blocked';

    private function db(): Connection
    {
        return $this->em()->getConnection();
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }

    /** Un maillon de séquence 1, signé par `$key` (clé étrangère ou clé de l'environnement). */
    private function link(string $pointOfSaleId, string $key): void
    {
        $fingerprint = hash('sha256', $pointOfSaleId . '|1');
        $ring = new SealKeyring('NF525_SEAL_KEY', $key);
        $this->db()->executeStatement(
            'INSERT INTO nf525_operation_scellee (id, type_operation, cible_type, cible_id, numero_sequence, empreinte, signature, seal_key_id, payload_canonique, horodatage, point_de_vente_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)',
            [Uuid::v7()->toBinary(), 'vente', 'Vente', Uuid::v7()->toBinary(), 1, $fingerprint, $ring->sign($fingerprint), $ring->activeKeyId(), '{}', Uuid::fromString($pointOfSaleId)->toBinary()],
        );
    }

    /** Re-signe le maillon 1 du point de vente par la clé active de l'environnement : la chaîne se prolonge de nouveau. */
    private function restoreKey(string $pointOfSaleId): void
    {
        $fingerprint = hash('sha256', $pointOfSaleId . '|1');
        $ring = new SealKeyring('NF525_SEAL_KEY', $this->activeKey());
        $this->db()->executeStatement(
            'UPDATE nf525_operation_scellee SET signature = ?, seal_key_id = ? WHERE point_de_vente_id = ? AND numero_sequence = 1',
            [$ring->sign($fingerprint), $ring->activeKeyId(), Uuid::fromString($pointOfSaleId)->toBinary()],
        );
    }

    private function activeKey(): string
    {
        $key = $_SERVER['NF525_SEAL_KEY'] ?? $_ENV['NF525_SEAL_KEY'] ?? getenv('NF525_SEAL_KEY');
        self::assertIsString($key);

        return $key;
    }

    /** @param array<string, mixed> $headers */
    private function openSession(Client $client, array $headers, string $pointOfSaleId, string $tillId): string
    {
        return $client->request('POST', '/api/sessions-caisse/ouvrir', $headers + ['json' => [
            'pointDeVente' => '/api/point_de_ventes/' . $pointOfSaleId,
            'caisse' => '/api/caisses/' . $tillId,
            'regisseur' => '/api/utilisateurs/' . $this->idAdmin(),
            'codeRegisseur' => 'CODE-REGIE-2026',
            'fondDeCaisse' => '50.00',
        ]])->toArray()['id'];
    }

    /**
     * Un panier réglé, prêt à valider.
     *
     * @param array<string, mixed> $headers
     */
    private function paidSale(Client $client, array $headers, string $sessionId): string
    {
        $sale = $this->creerVente($client, $headers, $sessionId);
        $client->request('POST', '/api/ventes/' . $sale['id'] . '/lignes', $headers + ['json' => [
            'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE),
            'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
            'quantite' => 1,
        ]]);
        $client->request('POST', '/api/ventes/' . $sale['id'] . '/paiements', $headers + ['json' => ['moyen' => 'especes']]);
        self::assertResponseIsSuccessful();

        return $sale['id'];
    }

    /** @param array<string, mixed> $headers */
    private function validate(Client $client, array $headers, string $saleId): int
    {
        return $client->request('POST', '/api/ventes/' . $saleId . '/valider', $headers + ['json' => []])->getStatusCode();
    }

    /** @return list<array<string, mixed>> */
    private function alerts(?string $pointOfSaleId = null): array
    {
        $sql = 'SELECT * FROM platform_nf525_alert';
        $args = [];
        if ($pointOfSaleId !== null) {
            $sql .= ' WHERE chain_key = ?';
            $args[] = 'sales:' . $pointOfSaleId;
        }

        return $this->db()->fetchAllAssociative($sql . ' ORDER BY first_event_at', $args);
    }

    private function bells(): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM platform_notification WHERE source = ?', [self::BELL_SOURCE]);
    }

    /** @return array{0: string, 1: string} un second point de vente et sa caisse, sur l'établissement A */
    private function secondPointOfSale(): array
    {
        $site = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        $pointOfSale = (new PointDeVente())->setLibelle('Second guichet')->setEtablissement($site)
            ->setSeuilImpression(VenteFixtures::SEUIL_IMPRESSION)->setMoyensAutorises(VenteFixtures::MOYENS);
        $till = (new Caisse())->setLibelle('Caisse 2')->setPointDeVente($pointOfSale)->setEtat(EtatCaisse::Securisee);
        $this->em()->persist($pointOfSale);
        $this->em()->persist($till);
        $this->em()->flush();

        return [(string) $pointOfSale->getId(), (string) $till->getId()];
    }

    public function testABlockedChainRefusesTheSaleKeepsItOpenAndLeavesAnAlertWithoutTechnicalDetailInTheBody(): void
    {
        [$client, $headers] = $this->adminSurA();
        $client->disableReboot();
        $pos = $this->idPointDeVente();
        $sessionId = $this->openSession($client, $headers, $pos, $this->idCaisse());
        $saleId = $this->paidSale($client, $headers, $sessionId);
        $this->link($pos, self::FOREIGN_KEY);

        $status = $this->validate($client, $headers, $saleId);

        self::assertSame(409, $status);
        $detail = (string) $client->getResponse()->toArray(false)['detail'];
        $alerts = $this->alerts($pos);
        self::assertCount(1, $alerts, 'l\'alerte survit au rollback de la vente');
        self::assertSame(1, (int) $alerts[0]['refusal_count']);
        self::assertStringContainsString((string) $alerts[0]['reference'], $detail);
        self::assertStringNotContainsString('NF525_', $detail);
        self::assertStringNotContainsString(SealKeyring::keyIdOf(self::FOREIGN_KEY), $detail);
        self::assertStringNotContainsString(SealKeyring::keyIdOf($this->activeKey()), $detail);
        self::assertStringNotContainsString($pos, $detail);
        // Le rollback est prouvé par l'état de la vente (témoin : sans lui, l'alerte seule ne dirait rien).
        $sale = $client->request('GET', '/api/ventes/' . $saleId, $headers)->toArray();
        self::assertSame('en_cours', $sale['statut']);
        self::assertGreaterThan(0, $this->bells(), 'la cloche a sonné à l\'ouverture');
    }

    public function testControlTheCorrectKeyValidatesTheSaleWithoutAnyAlert(): void
    {
        [$client, $headers] = $this->adminSurA();
        $client->disableReboot();
        $pos = $this->idPointDeVente();
        $sessionId = $this->openSession($client, $headers, $pos, $this->idCaisse());
        $saleId = $this->paidSale($client, $headers, $sessionId);
        $this->link($pos, $this->activeKey());

        self::assertSame(201, $this->validate($client, $headers, $saleId));

        self::assertSame([], $this->alerts());
        self::assertSame(0, $this->bells());
    }

    public function testThreeRefusedSalesShareOneAlertAndOneWaveOfNotifications(): void
    {
        [$client, $headers] = $this->adminSurA();
        $client->disableReboot();
        $pos = $this->idPointDeVente();
        $sessionId = $this->openSession($client, $headers, $pos, $this->idCaisse());
        $sales = [$this->paidSale($client, $headers, $sessionId), $this->paidSale($client, $headers, $sessionId), $this->paidSale($client, $headers, $sessionId)];
        $this->link($pos, self::FOREIGN_KEY);

        self::assertSame(409, $this->validate($client, $headers, $sales[0]));
        $afterFirst = $this->bells();
        self::assertGreaterThan(0, $afterFirst, 'témoin : la première vague existe');
        self::assertSame(409, $this->validate($client, $headers, $sales[1]));
        self::assertSame(409, $this->validate($client, $headers, $sales[2]));

        $alerts = $this->alerts($pos);
        self::assertCount(1, $alerts);
        self::assertSame(3, (int) $alerts[0]['refusal_count']);
        self::assertLessThanOrEqual((string) $alerts[0]['last_event_at'], (string) $alerts[0]['first_event_at']);
        self::assertSame($afterFirst, $this->bells(), 'une seule vague de notifications');
    }

    public function testTwoPointsOfSaleOpenTwoAlerts(): void
    {
        [$client, $headers] = $this->adminSurA();
        $client->disableReboot();
        $first = $this->idPointDeVente();
        [$second, $till] = $this->secondPointOfSale();
        $saleA = $this->paidSale($client, $headers, $this->openSession($client, $headers, $first, $this->idCaisse()));
        $saleB = $this->paidSale($client, $headers, $this->openSession($client, $headers, $second, $till));
        $this->link($first, self::FOREIGN_KEY);
        $this->link($second, self::FOREIGN_KEY);

        self::assertSame(409, $this->validate($client, $headers, $saleA));
        self::assertSame(409, $this->validate($client, $headers, $saleB));

        self::assertCount(1, $this->alerts($first));
        self::assertCount(1, $this->alerts($second));
        self::assertCount(2, $this->alerts());
    }

    public function testResolvedOnTheNextSuccessfulSealThenANewRefusalOpensANewAlertAndRingsAgain(): void
    {
        [$client, $headers] = $this->adminSurA();
        $client->disableReboot();
        $pos = $this->idPointDeVente();
        $sessionId = $this->openSession($client, $headers, $pos, $this->idCaisse());
        $sales = [$this->paidSale($client, $headers, $sessionId), $this->paidSale($client, $headers, $sessionId), $this->paidSale($client, $headers, $sessionId)];
        $this->link($pos, self::FOREIGN_KEY);

        self::assertSame(409, $this->validate($client, $headers, $sales[0]));
        $open = $this->alerts($pos);
        self::assertSame('open', $open[0]['status']);
        $bellsAfterFirstAlert = $this->bells();

        // La clé est restaurée : la vente suivante passe et ferme l'alerte.
        $this->restoreKey($pos);
        self::assertSame(201, $this->validate($client, $headers, $sales[1]));
        $resolved = $this->alerts($pos);
        self::assertCount(1, $resolved);
        self::assertSame('resolved', $resolved[0]['status']);
        self::assertNotNull($resolved[0]['resolved_at']);

        // Le maillon n° 2 vient d'être scellé par la clé active : on le remplace par un maillon de clé étrangère.
        $this->db()->executeStatement('UPDATE nf525_operation_scellee SET signature = ?, seal_key_id = ? WHERE point_de_vente_id = ? AND numero_sequence = 2', [
            (new SealKeyring('NF525_SEAL_KEY', self::FOREIGN_KEY))->sign(str_repeat('a', 64)),
            SealKeyring::keyIdOf(self::FOREIGN_KEY),
            Uuid::fromString($pos)->toBinary(),
        ]);
        self::assertSame(409, $this->validate($client, $headers, $sales[2]));

        $all = $this->alerts($pos);
        self::assertCount(2, $all, 'une NOUVELLE alerte, l\'ancienne reste résolue');
        $statuses = array_column($all, 'status');
        sort($statuses);
        self::assertSame(['open', 'resolved'], $statuses);
        self::assertNotSame($all[0]['reference'], $all[1]['reference']);
        self::assertGreaterThan($bellsAfterFirstAlert, $this->bells(), 'la cloche sonne de nouveau');
    }

    public function testARuntimeExceptionInTheGuardsSurfacesAsIsWithoutAnyAlert(): void
    {
        $this->adminSurA();
        $pointOfSale = $this->em()->getRepository(PointDeVente::class)->find(Uuid::fromString($this->idPointDeVente()));
        self::assertNotNull($pointOfSale);
        $real = $this->em();
        // Une panne de lecture de l'ordre des clés (connexion) : ce n'est PAS un refus, rien ne doit l'avaler.
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(static fn (string $class) => $real->getRepository($class));
        $em->method('getConnection')->willThrowException(new \RuntimeException('panne-simulee-vente'));
        $ring = new SealKeyring('NF525_SEAL_KEY', $this->activeKey());
        $container = static::getContainer()->get(ScellementHandler::class);
        self::assertInstanceOf(ScellementHandler::class, $container);
        $handler = new ScellementHandler(
            $em,
            new HashChainSignataire($ring),
            $ring,
            (new \ReflectionProperty(ScellementHandler::class, 'alerts'))->getValue($container),
        );

        try {
            $handler->sceller(new OperationAScellerDto($pointOfSale, TypeOperationScellee::Vente, 'Vente', Uuid::v4(), ['montant' => '10.00']));
            self::fail('La panne aurait dû ressortir.');
        } catch (SealingRefusedException $e) {
            self::fail('Une panne ne doit pas devenir un refus : ' . $e->getMessage());
        } catch (\RuntimeException $e) {
            self::assertSame('panne-simulee-vente', $e->getMessage());
        }

        self::assertSame([], $this->alerts());
    }
}
