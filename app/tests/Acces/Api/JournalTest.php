<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\DataFixtures\AccesFixtures;
use App\Tests\Acces\AccesApiTestCase;

/**
 * Journal des passages (US-L3-11, écran A-05, RG-ACC-06, CA-12) : chaque passage horodaté et
 * rattaché, filtrable (période/espace/équipement/type), exportable, lecture seule ; un passage
 * hors-ligne apparaît après synchro sans doublon.
 */
final class JournalTest extends AccesApiTestCase
{
    public function testCa12PassageHorodateEtRattache(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => [
                'equipement' => '/api/equipements/' . $this->idEquipement(),
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
            ],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/passages', $entete);
        $liste = $client->getResponse()->toArray();
        $membres = $liste['member'] ?? $liste['hydra:member'];
        self::assertCount(1, $membres);
        $passage = $membres[0];
        self::assertNotEmpty($passage['horodatage']);
        self::assertNotEmpty($passage['espace']);
        self::assertNotEmpty($passage['equipement']);
        self::assertNotEmpty($passage['support']);
        self::assertSame('valide', $passage['resultat']);
    }

    public function testCa12ExportFiltreParEspaceEtResultat(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => [
                'equipement' => '/api/equipements/' . $this->idEquipement(),
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
            ],
        ]);

        $client->request('GET', '/api/acces/passages/export', $entete + [
            'query' => ['espace' => $this->idEspaceAcces(), 'resultat' => 'valide'],
        ]);
        self::assertResponseIsSuccessful();
        $liste = $client->getResponse()->toArray();
        $membres = $liste['member'] ?? $liste['hydra:member'];
        self::assertCount(1, $membres);
    }

    /**
     * ⚠ UN FILTRE ILLISIBLE NE DOIT PAS RENDRE PLUS QUE CE QUI ETAIT DEMANDE.
     *
     * La forme d'origine etait `si present ET valide, alors filtre` : elle se lit comme une
     * precaution et fait l'inverse. L'appelant demande a REDUIRE ; sur une valeur illisible, il
     * recevait TOUT l'etablissement. Dans un export c'est le pire endroit — le fichier s'ouvre, il
     * contient des lignes, elles sont plausibles, et personne ne compte les lignes d'un CSV.
     *
     * ⚠ ON COMPTE LES LIGNES, PAS LE STATUT : l'export repondait deja 200 en rendant tout.
     */
    public function testUnFiltreIllisibleNeRendPasTout(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => [
                'equipement' => '/api/equipements/' . $this->idEquipement(),
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
            ],
        ]);

        // Témoin positif : sans filtre, l'export contient quelque chose.
        $client->request('GET', '/api/acces/passages/export', $entete);
        self::assertResponseIsSuccessful();
        $tout = $client->getResponse()->toArray();
        $lignes = \count($tout['member'] ?? $tout['hydra:member']);
        self::assertGreaterThan(0, $lignes, 'témoin : sans passage exporté, ce test ne mesure rien');

        // Un identifiant valide mais inconnu : le filtre AGIT, donc zéro ligne.
        $client->request('GET', '/api/acces/passages/export', $entete + [
            'query' => ['espace' => (string) \Symfony\Component\Uid\Uuid::v4()],
        ]);
        self::assertResponseIsSuccessful();
        $inconnu = $client->getResponse()->toArray();
        self::assertCount(
            0,
            $inconnu['member'] ?? $inconnu['hydra:member'],
            'témoin : un espace inconnu doit rendre zéro, sinon le filtre ne filtre pas et la suite ne prouve rien',
        );

        // Un identifiant ILLISIBLE : refus explicite, jamais l'export entier.
        $client->request('GET', '/api/acces/passages/export', $entete + [
            'query' => ['espace' => 'pas-un-identifiant'],
        ]);
        self::assertResponseStatusCodeSame(
            422,
            'Un filtre illisible a été ignoré : l’export rend tout l’établissement alors qu’on demandait un espace.',
        );

        // Et une date illisible ne doit pas produire une erreur serveur.
        $client->request('GET', '/api/acces/passages/export', $entete + [
            'query' => ['depuis' => 'hier matin'],
        ]);
        self::assertResponseStatusCodeSame(422, 'Une date illisible doit être refusée, pas produire un 500.');
    }

    public function testCa12JournalLectureSeule(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => [
                'equipement' => '/api/equipements/' . $this->idEquipement(),
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
            ],
        ]);
        $passageId = $client->getResponse()->toArray()['id'];

        // Aucune opération d'écriture (PATCH/DELETE) exposée sur le journal.
        $client->request('PATCH', '/api/passages/' . $passageId, $entete + [
            'json' => ['motif' => 'falsification'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        self::assertContains($client->getResponse()->getStatusCode(), [404, 405]);

        $client->request('DELETE', '/api/passages/' . $passageId, $entete);
        self::assertContains($client->getResponse()->getStatusCode(), [404, 405]);
    }
}
