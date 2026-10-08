<?php

declare(strict_types=1);

namespace App\Tests\Crm\Api;

use ApiPlatform\Symfony\Bundle\Test\Client as HttpClient;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Client;
use App\Crm\Entity\PorteMonnaieVirtuel;
use App\Offre\DataFixtures\OffreFixtures;
use App\Tests\Crm\CrmApiTestCase;
use Doctrine\DBAL\Connection;

/**
 * Recrédit du porte-monnaie (RG-M4-03) par une contre-passation : jamais plus que la part PMV non
 * encore recréditée, ni plus que l'avoir (P-5 option A du plan ticket-opposable, à valider).
 *
 * Le défaut : chaque remboursement, même partiel, recréditait TOUTE la part PMV de la vente — 50 €
 * payés en PMV, deux remboursements partiels, 100 € rendus au porte-monnaie.
 *
 * Vente témoin : une carte et quatre entrées, 50.00 réglés en PMV (tout le solde des fixtures), le
 * reste en espèces ; le total laisse place à trois remboursements de 20.00.
 */
final class WalletPartialRefundTest extends CrmApiTestCase
{
    public function testPartialRefundsNeverRecreditMoreThanTheWalletShare(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->mixedSale($client, $entete);
        self::assertSame('0.00', $this->balance($client, $entete));

        foreach (['20.00', '40.00', '50.00'] as $attendu) {
            $this->refund($client, $entete, $vente, '20.00');
            self::assertSame($attendu, $this->balance($client, $entete), 'Chaque remboursement ne recrédite que sa part, dans la limite de la part PMV restante.');
        }
        self::assertSame('50.00', $this->recredited($client, $entete, $vente));
    }

    /** Témoin : le plafond ne retire rien au remboursement total. */
    public function testFullRefundRecreditsExactlyTheWalletShare(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->mixedSale($client, $entete);

        $this->refund($client, $entete, $vente, null);

        self::assertSame('50.00', $this->balance($client, $entete));
        self::assertSame('50.00', $this->recredited($client, $entete, $vente));
    }

    /** Témoin : une vente réglée sans porte-monnaie ne le recrédite jamais. */
    public function testSaleWithoutWalletRecreditsNothing(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->creerVenteAvecClient($client, $entete, '/api/clients/' . $this->idPayeur(), 4)['id'];
        $client->request('POST', '/api/ventes/' . $vente . '/paiements', $entete + ['json' => ['moyen' => 'especes']]);
        $client->request('POST', '/api/ventes/' . $vente . '/valider', $entete);
        self::assertResponseIsSuccessful();

        $this->refund($client, $entete, $vente, '5.00');
        $this->refund($client, $entete, $vente, null);

        self::assertSame('50.00', $this->balance($client, $entete));
        self::assertSame('0.00', $this->recredited($client, $entete, $vente));
    }

    public function testCancellationAfterPartialRefundRecreditsOnlyTheRest(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->mixedSale($client, $entete);
        $this->refund($client, $entete, $vente, '20.00');

        $client->request('POST', '/api/ventes/' . $vente . '/annuler', $entete + ['json' => ['motif' => 'Client parti']]);
        self::assertResponseStatusCodeSame(201);

        self::assertSame('50.00', $this->balance($client, $entete));
        self::assertSame('50.00', $this->recredited($client, $entete, $vente));
    }

    /**
     * Un remboursement concurrent tient la vente et a déjà recrédité 40.00, sans avoir validé : le
     * second attend, puis ne recrédite que les 10.00 restants. Sans verrou, il lisait « rien de
     * recrédité » avant la validation de l'autre et recréditait ses 20.00.
     */
    public function testConcurrentRefundIsAwaitedAndOnlyTheRestIsRecredited(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();
        $vente = $this->mixedSale($client, $entete);

        $concurrent = $this->holdSaleWhileRecrediting($vente, '40.00', $idA);
        $debut = microtime(true);
        $this->refund($client, $entete, $vente, '20.00');
        $attente = microtime(true) - $debut;
        $this->release($concurrent);

        self::assertGreaterThanOrEqual(1.5, $attente, 'Le remboursement doit attendre la contre-passation en cours sur la même vente.');
        self::assertSame('50.00', $this->balance($client, $entete));
        self::assertSame('50.00', $this->recredited($client, $entete, $vente));
    }

