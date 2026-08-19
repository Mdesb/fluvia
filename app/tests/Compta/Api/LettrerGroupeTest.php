<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\LettrageEcriture;
use App\Compta\Entity\LigneEcriture;
use App\Tests\Compta\ComptaApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use Doctrine\ORM\EntityManagerInterface;

/**
 * US-L4-14, RG-M6-14 : `POST /compta/lettrages/groupe` — lettrage groupé (CA-5/CA-6, §4.4 spec).
 */
final class LettrerGroupeTest extends ComptaApiTestCase
{
    /** @param array<string, mixed> $entete */
    private function idLigne(array $entete, string $idEcriture, string $numeroCompte): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $ecriture = $em->getRepository(EcritureComptable::class)->find($idEcriture);
        self::assertNotNull($ecriture);
        foreach ($ecriture->getLignes() as $ligne) {
            if ($ligne->getCompte()?->getNumero() === $numeroCompte) {
                return (string) $ligne->getId();
            }
        }
        self::fail(sprintf('Aucune ligne trouvée pour le compte %s.', $numeroCompte));
    }

    /** @param array<string, mixed> $entete */
    private function creerFactureFournisseur(Client $client, array $entete): string
    {
        return $client->request('POST', '/api/compta/journal-entries/manual', $entete + [
            'json' => [
                'businessProfile' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
                'journal' => '/api/journals/' . $this->idJournal('OD'),
                'date' => '2026-08-19',
                'label' => 'Facture fournisseur',
                'lines' => [
                    ['account' => '/api/compte_comptables/' . $this->idCompte('627000'), 'debit' => '500.00', 'vatRate' => '/api/taux_tvas/' . $this->idTauxHorsChamp()],
                    ['account' => '/api/compte_comptables/' . $this->idCompte('401000'), 'credit' => '500.00', 'vatRate' => '/api/taux_tvas/' . $this->idTauxHorsChamp()],
                ],
            ],
        ])->toArray()['id'];
    }

    /** @param array<string, mixed> $entete */
    private function creerPaiementFournisseur(Client $client, array $entete): string
    {
        return $client->request('POST', '/api/compta/journal-entries/manual', $entete + [
            'json' => [
                'businessProfile' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
                'journal' => '/api/journals/' . $this->idJournal('OD'),
                'date' => '2026-08-19',
                'label' => 'Paiement fournisseur',
                'lines' => [
                    ['account' => '/api/compte_comptables/' . $this->idCompte('401000'), 'debit' => '500.00', 'vatRate' => '/api/taux_tvas/' . $this->idTauxHorsChamp()],
                    ['account' => '/api/compte_comptables/' . $this->idCompte('512000'), 'credit' => '500.00', 'vatRate' => '/api/taux_tvas/' . $this->idTauxHorsChamp()],
                ],
            ],
        ])->toArray()['id'];
    }

    public function testDeuxLignesMemeMontantMemeReconciliationCode(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->ouvrirPeriode($client, $entete, '2026-08-01', '2026-08-31');

        $facture = $this->creerFactureFournisseur($client, $entete);
        $paiement = $this->creerPaiementFournisseur($client, $entete);

        $ligneCredit = $this->idLigne($entete, $facture, '401000');
        $ligneDebit = $this->idLigne($entete, $paiement, '401000');

        $reponse = $client->request('POST', '/api/compta/lettrages/groupe', $entete + [
            'json' => ['lines' => ['/api/ligne_ecritures/' . $ligneCredit, '/api/ligne_ecritures/' . $ligneDebit]],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertCount(2, $reponse);
        self::assertNotEmpty($reponse[0]['reconciliationCode']);
        self::assertSame($reponse[0]['reconciliationCode'], $reponse[1]['reconciliationCode']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $nb = (int) $em->getRepository(LettrageEcriture::class)->createQueryBuilder('l')->select('COUNT(l.id)')->getQuery()->getSingleScalarResult();
        self::assertSame(2, $nb);
    }

    public function testGroupeDesequilibreRejete422(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->ouvrirPeriode($client, $entete, '2026-08-01', '2026-08-31');

        $facture = $this->creerFactureFournisseur($client, $entete);

        $ligneCredit401 = $this->idLigne($entete, $facture, '401000');

        // Ligne facture (crédit 500) rapprochée à un paiement PARTIEL (débit 300) : total débit (300)
        // ≠ total crédit (500) -> rejet 422 (CA-6).
        $paiementPartiel = $client->request('POST', '/api/compta/journal-entries/manual', $entete + [
            'json' => [
                'businessProfile' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
                'journal' => '/api/journals/' . $this->idJournal('OD'),
                'date' => '2026-08-19',
                'label' => 'Paiement partiel',
                'lines' => [
                    ['account' => '/api/compte_comptables/' . $this->idCompte('401000'), 'debit' => '300.00', 'vatRate' => '/api/taux_tvas/' . $this->idTauxHorsChamp()],
                    ['account' => '/api/compte_comptables/' . $this->idCompte('512000'), 'credit' => '300.00', 'vatRate' => '/api/taux_tvas/' . $this->idTauxHorsChamp()],
                ],
            ],
        ])->toArray()['id'];
        $lignePaiementPartiel = $this->idLigne($entete, $paiementPartiel, '401000');

        $client->request('POST', '/api/compta/lettrages/groupe', $entete + [
            'json' => ['lines' => ['/api/ligne_ecritures/' . $ligneCredit401, '/api/ligne_ecritures/' . $lignePaiementPartiel]],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testMoinsDeDeuxLignesRejete422(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->ouvrirPeriode($client, $entete, '2026-08-01', '2026-08-31');

        $facture = $this->creerFactureFournisseur($client, $entete);
        $ligneCredit = $this->idLigne($entete, $facture, '401000');

        $client->request('POST', '/api/compta/lettrages/groupe', $entete + [
            'json' => ['lines' => ['/api/ligne_ecritures/' . $ligneCredit]],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testLigneDejaLettreeRejetee409(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->ouvrirPeriode($client, $entete, '2026-08-01', '2026-08-31');

        $facture = $this->creerFactureFournisseur($client, $entete);
        $paiement = $this->creerPaiementFournisseur($client, $entete);
        $ligneCredit = $this->idLigne($entete, $facture, '401000');
        $ligneDebit = $this->idLigne($entete, $paiement, '401000');

        $client->request('POST', '/api/compta/lettrages/groupe', $entete + [
            'json' => ['lines' => ['/api/ligne_ecritures/' . $ligneCredit, '/api/ligne_ecritures/' . $ligneDebit]],
        ]);
        self::assertResponseIsSuccessful();

        $paiement2 = $this->creerPaiementFournisseur($client, $entete);
        $ligneDebit2 = $this->idLigne($entete, $paiement2, '401000');

        $client->request('POST', '/api/compta/lettrages/groupe', $entete + [
            'json' => ['lines' => ['/api/ligne_ecritures/' . $ligneCredit, '/api/ligne_ecritures/' . $ligneDebit2]],
        ]);

        self::assertResponseStatusCodeSame(409);
    }
}
