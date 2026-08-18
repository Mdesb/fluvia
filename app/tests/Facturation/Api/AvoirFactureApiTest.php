<?php

declare(strict_types=1);

namespace App\Tests\Facturation\Api;

use App\Compta\Entity\EcritureComptable;
use App\Tests\Facturation\FacturationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * US-FACT-05, RG-FACT-05 (CA-6) : l'avoir est la seule voie de correction — écriture d'extourne
 * symétrique si la facture d'origine en avait généré une (directe), aucune écriture sinon
 * (justificative).
 */
final class AvoirFactureApiTest extends FacturationApiTestCase
{
    public function testCa6AvoirSurFactureDirecteExtourneEcriture(): void
    {
        [$client, $entete] = $this->adminSurA();

        $brouillon = $client->request('POST', '/api/factures', $entete + [
            'json' => $this->corpsFactureDirecte(100.0),
        ])->toArray();
        $emise = $client->request('POST', '/api/factures/' . $brouillon['id'] . '/emettre', $entete)->toArray();

        $avoir = $client->request('POST', '/api/factures/' . $brouillon['id'] . '/avoir', $entete)->toArray();

        self::assertSame('avoir', $avoir['nature']);
        self::assertStringStartsWith('AVF-', $avoir['numero']);
        self::assertNotNull($avoir['ecritureGeneree'], 'Écriture d\'extourne générée (CA-6, branche 1).');

        $extourneId = $this->idDepuisReference($avoir['ecritureGeneree']);
        $extourne = $client->request('GET', '/api/ecriture_comptables/' . $extourneId, $entete)->toArray();
        self::assertNotNull($extourne['pieceExtourneDe'] ?? null, 'pieceExtourneDe renseigné.');
        self::assertSame($this->idDepuisReference($emise['ecritureGeneree']), $this->idDepuisReference($extourne['pieceExtourneDe']));

        $debit = array_sum(array_map('intval', array_column($extourne['lignes'], 'debitCentimes')));
        $credit = array_sum(array_map('intval', array_column($extourne['lignes'], 'creditCentimes')));
        self::assertSame($debit, $credit, 'Extourne équilibrée.');
    }

    public function testCa6AvoirSurFactureJustificativeNeGenereAucuneEcriture(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->creerVenteValidee($client, $entete);
        $facture = $client->request('POST', '/api/factures/depuis-vente', $entete + [
            'json' => ['vente' => '/api/ventes/' . $vente['id']],
        ])->toArray();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $nbEcrituresAvant = (int) $em->getRepository(EcritureComptable::class)->createQueryBuilder('e')
            ->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();

        $avoir = $client->request('POST', '/api/factures/' . $facture['id'] . '/avoir', $entete)->toArray();

        self::assertSame('avoir', $avoir['nature']);
        self::assertNull($avoir['ecritureGeneree'] ?? null, 'Aucune écriture générée par l\'avoir (CA-6, branche 2).');

        $nbEcrituresApres = (int) $em->getRepository(EcritureComptable::class)->createQueryBuilder('e')
            ->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();
        self::assertSame($nbEcrituresAvant, $nbEcrituresApres);
    }

    /** Extrait un identifiant, que la référence soit une IRI (string) ou une ressource imbriquée (array). */
    private function idDepuisReference(mixed $reference): string
    {
        if (\is_array($reference)) {
            $reference = $reference['id'] ?? $reference['@id'] ?? '';
        }

        return basename((string) $reference);
    }
}
