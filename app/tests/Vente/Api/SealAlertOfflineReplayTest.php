<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Platform\Nf525\SealKeyring;
use App\Tests\Vente\VenteApiTestCase;
use App\Vente\Nf525\Entity\OperationScellee;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * G-8 / G-3 HORS LIGNE — la remontée d'un lot sur une chaîne bloquée ou rompue.
 *
 * ── LES PIÈGES ──────────────────────────────────────────────────────────────────────────────────
 *
 * - La quarantaine recopie `getMessage()` du refus de scellement : elle doit porter la référence de l'alerte
 *   et AUCUN détail technique (clé, identifiant de clé, UUID de point de vente), car elle est rendue au poste.
 * - Après une vente en quarantaine, `em->clear()` vide le gestionnaire : l'alerte est écrite par une seconde
 *   connexion, elle ne doit pas en dépendre. Trois refus = une alerte, `refusal_count` 3.
 * - « Aucune alerte » est vrai aussi d'une alerte qui ne s'écrit jamais : chaque cas négatif a son témoin
 *   positif (chaîne saine -> ventes insérées, quarantaine vide).
 */
final class SealAlertOfflineReplayTest extends VenteApiTestCase
{
    public const FOREIGN_KEY = 'cle-etrangere-alerte-hors-ligne';

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

    private function activeKey(): string
    {
        $key = $_SERVER['NF525_SEAL_KEY'] ?? $_ENV['NF525_SEAL_KEY'] ?? getenv('NF525_SEAL_KEY');
        self::assertIsString($key);

        return $key;
    }

    /** Un maillon de séquence 1, signé par `$key`. */
    private function link(string $pointOfSaleId, string $key): void
    {
        $fingerprint = hash('sha256', $pointOfSaleId . '|1');
        $ring = new SealKeyring('NF525_SEAL_KEY', $key);
        $this->db()->executeStatement(
            'INSERT INTO nf525_operation_scellee (id, type_operation, cible_type, cible_id, numero_sequence, empreinte, signature, seal_key_id, payload_canonique, horodatage, point_de_vente_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)',
            [Uuid::v7()->toBinary(), 'vente', 'Vente', Uuid::v7()->toBinary(), 1, $fingerprint, $ring->sign($fingerprint), $ring->activeKeyId(), '{}', Uuid::fromString($pointOfSaleId)->toBinary()],
        );
    }

    /**
     * Le rejeu vérifie la chaîne UNE FOIS, puis scelle chaque vente. Un maillon d'une clé inconnue est déjà « rompu »
     * pour la vérification : pour atteindre la porte de scellement, la chaîne se bloque donc PENDANT le lot. Le maillon
     * de la première vente, écrit par le flush, est re-signé par une clé étrangère dans le même flush : les ventes
     * suivantes sont refusées (clé absente du trousseau).
     */
    private function blockTheChainAfterTheFirstLink(): void
    {
        $db = $this->db();
        $this->em()->getEventManager()->addEventListener([Events::postPersist, Events::postFlush], new class($db) {
            /** @var list<OperationScellee> */
            private array $written = [];

            public function __construct(private readonly Connection $db)
            {
            }

            public function postPersist(PostPersistEventArgs $args): void
            {
                if ($args->getObject() instanceof OperationScellee) {
                    $this->written[] = $args->getObject();
                }
            }

            // Après le flush (l'entité est en base) : re-signature par la clé étrangère, puis relecture de l'entité gérée,
            // que la vente suivante lirait sinon dans l'identity map avec son ancienne clé.
            public function postFlush(PostFlushEventArgs $args): void
            {
                $links = $this->written;
                $this->written = [];
                foreach ($links as $link) {
                    $this->db->executeStatement(
                        'UPDATE nf525_operation_scellee SET signature = ?, seal_key_id = ? WHERE id = ?',
                        [(new SealKeyring('NF525_SEAL_KEY', SealAlertOfflineReplayTest::FOREIGN_KEY))->sign($link->getEmpreinte()), SealKeyring::keyIdOf(SealAlertOfflineReplayTest::FOREIGN_KEY), $link->getId()->toBinary()],
                    );
                    $args->getObjectManager()->refresh($link);
                }
            }
        });
    }

