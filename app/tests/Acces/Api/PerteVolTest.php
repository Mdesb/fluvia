<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\DataFixtures\AccesFixtures;
use App\Tests\Acces\AccesApiTestCase;

/**
 * Support perdu/volé (US-L3-09, RG-ACC-07, CA-10) : blocage serveur immédiat (refus online), la
 * déclaration est tracée (motif, agent, horodatage) et réversible par un rôle habilité.
 */
final class PerteVolTest extends AccesApiTestCase
{
    public function testCa10BlocageImmediatRefuseToutPassageOnline(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/acces/supports/' . $this->idSupport() . '/bloquer', $entete + [
            'json' => ['motif' => 'Support perdu par le porteur'],
        ]);
        self::assertResponseIsSuccessful();
        $declaration = $client->getResponse()->toArray();
        self::assertNotEmpty($declaration['agent']);
        self::assertNotEmpty($declaration['horodatage']);
        self::assertSame('Support perdu par le porteur', $declaration['motif']);

        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => [
                'equipement' => '/api/equipements/' . $this->idEquipement(),
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
            ],
        ]);
        $reponse = $client->getResponse()->toArray();
        self::assertSame('refuse', $reponse['resultat']);
        self::assertSame('support_bloque', $reponse['codeMotif']);
    }

    public function testCa10DeclarationReversibleParRoleHabilite(): void
    {
        [$client, $entete] = $this->adminSurA();

        $reponse = $client->request('POST', '/api/acces/supports/' . $this->idSupport() . '/bloquer', $entete + [
            'json' => ['motif' => 'Vol signalé'],
        ]);
        $declaration = $reponse->toArray();

        $client->request('POST', '/api/acces/declarations/' . basename((string) $declaration['id']) . '/annuler', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();
        $annulee = $client->getResponse()->toArray();
        self::assertTrue($annulee['annulee']);

        // Le support redevenu actif accepte à nouveau les passages.
        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => [
                'equipement' => '/api/equipements/' . $this->idEquipement(),
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
            ],
        ]);
        self::assertSame('valide', $client->getResponse()->toArray()['resultat']);
    }

    public function testCa10RefuseHorsLigneAussi(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/acces/supports/' . $this->idSupport() . '/bloquer', $entete + [
            'json' => ['motif' => 'Perte déclarée'],
        ]);
        self::assertResponseIsSuccessful();

        // Rejeu hors-ligne (§4.6) : le support bloqué figure dans la liste de révocation embarquée,
        // refusé même sans réseau (RG-ACC-07).
        $lot = [
            'controleur' => '/api/controleurs/' . $this->idControleur(),
            'lot' => [[
                'equipementId' => $this->idEquipement(),
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
                'sens' => 'entree',
                'horodatage' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'cleIdempotence' => (string) \Symfony\Component\Uid\Uuid::v4(),
            ]],
        ];
        $client->request('POST', '/api/acces/synchro', $entete + ['json' => $lot]);
        self::assertResponseIsSuccessful();
        $resultat = $client->getResponse()->toArray();
        self::assertCount(1, $resultat['inseres']);

        $passageId = $resultat['inseres'][0];
        $client->request('GET', '/api/passages/' . $passageId, $entete);
        $passage = $client->getResponse()->toArray();
        self::assertSame('refuse', $passage['resultat']);
        self::assertSame('support_bloque', $passage['codeMotif']);
    }
}