    /** @param array<string, mixed> $entete */
    private function mixedSale(HttpClient $client, array $entete): string
    {
        $vente = $this->creerVenteAvecClient($client, $entete, '/api/clients/' . $this->idPayeur(), 4)['id'];
        $client->request('POST', '/api/ventes/' . $vente . '/lignes', $entete + ['json' => [
            'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_CARTE),
            'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
            'quantite' => 1,
        ]]);
        $client->request('POST', '/api/ventes/' . $vente . '/paiements', $entete + ['json' => ['moyen' => 'pmv', 'montant' => '50.00']]);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/ventes/' . $vente . '/paiements', $entete + ['json' => ['moyen' => 'especes']]);
        $client->request('POST', '/api/ventes/' . $vente . '/valider', $entete);
        self::assertResponseIsSuccessful();
        $total = $client->request('GET', '/api/ventes/' . $vente, $entete)->toArray()['total'] ?? null;
        self::assertGreaterThanOrEqual(60.0, (float) $total, 'Trois remboursements de 20.00 doivent tenir dans le total.');

        return $vente;
    }

    /** @param array<string, mixed> $entete */
    private function refund(HttpClient $client, array $entete, string $vente, ?string $montant): void
    {
        $corps = ['motif' => 'Geste commercial'] + ($montant === null ? [] : ['montant' => $montant]);
        $client->request('POST', '/api/ventes/' . $vente . '/rembourser', $entete + ['json' => $corps]);
        self::assertResponseStatusCodeSame(201);
    }

    /** @param array<string, mixed> $entete */
    private function balance(HttpClient $client, array $entete): string
    {
        return $client->request('GET', '/api/clients/' . $this->idPayeur() . '/pmv', $entete)->toArray()['solde'];
    }

    /** @param array<string, mixed> $entete */
    private function recredited(HttpClient $client, array $entete, string $vente): string
    {
        $mouvements = $client->request('GET', '/api/clients/' . $this->idPayeur() . '/pmv/mouvements', $entete)->toArray()['mouvements'];
        $centimes = 0;
        foreach ($mouvements as $m) {
            if ($m['type'] === 'remboursement_vente' && $m['refVenteM2'] === $vente) {
                $centimes += (int) round((float) $m['montant'] * 100);
            }
        }

        return number_format($centimes / 100, 2, '.', '');
    }

    /**
     * Second processus, sur sa propre connexion : verrouille la vente comme une contre-passation en
     * cours, recrédite le PMV et journalise le mouvement, rend la main, attend 2 s, puis valide.
     *
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function holdSaleWhileRecrediting(string $vente, string $montant, string $etablissement): array
    {
        /** @var Connection $connexion */
        $connexion = static::getContainer()->get('doctrine')->getConnection();
        $p = $connexion->getParams();
        $payeur = $this->entite(Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL]);
        $pmv = (string) $this->entite(PorteMonnaieVirtuel::class, ['client' => $payeur])->getId();

        $code = <<<'PHP'
            [$dsn, $user, $pass, $vente, $pmv, $etab, $montant] = array_values(array_filter(array_slice($argv, 1), static fn ($a) => $a !== '--'));
            $hex = static fn (string $uuid): string => str_replace('-', '', $uuid);
            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->beginTransaction();
            $pdo->prepare('SELECT id FROM vente_vente WHERE id = UNHEX(?) FOR UPDATE')->execute([$hex($vente)]);
            $pdo->prepare('UPDATE crm_pmv SET solde = solde + ? WHERE id = UNHEX(?)')->execute([$montant, $hex($pmv)]);
            $pdo->prepare("INSERT INTO crm_mouvement_pmv (id, type, montant, solde_apres, date_mouvement, ref_vente_m2, motif, pmv_id, etablissement_id) SELECT UNHEX(REPLACE(UUID(), '-', '')), 'remboursement_vente', ?, solde, NOW(), UNHEX(?), 'concurrent', id, UNHEX(?) FROM crm_pmv WHERE id = UNHEX(?)")
                ->execute([$montant, $hex($vente), $hex($etab), $hex($pmv)]);
            echo "LOCKED\n";
            usleep(2000000);
            $pdo->commit();
            echo "COMMITTED\n";
            PHP;
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $p['host'] ?? 'localhost', $p['port'] ?? 3306, $p['dbname'] ?? '');
        $process = proc_open(
            [\PHP_BINARY, '-r', $code, '--', $dsn, (string) ($p['user'] ?? ''), (string) ($p['password'] ?? ''), $vente, $pmv, $etablissement, $montant],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process, 'Le processus concurrent n\'a pas démarré.');
        if (trim((string) fgets($pipes[1])) !== 'LOCKED') {
            $erreur = stream_get_contents($pipes[2]);
            proc_close($process);
            self::fail('Le processus concurrent ne tient pas la vente : ' . $erreur);
        }

        return [$process, $pipes];
    }

    /** @param array{0: resource, 1: array<int, resource>} $concurrent */
    private function release(array $concurrent): void
    {
        [$process, $pipes] = $concurrent;
        $sortie = stream_get_contents($pipes[1]);
        $erreur = stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process), 'Le processus concurrent a échoué : ' . $erreur);
        self::assertStringContainsString('COMMITTED', (string) $sortie);
    }
}
