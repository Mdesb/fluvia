<?php

declare(strict_types=1);

namespace App\Tests\Facturation\Api;

use App\Compta\Entity\EcritureComptable;
use App\Facturation\Entity\Facture;
use App\Facturation\Enum\NatureFacture;
use App\Tests\Facturation\FacturationApiTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

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
            'json' => ['vente' => '/api/ventes/' . $vente['id'], 'destinataire' => ['type' => 'personne_morale', 'raisonSociale' => 'Client de test', 'siret' => '12345678900011', 'adresse' => ['rue' => '1 rue de Test', 'cp' => '75000', 'ville' => 'Paris', 'pays' => 'FR']]],
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

    /**
     * Correctif revue de cohérence (défaut 1, BLOQUANT) : un second appel de `POST /factures/{id}/avoir`
     * ne doit créer ni un second avoir, ni une seconde extourne (double crédit 411 / double débit
     * produit). `AvoirFactureHandler::genererAvoir()` renvoie l'avoir déjà généré.
     */
    public function testCa6DoubleAppelAvoirNeCreePasSecondAvoirNiSecondeExtourne(): void
    {
        [$client, $entete] = $this->adminSurA();

        $brouillon = $client->request('POST', '/api/factures', $entete + [
            'json' => $this->corpsFactureDirecte(100.0),
        ])->toArray();
        $client->request('POST', '/api/factures/' . $brouillon['id'] . '/emettre', $entete);

        $premier = $client->request('POST', '/api/factures/' . $brouillon['id'] . '/avoir', $entete)->toArray();
        $second = $client->request('POST', '/api/factures/' . $brouillon['id'] . '/avoir', $entete)->toArray();

        self::assertSame($premier['id'], $second['id'], 'Idempotence : le second appel renvoie le même avoir, jamais un doublon.');
        self::assertSame($premier['numero'], $second['numero']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $nbAvoirs = (int) $em->getRepository(Facture::class)->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->andWhere('f.factureCorrigee = :corrigee')
            ->setParameter('corrigee', Uuid::fromString($brouillon['id']), 'uuid')
            ->getQuery()->getSingleScalarResult();
        self::assertSame(1, $nbAvoirs, 'Un seul avoir en base pour cette facture corrigée.');

        $nbExtournes = (int) $em->getRepository(EcritureComptable::class)->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.pieceExtourneDe IS NOT NULL')
            ->getQuery()->getSingleScalarResult();
        self::assertSame(1, $nbExtournes, 'Une seule écriture d\'extourne, pas de double débit/crédit.');
    }

    /** Garde-fou base (complément de l'idempotence applicative) : `uniq_facturation_facture_corrigee`. */
    public function testUniciteFactureCorrigeeEnBase(): void
    {
        [$client, $entete] = $this->adminSurA();

        $brouillon = $client->request('POST', '/api/factures', $entete + [
            'json' => $this->corpsFactureDirecte(100.0),
        ])->toArray();
        $client->request('POST', '/api/factures/' . $brouillon['id'] . '/emettre', $entete);
        $client->request('POST', '/api/factures/' . $brouillon['id'] . '/avoir', $entete);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $factureCorrigee = $em->getRepository(Facture::class)->find(Uuid::fromString($brouillon['id']));
        self::assertInstanceOf(Facture::class, $factureCorrigee);

        $destinataire = $factureCorrigee->getDestinataire();
        self::assertNotNull($destinataire);

        $doublon = new Facture();
        $doublon->setNature(NatureFacture::Avoir);
        $doublon->setFactureCorrigee($factureCorrigee);
        $doublon->setEtablissement($factureCorrigee->getEtablissement());
        $doublon->setProfilExploitant($factureCorrigee->getProfilExploitant());
        $doublon->setDestinataire($destinataire->copier());
        $doublon->setCreePar($factureCorrigee->getCreePar());

        $em->persist($doublon);

        $this->expectException(UniqueConstraintViolationException::class);
        $em->flush();
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
