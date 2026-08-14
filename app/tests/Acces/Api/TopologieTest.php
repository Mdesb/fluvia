<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\DataFixtures\AccesFixtures;
use App\Tests\Acces\AccesApiTestCase;

/**
 * Topologie Espace › Contrôleur › Équipement (US-L3-01, écran A-01, CA-1) : l'équipement porte un
 * sens, est rattaché à un contrôleur puis à un espace ; l'anti-passback est paramétrable au niveau
 * espace et surchargeable au niveau équipement ; une topologie incohérente est refusée (422).
 */
final class TopologieTest extends AccesApiTestCase
{
    public function testCa1EquipementPorteSensEtRattachements(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('GET', '/api/equipements/' . $this->idEquipement(), $entete);
        self::assertResponseIsSuccessful();
        $equipement = $client->getResponse()->toArray();

        self::assertSame('entree', $equipement['sens']);
        self::assertNotNull($equipement['controleur']);

        $client->request('GET', '/api/controleurs/' . $this->idControleur(), $entete);
        self::assertResponseIsSuccessful();
        $controleur = $client->getResponse()->toArray();
        self::assertNotNull($controleur['espace']);

        $client->request('GET', '/api/espace_acces/' . $this->idEspaceAcces(), $entete);
        self::assertResponseIsSuccessful();
        $espace = $client->getResponse()->toArray();
        self::assertSame(AccesFixtures::SEUIL_FMI, $espace['seuilFmi']);
        self::assertTrue($espace['antiPassbackActif']);
    }

    public function testCa1AntiPassbackSurchargeableAuNiveauEquipement(): void
    {
        [$client, $entete] = $this->adminSurA();

        $reponse = $client->request('POST', '/api/equipements', $entete + [
            'json' => [
                'libelle' => 'Tourniquet Sortie A2',
                'controleur' => '/api/controleurs/' . $this->idControleur(),
                'type' => 'tourniquet',
                'sens' => 'sortie',
                'antiPassbackActif' => false,
                'antiPassbackDelai' => 120,
            ],
        ]);
        self::assertResponseStatusCodeSame(201);
        $data = $reponse->toArray();
        self::assertFalse($data['antiPassbackActif']);
        self::assertSame(120, $data['antiPassbackDelai']);
    }

    public function testCa1SensManquantRefuseEnregistrement(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/equipements', $entete + [
            'json' => [
                'libelle' => 'Équipement sans sens',
                'controleur' => '/api/controleurs/' . $this->idControleur(),
                'type' => 'lecteur',
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testCa1EquipementOrphelinRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/equipements', $entete + [
            'json' => [
                'libelle' => 'Équipement orphelin',
                'type' => 'lecteur',
                'sens' => 'entree',
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testCa1ControleurSansItboxRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/controleurs', $entete + [
            'json' => [
                'libelle' => 'Contrôleur sans ITBOX',
                'espace' => '/api/espace_acces/' . $this->idEspaceAcces(),
                'itboxRef' => '',
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
    }
}
