<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Organisation\Entity\Etablissement;
use App\Platform\Nf525\SealKeyring;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Vente\VenteApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * G-3 / G-7 (ORIGINE VÉRIFICATION), CHAÎNE DE VENTE — `POST /nf525/verifier-chaine` ouvre, met à jour et ferme l'alerte.
 *
 * ── LES PIÈGES ──────────────────────────────────────────────────────────────────────────────────
 *
 * - Un maillon altéré par UPDATE SQL est suivi d'un `em->clear()` : sans lui, la vérification relit l'identity map.
 * - L'altération porte sur le maillon 1 de deux : le dernier maillon (le seul que la porte de scellement regarde)
 *   reste sain, donc un scellement RÉUSSIT sur une chaîne que la vérification juge rompue (témoin de l'amendement G-7).
 * - « Aucune alerte » est vrai aussi d'une alerte qui ne s'écrit jamais : chaque cas négatif a son témoin positif.
 */
final class SealAlertVerificationTest extends VenteApiTestCase
{
    private const BELL_SOURCE = 'nf525.chain_blocked';
    private const FOREIGN_KEY = 'cle-etrangere-alerte-verification-vente';

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

    /** @param array<string, mixed> $headers */
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

    /**
     * Une chaîne réelle de deux maillons, et une troisième vente prête à valider.
     *
     * @param array<string, mixed> $headers
     *
     * @return string l'identifiant de la vente en attente
     */
    private function twoLinksAndAPendingSale(Client $client, array $headers, string $sessionId): string
    {
        $first = $this->paidSale($client, $headers, $sessionId);
        $second = $this->paidSale($client, $headers, $sessionId);
        $pending = $this->paidSale($client, $headers, $sessionId);
        self::assertSame(201, $this->validate($client, $headers, $first));
        self::assertSame(201, $this->validate($client, $headers, $second));

        return $pending;
    }

    /** @param array<string, mixed> $headers */
    private function verify(Client $client, array $headers, ?string $pointOfSaleId = null): int
    {
        return $client->request('POST', '/api/nf525/verifier-chaine', $headers + [
            'json' => ['pointDeVente' => '/api/point_de_ventes/' . ($pointOfSaleId ?? $this->idPointDeVente())],
        ])->getStatusCode();
    }

    private function tamper(): string
    {
        $original = (string) $this->db()->fetchOne('SELECT empreinte FROM nf525_operation_scellee WHERE numero_sequence = 1');
        self::assertNotSame('', $original, 'témoin : le maillon 1 existe');
        $this->db()->executeStatement('UPDATE nf525_operation_scellee SET empreinte = ? WHERE numero_sequence = 1', [str_repeat('b', 64)]);
        $this->em()->clear();

        return $original;
    }

    private function repair(string $original): void
    {
        $this->db()->executeStatement('UPDATE nf525_operation_scellee SET empreinte = ? WHERE numero_sequence = 1', [$original]);
        $this->em()->clear();
    }

    private function signLink(int $sequence, string $key): void
    {
        $fingerprint = (string) $this->db()->fetchOne('SELECT empreinte FROM nf525_operation_scellee WHERE numero_sequence = ?', [$sequence]);
        $ring = new SealKeyring('NF525_SEAL_KEY', $key);
        $this->db()->executeStatement(
            'UPDATE nf525_operation_scellee SET signature = ?, seal_key_id = ? WHERE numero_sequence = ?',
            [$ring->sign($fingerprint), $ring->activeKeyId(), $sequence],
        );
        $this->em()->clear();
    }

    /** @return list<array<string, mixed>> */
    private function alerts(): array
    {
        return $this->db()->fetchAllAssociative('SELECT * FROM platform_nf525_alert ORDER BY first_event_at');
    }

    private function bells(): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM platform_notification WHERE source = ?', [self::BELL_SOURCE]);
    }

    public function testABrokenChainOpensTheAlertAndRingsTheBellWhileTheResponseIsUnchanged(): void
    {
        [$client, $headers] = $this->adminSurA();
        $client->disableReboot();
        $pos = $this->idPointDeVente();
        $this->twoLinksAndAPendingSale($client, $headers, $this->ouvrirSession($client, $headers)['id']);
        $this->tamper();

        self::assertSame(409, $this->verify($client, $headers));

        $body = $client->getResponse()->toArray(false);
        self::assertFalse($body['intacte']);
        self::assertSame($pos, $body['pointDeVente']);
        $alerts = $this->alerts();
        self::assertCount(1, $alerts);
        self::assertSame('sales:' . $pos, $alerts[0]['chain_key']);
        self::assertSame('chain_broken', $alerts[0]['cause']);
        self::assertSame('verification', $alerts[0]['origin']);
        self::assertSame('open', $alerts[0]['status']);
        self::assertSame(1, (int) $alerts[0]['detection_count']);
        self::assertStringContainsString('n°1', (string) $alerts[0]['technical_detail'], 'témoin : le détail technique est dans l\'alerte');
        self::assertGreaterThan(0, $this->bells());
    }

    public function testASecondVerificationUpdatesTheSameAlertWithoutANewBell(): void
    {
        [$client, $headers] = $this->adminSurA();
        $client->disableReboot();
        $this->twoLinksAndAPendingSale($client, $headers, $this->ouvrirSession($client, $headers)['id']);
        $this->tamper();

        self::assertSame(409, $this->verify($client, $headers));
        $bells = $this->bells();
        self::assertGreaterThan(0, $bells, 'témoin : la première vague existe');
        self::assertSame(409, $this->verify($client, $headers));

        $alerts = $this->alerts();
        self::assertCount(1, $alerts);
        self::assertSame(2, (int) $alerts[0]['detection_count']);
        self::assertSame($bells, $this->bells());
    }

    public function testAFixedLinkResolvesTheAlertAndANewBreakOpensANewOneWithANewBell(): void
    {
        [$client, $headers] = $this->adminSurA();
        $client->disableReboot();
        $this->twoLinksAndAPendingSale($client, $headers, $this->ouvrirSession($client, $headers)['id']);
        $original = $this->tamper();
        self::assertSame(409, $this->verify($client, $headers));
        $bells = $this->bells();

        $this->repair($original);
        self::assertSame(200, $this->verify($client, $headers));

        $alerts = $this->alerts();
        self::assertCount(1, $alerts);
        self::assertSame('resolved', $alerts[0]['status']);
        self::assertNotNull($alerts[0]['resolved_at']);

        $this->tamper();
        self::assertSame(409, $this->verify($client, $headers));
        $all = $this->alerts();
        self::assertCount(2, $all, 'une NOUVELLE alerte, l\'ancienne reste résolue');
        $statuses = array_column($all, 'status');
        sort($statuses);
        self::assertSame(['open', 'resolved'], $statuses);
        self::assertGreaterThan($bells, $this->bells(), 'la cloche sonne de nouveau');
    }

    public function testASuccessfulSealLeavesTheVerificationAlertOpenAndOnlyAnIntactVerificationClosesIt(): void
    {
        [$client, $headers] = $this->adminSurA();
        $client->disableReboot();
        $pending = $this->twoLinksAndAPendingSale($client, $headers, $this->ouvrirSession($client, $headers)['id']);
        $original = $this->tamper();
        self::assertSame(409, $this->verify($client, $headers));

        self::assertSame(201, $this->validate($client, $headers, $pending), 'témoin : le scellement réussit (le dernier maillon est sain)');

        $alerts = $this->alerts();
        self::assertCount(1, $alerts);
        self::assertSame('open', $alerts[0]['status'], 'un scellement réussi ne ferme pas une alerte de vérification');

        $this->repair($original);
        self::assertSame(200, $this->verify($client, $headers));
        self::assertSame('resolved', $this->alerts()[0]['status']);
    }

    public function testAMixedAlertStaysOpenAfterASuccessfulSeal(): void
    {
        [$client, $headers] = $this->adminSurA();
        $client->disableReboot();
        $pending = $this->twoLinksAndAPendingSale($client, $headers, $this->ouvrirSession($client, $headers)['id']);
        $this->tamper();
        // 1. Refus : le dernier maillon est signé par une clé étrangère.
        $this->signLink(2, self::FOREIGN_KEY);
        self::assertSame(409, $this->validate($client, $headers, $pending));
        self::assertSame('refusal', $this->alerts()[0]['origin']);

        // 2. Vérification rompue : l'alerte passe à l'origine « verification » (la plus stricte).
        self::assertSame(409, $this->verify($client, $headers));
        $alerts = $this->alerts();
        self::assertCount(1, $alerts);
        self::assertSame('verification', $alerts[0]['origin']);

        // 3. La clé est restaurée : le scellement réussit, mais le maillon 1 reste altéré : l'alerte reste ouverte.
        $this->signLink(2, $this->activeKey());
        self::assertSame(201, $this->validate($client, $headers, $pending));
        $alerts = $this->alerts();
        self::assertCount(1, $alerts);
        self::assertSame('open', $alerts[0]['status']);
    }

    public function testControlAnIntactChainOpensNoAlertAndAnIntactVerificationWritesNothing(): void
    {
        [$client, $headers] = $this->adminSurA();
        $client->disableReboot();
        $this->twoLinksAndAPendingSale($client, $headers, $this->ouvrirSession($client, $headers)['id']);

        self::assertSame(200, $this->verify($client, $headers));

        self::assertTrue($client->getResponse()->toArray(false)['intacte']);
        self::assertSame(2, $client->getResponse()->toArray(false)['nbOperations'], 'témoin : la vérification a bien porté sur la chaîne');
        self::assertSame([], $this->alerts());
        self::assertSame(0, $this->bells());
    }

    public function testARequestRefusedWithA404OpensNothing(): void
    {
        [$client, $headers] = $this->adminSurA();
        $client->disableReboot();
        $pos = $this->idPointDeVente();
        $this->twoLinksAndAPendingSale($client, $headers, $this->ouvrirSession($client, $headers)['id']);
        $this->tamper();

        [$email, $password] = $this->readerOnB();
        $clientB = static::createClient();
        $clientB->disableReboot();
        $headersB = ['auth_bearer' => $this->jeton($clientB, $email, $password), 'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_B_NOM)]];

        self::assertSame(404, $this->verify($clientB, $headersB, $pos));
        self::assertSame([], $this->alerts(), 'une requête refusée n\'ouvre rien');

        // Témoin positif : la même chaîne vérifiée par son propriétaire ouvre bien l'alerte.
        self::assertSame(409, $this->verify($client, $headers));
        self::assertCount(1, $this->alerts());
    }

    /** @return array{0: string, 1: string} */
    private function readerOnB(): array
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em = $this->em();
        $siteB = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertInstanceOf(Etablissement::class, $siteB);
        $permission = $em->getRepository(Permission::class)->findOneBy(['module' => 'caisse', 'action' => 'lire']);
        self::assertInstanceOf(Permission::class, $permission);
        $role = (new Role())->setNom('Lecteur caisse B (alerte)');
        $role->addPermission($permission);
        $em->persist($role);
        $email = 'caisse-b-' . uniqid() . '@itcotation.com';
        $user = (new Utilisateur())->setEmail($email)->setNom('Lecteur Caisse B')->setActif(true);
        $user->setMotDePasse($hasher->hashPassword($user, 'aaa'));
        $em->persist($user);
        $em->persist((new Affectation())->setUtilisateur($user)->setRole($role)->setEtablissement($siteB));
        $em->flush();

        return [$email, 'aaa'];
    }

    public function testTheChainIdentityComesFromTheServerNotFromTheBody(): void
    {
        [$client, $headers] = $this->adminSurA();
        $client->disableReboot();
        $pos = $this->idPointDeVente();
        $this->twoLinksAndAPendingSale($client, $headers, $this->ouvrirSession($client, $headers)['id']);
        $this->tamper();

        $client->request('POST', '/api/nf525/verifier-chaine', $headers + ['json' => [
            'pointDeVente' => '/api/point_de_ventes/' . $pos,
            'chain_key' => 'sales:' . Uuid::v4()->toRfc4122(),
            'chainKey' => 'accounting:x',
        ]]);

        $alerts = $this->alerts();
        self::assertCount(1, $alerts);
        self::assertSame('sales:' . $pos, $alerts[0]['chain_key']);
    }
}
