<?php

declare(strict_types=1);

namespace App\Tests\Facturation;

use App\Facturation\Einvoicing\InvoiceIssuerSnapshot;
use App\Facturation\Entity\Facture;
use App\Facturation\Nf525\ScellementFactureHandler;
use App\Platform\Nf525\SealingRefusedException;
use App\Platform\Nf525\SealKeyring;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * #361 — TÉMOIN À DEUX CLÉS sur la chaîne de facturation, contre la vraie base :
 *  - les documents émis portent l'identifiant de la clé active (jamais la clé) ;
 *  - rotation SANS trousseau : « clé inconnue du trousseau » à la vérification, scellement REFUSÉ ;
 *  - rotation AVEC trousseau (ancienne clé retirée) : chaîne vérifiable, et prolongeable ;
 *  - la commande de déploiement `app:nf525:check-keys` passe sur une base saine et échoue sur un
 *    dernier maillon dont la clé n'est pas au trousseau.
 */
final class SealKeyRotationTest extends FacturationApiTestCase
{
    private const OTHER_KEY = 'cle-de-test-rotation-facturation';

    public function testRotationWithAndWithoutKeyring(): void
    {
        [$client, $entete] = $this->adminSurA();
        $f1 = $this->emettre($client, $entete, 100.0);
        $f2 = $this->emettre($client, $entete, 200.0);
        $brouillon = $client->request('POST', '/api/factures', $entete + ['json' => $this->corpsFactureDirecte(300.0)])->toArray();

        $em = $this->em();
        $e1 = $this->facture($f1);
        $e2 = $this->facture($f2);
        $activeKey = $this->activeKey();
        self::assertSame(SealKeyring::keyIdOf($activeKey), $e2->getSealKeyId(), 'le document émis porte l\'identifiant de sa clé');

        /** @var InvoiceIssuerSnapshot $snapshot */
        $snapshot = static::getContainer()->get(InvoiceIssuerSnapshot::class);

        // Rotation SANS trousseau.
        $withoutKeyring = new ScellementFactureHandler($em, new SealKeyring('NF525_FACTURATION_SEAL_KEY', self::OTHER_KEY), $snapshot);
        $rapport = $withoutKeyring->verifieChaine([$e1, $e2]);
        self::assertFalse($rapport['intacte']);
        self::assertStringContainsString('clé inconnue du trousseau', $rapport['anomalies'][0]['probleme']);

        $draft = $this->facture($brouillon['id']);
        try {
            $withoutKeyring->sceller($draft);
            self::fail('Sceller par-dessus une chaîne invérifiable aurait dû être refusé.');
        } catch (SealingRefusedException $e) {
            self::assertStringContainsString('NF525_FACTURATION_SEAL_RETIRED_KEYS', $e->getMessage());
        }
        self::assertSame('', $draft->getEmpreinte(), 'rien n\'a été scellé');

        // Rotation AVEC trousseau : l'ancienne clé, retirée, vérifie ; la nouvelle signe.
        $withKeyring = new ScellementFactureHandler($em, new SealKeyring('NF525_FACTURATION_SEAL_KEY', self::OTHER_KEY, $activeKey), $snapshot);
        self::assertTrue($withKeyring->verifieChaine([$e1, $e2])['intacte']);
        $withKeyring->sceller($draft);
        self::assertSame($e2->getNumeroSequence() + 1, $draft->getNumeroSequence());
        self::assertSame(SealKeyring::keyIdOf(self::OTHER_KEY), $draft->getSealKeyId());
        $rapport = $withKeyring->verifieChaine([$e1, $e2, $draft]);
        self::assertTrue($rapport['intacte'], json_encode($rapport['anomalies'], \JSON_UNESCAPED_UNICODE));

        $em->clear(); // rien n'est écrit : le document scellé « à la main » n'est jamais persisté.
    }

    public function testDeployCheckCommand(): void
    {
        [$client, $entete] = $this->adminSurA();
        $f1 = $this->emettre($client, $entete, 100.0);

        $tester = new CommandTester((new Application(static::$kernel))->find('app:nf525:check-keys'));

        // Témoin : base saine, commande verte.
        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());

        // Le dernier document désigne une clé que le trousseau ne contient pas.
        $this->em()->getConnection()->executeStatement(
            'UPDATE facturation_facture SET seal_key_id = ? WHERE id = ?',
            ['ffffffffffffffff', Uuid::fromString($f1)->toBinary()],
        );
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('clé inconnue du trousseau', $tester->getDisplay());
        self::assertStringContainsString('REFUSERA', $tester->getDisplay());
    }

    /** @param array<string, mixed> $entete */
    private function emettre(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $entete, float $montant): string
    {
        $brouillon = $client->request('POST', '/api/factures', $entete + ['json' => $this->corpsFactureDirecte($montant)])->toArray();
        $emise = $client->request('POST', '/api/factures/' . $brouillon['id'] . '/emettre', $entete)->toArray();

        return (string) $emise['id'];
    }

    private function facture(string $id): Facture
    {
        $facture = $this->em()->getRepository(Facture::class)->find(Uuid::fromString($id));
        self::assertNotNull($facture);

        return $facture;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }

    /** La clé de TEST de l'environnement de test (jamais affichée). */
    private function activeKey(): string
    {
        $key = $_SERVER['NF525_FACTURATION_SEAL_KEY'] ?? $_ENV['NF525_FACTURATION_SEAL_KEY'] ?? getenv('NF525_FACTURATION_SEAL_KEY');
        self::assertIsString($key);
        self::assertNotSame('', $key);

        return $key;
    }
}
