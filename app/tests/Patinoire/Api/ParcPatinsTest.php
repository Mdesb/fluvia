<?php

declare(strict_types=1);

namespace App\Tests\Patinoire\Api;

use App\Patinoire\DataFixtures\PatinoireFixtures;
use App\Patinoire\Entity\Affutage;
use App\Tests\Patinoire\PatinoireApiTestCase;

/** Parc de patins par pointure (US-PATIN-01, RG-PAT-01, RG-PAT-06, CA-1). */
final class ParcPatinsTest extends PatinoireApiTestCase
{
    public function testDisponibiliteDeriveeDesCompteurs(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        // Fixture pointure 42 : quantiteTotale=5, quantiteSortie=1 (location démo en cours).
        $idParc = $this->idParcPatins(PatinoireFixtures::POINTURE_DEMO);
        $client->request('GET', '/api/patinoire_parc_patins/' . $idParc, $entete);
        self::assertResponseIsSuccessful();
        $parc = $client->getResponse()->toArray();

        self::assertSame(5, $parc['quantiteTotale']);
        self::assertSame(1, $parc['quantiteSortie']);
        self::assertSame(0, $parc['quantiteEnAffutage']);
        self::assertSame(0, $parc['quantiteHS']);
        self::assertSame(4, $parc['quantiteDisponible'], 'CA-1 : disponibilité = total - sortie - en affûtage - HS.');
    }

    public function testArticleAAffuterOuHSSortDuDisponible(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        // Fixture pointure 41 : quantiteTotale=5, 1 article en affûtage (fixture démo `Affutage`).
        $idParc = $this->idParcPatins(41);
        $client->request('GET', '/api/patinoire_parc_patins/' . $idParc, $entete);
        self::assertResponseIsSuccessful();
        $parc = $client->getResponse()->toArray();

        self::assertSame(5, $parc['quantiteTotale']);
        self::assertSame(1, $parc['quantiteEnAffutage'], 'RG-PAT-06 : article « à affûter » compté séparément.');
        self::assertSame(4, $parc['quantiteDisponible'], 'RG-PAT-06 : sort immédiatement du disponible.');

        // Remise en service (technicien termine l'affûtage) : redevient disponible.
        [$clientTech, $entTech] = $this->technicienSurA();
        $clientTech->disableReboot();
        $affutage = $this->entite(Affutage::class, []);
        $clientTech->request('POST', '/api/patinoire/affutages/' . $affutage->getId() . '/terminer', $entTech);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/patinoire_parc_patins/' . $idParc, $entete);
        $parc = $client->getResponse()->toArray();
        self::assertSame(0, $parc['quantiteEnAffutage']);
        self::assertSame(5, $parc['quantiteDisponible'], 'RG-PAT-06 : redevient disponible après remise en service.');
    }

    public function testCrudReserveAuGestionnaireOffre(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();
        $client->disableReboot();

        $client->request('POST', '/api/patinoire_parc_patins', $entete + [
            'json' => ['etablissement' => '/api/etablissements/' . $idA, 'pointure' => 45, 'quantiteTotale' => 3],
        ]);
        self::assertResponseIsSuccessful();
        $parc = $client->getResponse()->toArray();
        self::assertSame(45, $parc['pointure']);
        self::assertSame(3, $parc['quantiteDisponible']);
    }
}
