<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use App\Sport\Entity\EcheanceSepa;
use App\Sport\Entity\RepresentationSepa;
use App\Tests\Sport\SportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tableau de bord impayés (US-SPORT-11, CA-13) : file des rejets par statut, nombre de badges refusés
 * en cours, taux de résolution en self-service.
 */
final class TableauBordTest extends SportApiTestCase
{
    public function testCa13TableauBordAgregeParStatutEtTauxDeResolution(): void
    {
        [$client, $entete] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $abonnement = $this->abonnementDemo();
        $echeance = $em->getRepository(EcheanceSepa::class)->findOneBy(['abonnement' => $abonnement], ['dateProgrammee' => 'ASC']);

        // Rejet → échec de représentation → recouvrement + badge refusé.
        $client->request('POST', '/api/sport/echeances/' . $echeance->getId() . '/simuler-rejet', $entete + [
            'json' => ['codeRetour' => 'AM04'],
        ]);
        $incidentId = $client->getResponse()->toArray()['id'];
        $representation = $em->getRepository(RepresentationSepa::class)->findOneBy(['incident' => $incidentId]);
        $client->request('POST', '/api/sport/representations/' . $representation->getId() . '/enregistrer-resultat', $entete + [
            'json' => ['resultat' => 'echouee'],
        ]);
        self::assertResponseIsSuccessful();

        // Résolution 1 clic.
        $client->request('POST', '/api/sport/impayes/' . $incidentId . '/resoudre', $entete);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/sport/tableau-bord-impayes', $entete);
        self::assertResponseIsSuccessful();
        $tableau = $client->getResponse()->toArray();
        self::assertSame(0, $tableau['nbEnRepresentation']);
        self::assertSame(0, $tableau['nbEnRecouvrement'], 'Incident résolu, sorti du recouvrement.');
        self::assertSame(0, $tableau['nbBadgesRefuses'], 'Badge restauré après résolution.');
        self::assertEquals(1.0, $tableau['tauxResolutionSelfService'], '100% des incidents résolus en self-service (app_1_clic).');
    }
}
