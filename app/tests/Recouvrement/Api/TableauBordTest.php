<?php

declare(strict_types=1);

namespace App\Tests\Recouvrement\Api;

use App\Recouvrement\Entity\RepresentationSepa;
use App\Tests\Recouvrement\RecouvrementApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tableau de bord du recouvrement (`App\Recouvrement`, refactor extraction de
 * `App\Sport\ApiResource\TableauBordImpayes`) : file des rejets par statut, nombre d'accès bloqués en
 * cours, taux de résolution en self-service — 100% générique, aucun champ « badge »/« fitness ».
 */
final class TableauBordTest extends RecouvrementApiTestCase
{
    public function testTableauBordAgregeParStatutEtTauxDeResolution(): void
    {
        [$client, $entete] = $this->adminSurA();
        $echeance = $this->premiereEcheanceContratDemo();

        // Rejet → échec de représentation → recouvrement + accès bloqué.
        $client->request('POST', '/api/sport/echeances/' . $echeance->getId() . '/simuler-rejet', $entete + [
            'json' => ['codeRetour' => 'AM04'],
        ]);
        $incidentId = $client->getResponse()->toArray()['id'];

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $representation = $em->getRepository(RepresentationSepa::class)->findOneBy(['incident' => $incidentId]);
        $client->request('POST', '/api/recouvrement/representations/' . $representation->getId() . '/enregistrer-resultat', $entete + [
            'json' => ['resultat' => 'echouee'],
        ]);
        self::assertResponseIsSuccessful();

        // Résolution 1 clic.
        $client->request('POST', '/api/recouvrement/incidents/' . $incidentId . '/resoudre', $entete);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/recouvrement/tableau-bord', $entete);
        self::assertResponseIsSuccessful();
        $tableau = $client->getResponse()->toArray();
        self::assertSame(0, $tableau['nbEnRepresentation']);
        self::assertSame(0, $tableau['nbEnRecouvrement'], 'Incident résolu, sorti du recouvrement.');
        self::assertSame(0, $tableau['nbAccesBloques'], 'Accès restauré après résolution.');
        self::assertEquals(1.0, $tableau['tauxResolutionSelfService'], '100% des incidents résolus en self-service (app_1_clic).');
    }
}
