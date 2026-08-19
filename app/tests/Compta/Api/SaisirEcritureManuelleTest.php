<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Compta\Entity\CompteComptable;
use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\TauxTva;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * US-L4-11, RG-M6-11 : `POST /compta/journal-entries/manual` — saisie d'une écriture manuelle libre
 * (OD), CA-1/CA-2, §4.1 spec.
 */
final class SaisirEcritureManuelleTest extends ComptaApiTestCase
{
    public function testCreationEquilibreeScelleeCommeToutAutreEcriture(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->ouvrirPeriode($client, $entete, '2026-08-01', '2026-08-31');

        $reponse = $client->request('POST', '/api/compta/journal-entries/manual', $entete + [
            'json' => [
                'businessProfile' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
                'journal' => '/api/journals/' . $this->idJournal('OD'),
                'date' => '2026-08-19',
                'label' => 'OD test manuel',
                'lines' => [
                    ['account' => '/api/compte_comptables/' . $this->idCompte('512000'), 'debit' => '100.00', 'vatRate' => '/api/taux_tvas/' . $this->idTauxHorsChamp()],
                    ['account' => '/api/compte_comptables/' . $this->idCompte('401000'), 'credit' => '100.00', 'vatRate' => '/api/taux_tvas/' . $this->idTauxHorsChamp()],
                ],
            ],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertNotEmpty($reponse['empreinte']);
        self::assertNotEmpty($reponse['signature']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $ecriture = $em->getRepository(EcritureComptable::class)->find($reponse['id']);
        self::assertNotNull($ecriture);
        self::assertTrue($ecriture->estScellee());
        self::assertTrue($ecriture->estEquilibree());

        // Traçable au même titre qu'une écriture générée automatiquement (CA-1) : visible dans le
        // même endpoint de collecte, mêmes groupes de sérialisation.
        $collection = $client->request('GET', '/api/ecriture_comptables', $entete)->toArray();
        $ids = array_map(static fn (array $item): string => $item['id'], $collection['member'] ?? $collection['hydra:member'] ?? []);
        self::assertContains($reponse['id'], $ids);
    }

    public function testEcritureDesequilibreeRejetee422(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->ouvrirPeriode($client, $entete, '2026-08-01', '2026-08-31');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $avant = (int) $em->getRepository(EcritureComptable::class)->createQueryBuilder('e')->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();

        $client->request('POST', '/api/compta/journal-entries/manual', $entete + [
            'json' => [
                'businessProfile' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
                'journal' => '/api/journals/' . $this->idJournal('OD'),
                'date' => '2026-08-19',
                'label' => 'OD déséquilibrée',
                'lines' => [
                    ['account' => '/api/compte_comptables/' . $this->idCompte('512000'), 'debit' => '100.00', 'vatRate' => '/api/taux_tvas/' . $this->idTauxHorsChamp()],
                    ['account' => '/api/compte_comptables/' . $this->idCompte('401000'), 'credit' => '50.00', 'vatRate' => '/api/taux_tvas/' . $this->idTauxHorsChamp()],
                ],
            ],
        ]);

        self::assertResponseStatusCodeSame(422);

        /** @var EntityManagerInterface $emApres */
        $emApres = static::getContainer()->get('doctrine')->getManager();
        $apres = (int) $emApres->getRepository(EcritureComptable::class)->createQueryBuilder('e')->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();
        self::assertSame($avant, $apres, 'Aucune écriture partielle ne doit être persistée après un 422 (CA-2).');
    }

    public function testPeriodeClotureeRejetee409(): void
    {
        [$client, $entete] = $this->adminSurA();
        $periode = $this->ouvrirPeriode($client, $entete, '2026-08-01', '2026-08-31');
        $client->request('POST', '/api/compta/periodes/' . $periode['id'] . '/cloturer', $entete);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/compta/journal-entries/manual', $entete + [
            'json' => [
                'businessProfile' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
                'journal' => '/api/journals/' . $this->idJournal('OD'),
                'date' => '2026-08-19',
                'label' => 'OD sur période clôturée',
                'lines' => [
                    ['account' => '/api/compte_comptables/' . $this->idCompte('512000'), 'debit' => '100.00', 'vatRate' => '/api/taux_tvas/' . $this->idTauxHorsChamp()],
                    ['account' => '/api/compte_comptables/' . $this->idCompte('401000'), 'credit' => '100.00', 'vatRate' => '/api/taux_tvas/' . $this->idTauxHorsChamp()],
                ],
            ],
        ]);

        self::assertResponseStatusCodeSame(409);
    }

    public function testLigneCompteInactifRejetee422(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->ouvrirPeriode($client, $entete, '2026-08-01', '2026-08-31');

        $idCompte = $this->idCompte('401000');
        $client->request('PATCH', '/api/compte_comptables/' . $idCompte, [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'] + $entete['headers'],
            'json' => ['actif' => false],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/compta/journal-entries/manual', $entete + [
            'json' => [
                'businessProfile' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
                'journal' => '/api/journals/' . $this->idJournal('OD'),
                'date' => '2026-08-19',
                'label' => 'OD compte inactif',
                'lines' => [
                    ['account' => '/api/compte_comptables/' . $this->idCompte('512000'), 'debit' => '100.00', 'vatRate' => '/api/taux_tvas/' . $this->idTauxHorsChamp()],
                    ['account' => '/api/compte_comptables/' . $idCompte, 'credit' => '100.00', 'vatRate' => '/api/taux_tvas/' . $this->idTauxHorsChamp()],
                ],
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testLigneTauxTvaHorsChampReutilise(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->ouvrirPeriode($client, $entete, '2026-08-01', '2026-08-31');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $avantNbTaux = (int) $em->getRepository(TauxTva::class)->createQueryBuilder('t')->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();

        $reponse = $client->request('POST', '/api/compta/journal-entries/manual', $entete + [
            'json' => [
                'businessProfile' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
                'journal' => '/api/journals/' . $this->idJournal('OD'),
                'date' => '2026-08-19',
                'label' => 'Frais bancaires (hors champ)',
                'lines' => [
                    ['account' => '/api/compte_comptables/' . $this->idCompte('627000'), 'debit' => '15.00', 'vatRate' => '/api/taux_tvas/' . $this->idTauxHorsChamp()],
                    ['account' => '/api/compte_comptables/' . $this->idCompte('512000'), 'credit' => '15.00', 'vatRate' => '/api/taux_tvas/' . $this->idTauxHorsChamp()],
                ],
            ],
        ])->toArray();

        self::assertResponseIsSuccessful();
        self::assertNotEmpty($reponse['id']);

        /** @var EntityManagerInterface $emApres */
        $emApres = static::getContainer()->get('doctrine')->getManager();
        $apresNbTaux = (int) $emApres->getRepository(TauxTva::class)->createQueryBuilder('t')->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();
        self::assertSame($avantNbTaux, $apresNbTaux, 'Le taux « Hors champ » déjà seedé doit être réutilisé, aucun nouveau taux créé.');

        $compte = $em->getRepository(CompteComptable::class)->findOneBy(['numero' => '627000']);
        self::assertNotNull($compte);
    }
}
