<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Caisse\Entity\Caisse;
use App\Caisse\Entity\PointDeVente;
use App\Caisse\Entity\SessionCaisse;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Tests\Vente\VenteApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * AUDIT DU 06/09, CONSTAT 5 — la session de caisse ouverte sur le guichet d'un autre client.
 *
 * `OuvrirSessionProcessor` résolvait point de vente, caisse et régisseur depuis le corps par `find()`.
 * Une session de caisse est le début d'une chaîne NF525 : l'ouvrir chez un tenant qu'on n'atteint pas,
 * c'est écrire dans son registre fiscal. 404 pour le point de vente étranger ; 422 pour une caisse qui
 * n'est pas celle du point de vente nommé — celle-là n'est pas un secret, c'est une incohérence.
 *
 * `VenteApiTestCase::ouvrirSession()` (A sur A) reste le témoin positif de tout le module.
 */
final class OuvrirSessionScopeTest extends VenteApiTestCase
{
    public function testUnPointDeVenteHorsPerimetreVaut404(): void
    {
        [$client, $entete, ] = $this->adminSurA();
        [$pdvEtranger, $caisseEtrangere] = $this->guichetEtranger();

        $client->request('POST', '/api/sessions-caisse/ouvrir', $entete + [
            'json' => [
                'pointDeVente' => '/api/point_de_ventes/' . $pdvEtranger->getId(),
                'caisse' => '/api/caisses/' . $caisseEtrangere->getId(),
                'regisseur' => '/api/utilisateurs/' . $this->idAdmin(),
                'codeRegisseur' => 'CODE-REGIE-2026',
                'fondDeCaisse' => '50.00',
            ],
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->em()->getRepository(SessionCaisse::class)->findBy(['pointDeVente' => $pdvEtranger]), 'aucune session ne doit exister sur le guichet étranger');
    }

    public function testUneCaisseQuiNEstPasCelleDuPointDeVenteVaut422(): void
    {
        [$client, $entete, ] = $this->adminSurA();
        [, $caisseEtrangere] = $this->guichetEtranger();

        $client->request('POST', '/api/sessions-caisse/ouvrir', $entete + [
            'json' => [
                'pointDeVente' => '/api/point_de_ventes/' . $this->idPointDeVente(),
                'caisse' => '/api/caisses/' . $caisseEtrangere->getId(),
                'regisseur' => '/api/utilisateurs/' . $this->idAdmin(),
                'codeRegisseur' => 'CODE-REGIE-2026',
                'fondDeCaisse' => '50.00',
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    /** Ce que la règle ÉPARGNE : l'ouverture ordinaire, A sur A, avec le régisseur affecté à A. */
    public function testLOuvertureOrdinaireResteOuverte(): void
    {
        [$client, $entete, ] = $this->adminSurA();

        $session = $this->ouvrirSession($client, $entete);

        self::assertResponseIsSuccessful();
        self::assertSame('ouverte', $session['etat'] ?? null);
    }

    /** @return array{0: PointDeVente, 1: Caisse} un guichet dans un tenant où personne des fixtures n'est affecté */
    private function guichetEtranger(): array
    {
        $em = $this->em();
        $groupe = (new Groupe())->setNom('Groupe étranger (constat 5)');
        $region = (new Region())->setNom('Région étrangère')->setGroupe($groupe);
        $etablissement = (new Etablissement())->setNom('Tenant étranger (constat 5)')->setRegion($region)->setActif(true);
        $pdv = (new PointDeVente())->setLibelle('Guichet étranger')->setEtablissement($etablissement);
        $caisse = (new Caisse())->setLibelle('Caisse étrangère')->setPointDeVente($pdv);
        foreach ([$groupe, $region, $etablissement, $pdv, $caisse] as $entite) {
            $em->persist($entite);
        }
        $em->flush();

        return [$pdv, $caisse];
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
