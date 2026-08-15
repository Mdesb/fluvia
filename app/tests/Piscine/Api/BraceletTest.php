<?php

declare(strict_types=1);

namespace App\Tests\Piscine\Api;

use App\Acces\Entity\Support;
use App\Acces\Enum\TypeSupport;
use App\Organisation\Entity\Etablissement;
use App\DataFixtures\SocleFixtures;
use App\Tests\Piscine\PiscineApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Bracelet RFID étanche (RG-PISC-04, US-L6-10, CA-10) : spécialisation d'un `Support` L3 (type RFID
 * exigé), lu/validé comme tout support d'accès au tripode/casier, historique de rattachement tracé
 * via `Support.statut` (réutilisé, pas de duplication).
 */
final class BraceletTest extends PiscineApiTestCase
{
    public function testCa10CreationAcceptonAvecSupportRfid(): void
    {
        [$client, $entete] = $this->adminSurA();

        $supportId = $this->creerSupport('RFID-CA10-01', TypeSupport::Rfid);

        $client->request('POST', '/api/bracelet_etanches', $entete + [
            'json' => ['support' => '/api/supports/' . $supportId, 'roles' => ['acces', 'casier', 'douche']],
        ]);
        self::assertResponseIsSuccessful();
        $bracelet = $client->getResponse()->toArray();
        self::assertSame(['acces', 'casier', 'douche'], $bracelet['roles']);
    }

    public function testCa10CreationRefuseeSiSupportNestPasRfid(): void
    {
        [$client, $entete] = $this->adminSurA();

        $supportId = $this->creerSupport('QR-CA10-01', TypeSupport::Qr);

        $client->request('POST', '/api/bracelet_etanches', $entete + [
            'json' => ['support' => '/api/supports/' . $supportId, 'roles' => ['acces']],
        ]);
        self::assertResponseStatusCodeSame(422, 'RG-PISC-04 : le bracelet étanche doit référencer un support RFID.');
    }

    public function testCa10DesactivationTraceeViaLeSupportReutilise(): void
    {
        [$client, $entete] = $this->adminSurA();

        $supportId = $this->creerSupport('RFID-CA10-02', TypeSupport::Rfid);
        $client->request('POST', '/api/bracelet_etanches', $entete + [
            'json' => ['support' => '/api/supports/' . $supportId, 'roles' => ['acces']],
        ]);
        self::assertResponseIsSuccessful();
        $braceletId = $client->getResponse()->toArray()['id'];
        self::assertSame('actif', $client->getResponse()->toArray()['etat']);

        // Blocage réutilisé tel quel du support L3 (perte/vol) : le bracelet reflète le nouvel état.
        $client->request('POST', '/api/acces/supports/' . $supportId . '/bloquer', $entete + [
            'json' => ['motif' => 'Bracelet perdu au bord du bassin'],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/bracelet_etanches/' . $braceletId, $entete);
        self::assertSame('bloque', $client->getResponse()->toArray()['etat'], 'L\'état du bracelet est délégué au Support L3 (pas de duplication).');
    }

    private function creerSupport(string $identifiant, TypeSupport $type): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        $support = (new Support())->setIdentifiant($identifiant)->setType($type)->setEtablissement($etab);
        $em->persist($support);
        $em->flush();

        return (string) $support->getId();
    }
}
