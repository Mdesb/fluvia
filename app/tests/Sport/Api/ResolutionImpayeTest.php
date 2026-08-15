<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use App\Sport\Entity\EcheanceSepa;
use App\Sport\Entity\IncidentPrelevement;
use App\Sport\Entity\StatutAccesFitness;
use App\Tests\Sport\SportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Résolution 1 clic & restauration de l'accès (US-SPORT-07, RG-SPORT-03, CA-8) : incident résolu
 * (canal app_1_clic), abonnement réactivé, droit d'accès restauré **sans intervention d'un agent**.
 */
final class ResolutionImpayeTest extends SportApiTestCase
{
    public function testCa8ResolutionUnClicRestaureAutomatiquementLacces(): void
    {
        [$client, $entete] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $abonnement = $this->abonnementDemo();
        $echeance = $em->getRepository(EcheanceSepa::class)->findOneBy(['abonnement' => $abonnement], ['dateProgrammee' => 'ASC']);

        // Rejet + échec de représentation → badge refusé (chemin déjà couvert par AntiImpayesTest).
        $client->request('POST', '/api/sport/echeances/' . $echeance->getId() . '/simuler-rejet', $entete + [
            'json' => ['codeRetour' => 'AM04'],
        ]);
        $incidentId = $client->getResponse()->toArray()['id'];
        $representation = $em->getRepository(\App\Sport\Entity\RepresentationSepa::class)->findOneBy(['incident' => $incidentId]);
        $client->request('POST', '/api/sport/representations/' . $representation->getId() . '/enregistrer-resultat', $entete + [
            'json' => ['resultat' => 'echouee'],
        ]);
        self::assertResponseIsSuccessful();

        $em->clear();
        $statutAcces = $em->getRepository(StatutAccesFitness::class)->findOneBy(['abonnement' => $abonnement->getId()]);
        self::assertFalse($statutAcces->isActif(), 'Badge refusé après échec de représentation (pré-condition du test).');

        // Résolution 1 clic (agent ici pour simplifier ; le flux self-service `_soi` est équivalent).
        $client->request('POST', '/api/sport/impayes/' . $incidentId . '/resoudre', $entete);
        self::assertResponseIsSuccessful();
        $incident = $client->getResponse()->toArray();
        self::assertSame('resolu', $incident['statut']);
        self::assertSame('app_1_clic', $incident['canalResolution']);

        $em->clear();
        $abonnementRafraichi = $em->getRepository(\App\Sport\Entity\AbonnementFitness::class)->find($abonnement->getId());
        self::assertSame('actif', $abonnementRafraichi->getStatut()->value);

        $statutAccesRestaure = $em->getRepository(StatutAccesFitness::class)->findOneBy(['abonnement' => $abonnement->getId()]);
        self::assertTrue($statutAccesRestaure->isActif());
        self::assertNotNull($statutAccesRestaure->getDroitAcces());
        self::assertSame('valide', $statutAccesRestaure->getDroitAcces()->getStatutProjection()->value);
    }

    public function testResolutionRefuseeSiIncidentDejaResolu(): void
    {
        [$client, $entete] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $abonnement = $this->abonnementDemo();
        $echeance = $em->getRepository(EcheanceSepa::class)->findOneBy(['abonnement' => $abonnement], ['dateProgrammee' => 'ASC']);

        $client->request('POST', '/api/sport/echeances/' . $echeance->getId() . '/simuler-rejet', $entete + [
            'json' => ['codeRetour' => 'AM04'],
        ]);
        $incidentId = $client->getResponse()->toArray()['id'];

        $client->request('POST', '/api/sport/impayes/' . $incidentId . '/resoudre', $entete);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/sport/impayes/' . $incidentId . '/resoudre', $entete);
        self::assertResponseStatusCodeSame(422);
    }

    public function testForcerReouvertureExigeUnMotif(): void
    {
        [$client, $entete] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $abonnement = $this->abonnementDemo();
        $echeance = $em->getRepository(EcheanceSepa::class)->findOneBy(['abonnement' => $abonnement], ['dateProgrammee' => 'ASC']);

        $client->request('POST', '/api/sport/echeances/' . $echeance->getId() . '/simuler-rejet', $entete + [
            'json' => ['codeRetour' => 'AM04'],
        ]);
        $incidentId = $client->getResponse()->toArray()['id'];

        $client->request('POST', '/api/sport/impayes/' . $incidentId . '/forcer-reouverture', $entete + ['json' => ['motif' => '']]);
        self::assertResponseStatusCodeSame(422, 'RG-SOCLE-07 : le motif est requis pour une réouverture forcée journalisée.');

        $client->request('POST', '/api/sport/impayes/' . $incidentId . '/forcer-reouverture', $entete + ['json' => ['motif' => 'Geste commercial exceptionnel']]);
        self::assertResponseIsSuccessful();
    }
}
