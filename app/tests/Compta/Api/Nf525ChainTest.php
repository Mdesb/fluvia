<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\Journal;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * US-L4-09, RG-M6-06 (CA-13) : chaque écriture porte une signature chaînée à la précédente ; une
 * rupture de chaîne est détectable.
 */
final class Nf525ChainTest extends ComptaApiTestCase
{
    public function testChaqueEcritureEstSigneeEtChaineeALaPrecedente(): void
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

        $reponse = $client->request('GET', '/api/compta/ecritures/verifier-chaine', $entete + [
            'query' => ['journal' => (string) $journal->getId()],
        ])->toArray();

        self::assertTrue($reponse['intacte']);
        self::assertSame(2, $reponse['nbOperations']);
        self::assertEmpty($reponse['anomalies']);
    }

    public function testRuptureDeChaineSimuleeEstDetectee(): void
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
        $connexion = $em->getConnection();
        $journal = $em->getRepository(Journal::class)->findOneBy(['code' => 'VTE']);
        self::assertNotNull($journal);

        // Altération directe en base (contourne le listener d'inaltérabilité applicatif) pour simuler
        // une rupture détectable par recalcul de l'empreinte (CA-13).
        $ecritures = $em->getRepository(EcritureComptable::class)->findBy(['journal' => $journal->getId()]);
        self::assertNotEmpty($ecritures);
        $connexion->executeStatement(
            'UPDATE compta_ecriture_comptable SET empreinte = ? WHERE id = ?',
            ['0000000000000000000000000000000000000000000000000000000000000000', $ecritures[0]->getId()->toBinary()],
        );

        $reponse = $client->request('GET', '/api/compta/ecritures/verifier-chaine', $entete + [
            'query' => ['journal' => (string) $journal->getId()],
        ])->toArray();

        self::assertFalse($reponse['intacte'], 'La rupture de chaîne doit être détectée (alerte de contrôle, CA-13).');
        self::assertNotEmpty($reponse['anomalies']);
    }
}
