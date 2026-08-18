<?php

declare(strict_types=1);

namespace App\Tests\Facturation\Api;

use App\Compta\Entity\EcritureComptable;
use App\Facturation\Entity\DestinataireFacturation;
use App\Facturation\Entity\Facture;
use App\Facturation\Enum\NatureFacture;
use App\Facturation\Enum\OrigineFacture;
use App\Facturation\Enum\TypeDestinataire;
use App\Securite\Entity\Utilisateur;
use App\Tests\Facturation\FacturationApiTestCase;
use App\Vente\Entity\Vente;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * US-FACT-01, RG-FACT-03.1/09 (CA-1, CA-2) : une facture émise pour une vente déjà encaissée est un
 * document justificatif, jamais un second fait générateur comptable.
 */
final class FactureJustificativeApiTest extends FacturationApiTestCase
{
    public function testCa1FactureSurTicketDejaEncaisseNeRecomptabilisePas(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->creerVenteValidee($client, $entete);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $nbEcrituresAvant = (int) $em->getRepository(EcritureComptable::class)->createQueryBuilder('e')
            ->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();

        $reponse = $client->request('POST', '/api/factures/depuis-vente', $entete + [
            'json' => ['vente' => '/api/ventes/' . $vente['id']],
        ]);

        self::assertSame(201, $reponse->getStatusCode());
        $donnees = $reponse->toArray();
        self::assertSame('acquittee', $donnees['statut']);
        self::assertNull($donnees['ecritureGeneree'] ?? null);
        self::assertNotNull($donnees['numero']);
        self::assertTrue($donnees['mentionAcquittee']);

        $nbEcrituresApres = (int) $em->getRepository(EcritureComptable::class)->createQueryBuilder('e')
            ->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();
        self::assertSame($nbEcrituresAvant, $nbEcrituresApres, 'Aucune écriture nouvelle : le CA de la période ne varie pas (CA-1).');
    }

    public function testCa2DuplicataMemeVenteNeCreePasNouvelleFacture(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->creerVenteValidee($client, $entete);

        $premiere = $client->request('POST', '/api/factures/depuis-vente', $entete + [
            'json' => ['vente' => '/api/ventes/' . $vente['id']],
        ])->toArray();

        $seconde = $client->request('POST', '/api/factures/depuis-vente', $entete + [
            'json' => ['vente' => '/api/ventes/' . $vente['id']],
        ])->toArray();

        self::assertSame($premiere['id'], $seconde['id'], 'CA-2 : même document renvoyé, aucune nouvelle facture.');
        self::assertSame($premiere['numero'], $seconde['numero']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $total = (int) $em->getRepository(Facture::class)->createQueryBuilder('f')
            ->select('COUNT(f.id)')->getQuery()->getSingleScalarResult();
        self::assertSame(1, $total);
    }

    public function testUniciteVenteOrigineEnBase(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->creerVenteValidee($client, $entete);

        $client->request('POST', '/api/factures/depuis-vente', $entete + [
            'json' => ['vente' => '/api/ventes/' . $vente['id']],
        ]);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $venteEntite = $em->getRepository(Vente::class)->find(Uuid::fromString($vente['id']));
        $etablissement = $em->getRepository(\App\Organisation\Entity\Etablissement::class)->find(Uuid::fromString($this->idEtablissement(\App\DataFixtures\SocleFixtures::ETAB_A_NOM)));
        $admin = $em->getRepository(Utilisateur::class)->find(Uuid::fromString($this->idAdmin()));

        $destinataire = (new DestinataireFacturation())->setType(TypeDestinataire::Particulier)->setNom('Doublon')->setAdresse([]);

        $doublon = new Facture();
        $doublon->setNature(NatureFacture::Facture);
        $doublon->setOrigine(OrigineFacture::TicketEncaisse);
        $doublon->setVenteOrigine($venteEntite);
        $doublon->setEtablissement($etablissement);
        $doublon->setProfilExploitant($this->profilExploitant());
        $doublon->setDestinataire($destinataire);
        $doublon->setCreePar($admin);

        $em->persist($doublon);

        $this->expectException(UniqueConstraintViolationException::class);
        $em->flush();
    }
}