    /** @return array<string, mixed> */
    private function operation(int $sequence): array
    {
        return [
            'cleIdempotence' => (string) Uuid::v4(),
            'sequenceLocale' => $sequence,
            'numeroLocal' => sprintf('HL-TEST-%05d', $sequence),
            'lignes' => [[
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_CARTE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ]],
            'paiements' => [['moyen' => 'especes', 'montant' => '45.00']],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function alerts(): array
    {
        return $this->db()->fetchAllAssociative('SELECT * FROM platform_nf525_alert ORDER BY first_event_at');
    }

    public function testThreeSalesOnAChainBlockedDuringTheBatchGoToQuarantineWithTheReferenceAndNoTechnicalDetail(): void
    {
        [$client, $headers] = $this->adminSurA();
        $client->disableReboot();
        $session = $this->ouvrirSession($client, $headers);
        $pos = $this->idPointDeVente();
        $this->blockTheChainAfterTheFirstLink();

        $response = $client->request('POST', '/api/synchro/operations', $headers + ['json' => [
            'session' => '/api/session_caisses/' . $session['id'],
            'operations' => [$this->operation(1), $this->operation(2), $this->operation(3), $this->operation(4)],
        ]])->toArray();

        self::assertCount(1, $response['inseres'], 'témoin positif : la première vente est scellée, la chaîne se bloque ensuite');
        self::assertCount(3, $response['quarantaine']);
        $alerts = $this->alerts();
        self::assertCount(1, $alerts, 'une seule alerte pour les trois refus, malgré le vidage du gestionnaire entre deux ventes');
        self::assertSame(3, (int) $alerts[0]['refusal_count']);
        self::assertSame('open', $alerts[0]['status']);
        foreach ($response['quarantaine'] as $entry) {
            $reason = (string) $entry['raison'];
            self::assertStringContainsString((string) $alerts[0]['reference'], $reason);
            self::assertStringContainsString('NF525-', $reason);
            self::assertStringNotContainsString('NF525_', $reason);
            self::assertStringNotContainsString(SealKeyring::keyIdOf(self::FOREIGN_KEY), $reason);
            self::assertStringNotContainsString(SealKeyring::keyIdOf($this->activeKey()), $reason);
            self::assertStringNotContainsString($pos, $reason);
        }
    }

    public function testControlAReplayOnAHealthyChainInsertsEverythingWithoutAnyAlert(): void
    {
        [$client, $headers] = $this->adminSurA();
        $client->disableReboot();
        $session = $this->ouvrirSession($client, $headers);
        // Une vraie chaîne d'un maillon, ancrée par une première remontée.
        $client->request('POST', '/api/synchro/operations', $headers + ['json' => [
            'session' => '/api/session_caisses/' . $session['id'],
            'operations' => [$this->operation(1)],
        ]]);
        self::assertResponseIsSuccessful();

        $response = $client->request('POST', '/api/synchro/operations', $headers + ['json' => [
            'session' => '/api/session_caisses/' . $session['id'],
            'operations' => [$this->operation(2), $this->operation(3), $this->operation(4)],
        ]])->toArray();

        self::assertCount(3, $response['inseres']);
        self::assertSame([], $response['quarantaine']);
        self::assertSame([], $this->alerts());
    }

    public function testABrokenServerChainRefusesTheAnchorWithAGenericMessageAndTheReferenceThenARepairedChainResolvesTheAlert(): void
    {
        [$client, $headers] = $this->adminSurA();
        $client->disableReboot();
        $session = $this->ouvrirSession($client, $headers);
        $pos = $this->idPointDeVente();
        $sessionRef = '/api/session_caisses/' . $session['id'];
        $client->request('POST', '/api/synchro/operations', $headers + ['json' => ['session' => $sessionRef, 'operations' => [$this->operation(1)]]]);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->alerts(), 'témoin : la chaîne saine n\'ouvre rien');
        $batch = ['session' => $sessionRef, 'operations' => [$this->operation(2)]];

        // Maillon altéré : l'empreinte ne correspond plus au contenu.
        $original = (string) $this->db()->fetchOne('SELECT empreinte FROM nf525_operation_scellee WHERE numero_sequence = 1');
        $this->db()->executeStatement('UPDATE nf525_operation_scellee SET empreinte = ? WHERE numero_sequence = 1', [str_repeat('b', 64)]);
        $this->em()->clear();

        $client->request('POST', '/api/synchro/operations', $headers + ['json' => $batch]);
        self::assertResponseStatusCodeSame(409);
        $detail = (string) $client->getResponse()->toArray(false)['detail'];
        $alerts = $this->alerts();
        self::assertCount(1, $alerts);
        self::assertSame('chain_broken', $alerts[0]['cause']);
        self::assertSame('verification', $alerts[0]['origin']);
        self::assertSame('open', $alerts[0]['status']);
        // Message générique, exact : aucun détail du rapport de vérification (il vit dans l'alerte, côté éditeur).
        self::assertSame(
            'Chaîne serveur rompue : ancrage refusé (alerte de contrôle NF525). Référence de l\'alerte : ' . $alerts[0]['reference'] . '.',
            $detail,
        );
        self::assertStringContainsString('altérée', (string) $alerts[0]['technical_detail'], 'témoin : le détail technique est bien dans l\'alerte');
        self::assertStringContainsString((string) $alerts[0]['reference'], $detail);
        self::assertStringNotContainsString('NF525_', $detail);
        self::assertStringNotContainsString($pos, $detail);
        self::assertStringNotContainsString(str_repeat('b', 64), $detail);

        // Chaîne réparée : la remontée suivante est ancrée et ferme l'alerte de vérification.
        $this->db()->executeStatement('UPDATE nf525_operation_scellee SET empreinte = ? WHERE numero_sequence = 1', [$original]);
        $this->em()->clear();
        $response = $client->request('POST', '/api/synchro/operations', $headers + ['json' => $batch])->toArray();
        self::assertCount(1, $response['inseres']);
        $after = $this->alerts();
        self::assertCount(1, $after);
        self::assertSame('resolved', $after[0]['status']);
    }
}
