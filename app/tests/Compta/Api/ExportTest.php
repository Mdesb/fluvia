<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Compta\Entity\EcritureComptable;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * US-L4-07, RG-EXPORT-07 : le format d'export est commuté par profil (CA-10) ; export bloqué avec
 * liste d'anomalies si une écriture de la période n'est pas validée/est déséquilibrée.
 */
final class ExportTest extends ComptaApiTestCase
{
    public function testProfilRegieNePeutGenererQuePesEtEtatRegie(): void
    {
        [$client, $entete] = $this->adminSurA();
        $iri = '/api/profil_exploitants/' . $this->idProfilExploitant();

        // FEC non disponible pour la régie directe (masquage par profil, CA-10).
        $reponse = $client->request('POST', '/api/export_comptables', $entete + [
            'json' => [
                'profilExploitant' => $iri,
                'format' => 'FEC',
                'periodeDebut' => '2026-01-01',
                'periodeFin' => '2026-12-31',
            ],
        ]);
        self::assertSame(403, $reponse->getStatusCode(), 'Le format FEC doit être masqué pour un profil régie directe (RG-EXPORT-07).');

        // EtatRegie disponible et généré (aucune écriture -> pas d'anomalie de déséquilibre).
        $etatRegie = $client->request('POST', '/api/export_comptables', $entete + [
            'json' => [
                'profilExploitant' => $iri,
                'format' => 'EtatRegie',
                'periodeDebut' => '2026-01-01',
                'periodeFin' => '2026-12-31',
            ],
        ])->toArray();
        self::assertSame('genere', $etatRegie['statut']);
    }

    public function testExportBloqueSiEcritureNonValideeDansLaPeriode(): void
    {
        [$client, $entete] = $this->adminSurA();

        $vente = $this->creerVenteValidee($client, $entete, quantite: 1);
        $client->request('POST', '/api/compta/ecritures/generer', $entete + [
            'json' => ['profilExploitant' => '/api/profil_exploitants/' . $this->idProfilExploitant()],
        ]);
        // L'écriture générée reste au statut « contrôlée » (pas encore validée par le Comptable).

        $export = $client->request('POST', '/api/export_comptables', $entete + [
            'json' => [
                'profilExploitant' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
                'format' => 'EtatRegie',
                'periodeDebut' => (new \DateTimeImmutable('first day of this year'))->format('Y-m-d'),
                'periodeFin' => (new \DateTimeImmutable('last day of this year'))->format('Y-m-d'),
            ],
        ])->toArray();

        self::assertSame('bloque_anomalies', $export['statut']);
        self::assertNotEmpty($export['anomalies']);
    }

    public function testExportGenereEtTelechargeableUneFoisEcritureValidee(): void
    {
        [$client, $entete] = $this->adminSurA();

        $vente = $this->creerVenteValidee($client, $entete, quantite: 1);
        $client->request('POST', '/api/compta/ecritures/generer', $entete + [
            'json' => ['profilExploitant' => '/api/profil_exploitants/' . $this->idProfilExploitant()],
        ]);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $ecriture = $em->getRepository(EcritureComptable::class)->findOneBy(['venteOrigine' => $vente['id']]);
        self::assertNotNull($ecriture);
        $ecritureId = (string) $ecriture->getId();
        $client->request('POST', '/api/compta/ecritures/' . $ecritureId . '/valider', $entete);
        self::assertResponseIsSuccessful();

        $export = $client->request('POST', '/api/export_comptables', $entete + [
            'json' => [
                'profilExploitant' => '/api/profil_exploitants/' . $this->idProfilExploitant(),
                'format' => 'EtatRegie',
                'periodeDebut' => (new \DateTimeImmutable('first day of this year'))->format('Y-m-d'),
                'periodeFin' => (new \DateTimeImmutable('last day of this year'))->format('Y-m-d'),
            ],
        ])->toArray();
        self::assertSame('genere', $export['statut']);
        self::assertSame([], $export['anomalies'] ?? []);

        $telecharge = $client->request('GET', '/api/compta/exports/' . $export['id'] . '/telecharger', $entete)->toArray();
        self::assertNotEmpty($telecharge['contenu'] ?? null, 'Le contenu généré doit être téléchargeable.');
    }
}
