<?php

declare(strict_types=1);

namespace App\Tests\Piscine\Api;

use App\Acces\DataFixtures\AccesFixtures;
use App\Tests\Piscine\PiscineApiTestCase;

/**
 * Bébés & accompagnants comptés dans la FMI (US-L6-08, RG-PISC-05, décision actée, CA-8). Réutilise
 * tel quel `POST /acces/passages/non-nominatif` de L3 (aucun développement L6, plan §2.4) appliqué à
 * un `EspaceAcces` référencé par une `Poss`.
 */
final class BebesTest extends PiscineApiTestCase
{
    public function testCa8BebeSansDroitIncrementeLaFmiSansDecompteDeCredit(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('GET', '/api/piscine/poss/' . $this->idPoss() . '/etat', $entete);
        self::assertSame(0, $client->getResponse()->toArray()['presents']);

        $client->request('POST', '/api/acces/passages/non-nominatif', $entete + [
            'json' => [
                'equipement' => '/api/equipements/' . $this->entite(\App\Acces\Entity\Equipement::class, ['libelle' => AccesFixtures::EQUIPEMENT_LIBELLE])->getId(),
                'motif' => 'bebe',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $passage = $client->getResponse()->toArray();
        self::assertSame('compte', $passage['resultat']);
        self::assertNull($passage['droit'], 'Aucun décompte de crédit pour un bébé/accompagnant sans droit.');

        $client->request('GET', '/api/piscine/poss/' . $this->idPoss() . '/etat', $entete);
        self::assertSame(1, $client->getResponse()->toArray()['presents'], 'La FMI/POSS est incrémentée (présence physique réelle).');
    }

    public function testCa8SortieDecrementeLaFmiCommeUnEntrantPayant(): void
    {
        [$client, $entete] = $this->adminSurA();

        $equipementEntree = $this->entite(\App\Acces\Entity\Equipement::class, ['libelle' => AccesFixtures::EQUIPEMENT_LIBELLE]);

        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $controleur = $equipementEntree->getControleur();
        $sortie = (new \App\Acces\Entity\Equipement())
            ->setLibelle('Sortie bébés test')
            ->setControleur($controleur)
            ->setType(\App\Acces\Enum\TypeEquipement::Tourniquet)
            ->setSens(\App\Acces\Enum\SensEquipement::Sortie);
        $em->persist($sortie);
        $em->flush();

        $client->request('POST', '/api/acces/passages/non-nominatif', $entete + [
            'json' => ['equipement' => '/api/equipements/' . $equipementEntree->getId(), 'motif' => 'accompagnant'],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/piscine/poss/' . $this->idPoss() . '/etat', $entete);
        self::assertSame(1, $client->getResponse()->toArray()['presents']);

        $client->request('POST', '/api/acces/passages/non-nominatif', $entete + [
            'json' => ['equipement' => '/api/equipements/' . $sortie->getId(), 'sens' => 'sortie', 'motif' => 'accompagnant'],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/piscine/poss/' . $this->idPoss() . '/etat', $entete);
        self::assertSame(0, $client->getResponse()->toArray()['presents'], 'Chaque sortie décrémente le compteur au même titre qu\'un entrant payant.');
    }
}
