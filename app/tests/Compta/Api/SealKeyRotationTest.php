<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\Journal;
use App\Compta\Nf525\ScellementEcritureHandler;
use App\Platform\Nf525\SealingRefusedException;
use App\Platform\Nf525\SealKeyring;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * #361 — TÉMOIN À DEUX CLÉS sur la chaîne comptable, contre la vraie base : rotation sans trousseau
 * = « clé inconnue » + scellement refusé ; rotation avec trousseau = chaîne vérifiable et prolongeable.
 */
final class SealKeyRotationTest extends ComptaApiTestCase
{
    private const OTHER_KEY = 'cle-de-test-rotation-compta';

    public function testRotationWithAndWithoutKeyring(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $this->creerVenteValidee($client, $entete, quantite: 1, sessionId: $session['id']);
        $this->creerVenteValidee($client, $entete, quantite: 1, sessionId: $session['id']);
        $client->request('POST', '/api/compta/ecritures/generer', $entete + [
            'json' => ['profilExploitant' => '/api/profil_exploitants/' . $this->idProfilExploitant()],
        ]);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $journal = $em->getRepository(Journal::class)->findOneBy(['code' => 'VTE']);
        self::assertNotNull($journal);
        $chaine = $em->getRepository(EcritureComptable::class)->findBy(['journal' => $journal->getId()], ['numeroSequence' => 'ASC']);
        self::assertCount(2, $chaine);

        $activeKey = $_SERVER['NF525_COMPTA_SEAL_KEY'] ?? $_ENV['NF525_COMPTA_SEAL_KEY'] ?? getenv('NF525_COMPTA_SEAL_KEY');
        self::assertIsString($activeKey);
        self::assertSame(SealKeyring::keyIdOf($activeKey), $chaine[1]->getSealKeyId(), 'l\'écriture porte l\'identifiant de sa clé');

        $nouvelle = (new EcritureComptable())
            ->setProfilExploitant($chaine[1]->getProfilExploitant())
            ->setJournal($journal);

        // Rotation SANS trousseau.
        $withoutKeyring = new ScellementEcritureHandler($em, new SealKeyring('NF525_COMPTA_SEAL_KEY', self::OTHER_KEY));
        $rapport = $withoutKeyring->verifieChaine($chaine);
        self::assertFalse($rapport['intacte']);
        self::assertStringContainsString('clé inconnue du trousseau', $rapport['anomalies'][0]['probleme']);
        try {
            $withoutKeyring->sceller($nouvelle);
            self::fail('Sceller par-dessus une chaîne invérifiable aurait dû être refusé.');
        } catch (SealingRefusedException $e) {
            self::assertStringContainsString('NF525_COMPTA_SEAL_RETIRED_KEYS', $e->getMessage());
        }
        self::assertSame(0, $nouvelle->getNumeroSequence(), 'rien n\'a été scellé');

        // Rotation AVEC trousseau.
        $withKeyring = new ScellementEcritureHandler($em, new SealKeyring('NF525_COMPTA_SEAL_KEY', self::OTHER_KEY, $activeKey));
        self::assertTrue($withKeyring->verifieChaine($chaine)['intacte']);
        $withKeyring->sceller($nouvelle);
        self::assertSame(3, $nouvelle->getNumeroSequence());
        self::assertSame(SealKeyring::keyIdOf(self::OTHER_KEY), $nouvelle->getSealKeyId());
        self::assertTrue($withKeyring->verifieChaine([...$chaine, $nouvelle])['intacte']);

        $em->clear(); // l'écriture scellée « à la main » n'est jamais persistée.
    }
}
