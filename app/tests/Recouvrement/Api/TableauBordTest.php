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

        // Résolution constatée par un agent : virement reçu.
        $client->request('POST', '/api/recouvrement/incidents/' . $incidentId . '/resoudre', $entete + ['json' => ['canal' => 'virement', 'moyenPaiement' => 'virement']]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/recouvrement/tableau-bord', $entete);
        self::assertResponseIsSuccessful();
        $tableau = $client->getResponse()->toArray();
        self::assertSame(0, $tableau['nbEnRepresentation']);
        self::assertSame(0, $tableau['nbEnRecouvrement'], 'Incident résolu, sorti du recouvrement.');
        self::assertSame(0, $tableau['nbAccesBloques'], 'Accès restauré après résolution.');
        self::assertSame(1, $tableau['nbResolus'], 'Le dossier est bien résolu — c\'est le témoin qui donne son sens à la ligne suivante.');

        // ⚠ CE 0 EST JUSTE, ET IL ÉTAIT AUPARAVANT INDISCERNABLE D'UN 1.
        //
        // `tauxResolutionSelfService` ne compte QUE les résolutions `app_1_clic` — « réglés par le
        // client seul ». Ce test attendait 1.0 non pas parce que c'était vrai, mais parce que
        // `app_1_clic` était l'UNIQUE canal atteignable : l'opération refusait tout corps et le canal
        // était posé en dur. Le taux mesurait donc « tous les incidents résolus », sous un nom qui
        // annonçait autre chose.
        //
        // Un règlement constaté par un agent n'est pas du self-service. Le taux vaut 0, et c'est ce
        // qu'il doit valoir.
        //
        // ⚠ ET IL VAUDRA 0 EN PRODUCTION AUSSI, TANT QU'AUCUN PSP NE SERA RACCORDÉ — `app_1_clic` est
        // désormais refusé par l'adaptateur d'encaissement. L'écran doit DIRE pourquoi cette ligne est
        // à zéro, sans quoi elle se lira comme un échec produit au lieu d'une fonctionnalité non
        // branchée.
        self::assertEquals(0.0, $tableau['tauxResolutionSelfService'], 'Un virement constaté par un agent n\'est pas du self-service.');
    }
}
