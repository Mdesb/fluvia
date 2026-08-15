<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use App\Sport\Entity\AbonnementFitness;
use App\Sport\Entity\EcheanceSepa;
use App\Sport\Entity\StatutAccesFitness;
use App\Tests\Sport\SportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Résolution 1 clic & restauration de l'accès (US-SPORT-07, RG-SPORT-03, CA-8) : le moteur générique
 * (`App\Recouvrement`) restaure `DroitAcces` automatiquement dès l'encaissement confirmé, **sans
 * intervention d'un agent** ; ce test vérifie la conséquence propre à Sport
 * (`AbonnementFitness.statut` + `StatutAccesFitness`), tenue à jour via
 * `SynchroniserImpayeFitnessListener` (refactor extraction).
 */
final class ResolutionImpayeTest extends SportApiTestCase
{
    public function testCa8ResolutionUnClicRestaureAutomatiquementLaccesFitness(): void
    {
        [$client, $entete] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $abonnement = $this->abonnementDemo();
        $echeance = $em->getRepository(EcheanceSepa::class)->findOneBy(['abonnement' => $abonnement], ['dateProgrammee' => 'ASC']);

        // Rejet + échec de représentation → accès coupé (chemin générique déjà couvert par
        // App\Tests\Recouvrement\Api\MoteurRecouvrementTest).
        $client->request('POST', '/api/sport/echeances/' . $echeance->getId() . '/simuler-rejet', $entete + [
            'json' => ['codeRetour' => 'AM04'],
        ]);
        $incidentId = $client->getResponse()->toArray()['id'];
        $representation = $em->getRepository(\App\Recouvrement\Entity\RepresentationSepa::class)->findOneBy(['incident' => $incidentId]);
        $client->request('POST', '/api/recouvrement/representations/' . $representation->getId() . '/enregistrer-resultat', $entete + [
            'json' => ['resultat' => 'echouee'],
        ]);
        self::assertResponseIsSuccessful();

        $em->clear();
        $statutAcces = $em->getRepository(StatutAccesFitness::class)->findOneBy(['abonnement' => $abonnement->getId()]);
        self::assertFalse($statutAcces->isActif(), 'Accès coupé après échec de représentation (pré-condition du test).');

        // Résolution 1 clic (agent ici pour simplifier ; le flux self-service `_soi` est équivalent).
        $client->request('POST', '/api/recouvrement/incidents/' . $incidentId . '/resoudre', $entete);
        self::assertResponseIsSuccessful();
        $incident = $client->getResponse()->toArray();
        self::assertSame('resolu', $incident['statut']);
        self::assertSame('app_1_clic', $incident['canalResolution']);

        $em->clear();
        $abonnementRafraichi = $em->getRepository(AbonnementFitness::class)->find($abonnement->getId());
        self::assertSame('actif', $abonnementRafraichi->getStatut()->value);

        $statutAccesRestaure = $em->getRepository(StatutAccesFitness::class)->findOneBy(['abonnement' => $abonnement->getId()]);
        self::assertTrue($statutAccesRestaure->isActif());
        self::assertNotNull($statutAccesRestaure->getDroitAcces());
        self::assertSame('valide', $statutAccesRestaure->getDroitAcces()->getStatutProjection()->value);
    }

    public function testForcerReouvertureRestaureLabonnementFitnessSansEncaissement(): void
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

        $client->request('POST', '/api/recouvrement/incidents/' . $incidentId . '/forcer-reouverture', $entete + ['json' => ['motif' => 'Geste commercial exceptionnel']]);
        self::assertResponseIsSuccessful();

        $em->clear();
        $abonnementRafraichi = $em->getRepository(AbonnementFitness::class)->find($abonnement->getId());
        self::assertSame('actif', $abonnementRafraichi->getStatut()->value, 'Réouverture forcée : abonnement réactivé sans que le dossier impayé soit résolu.');

        $incidentEncore = $em->getRepository(\App\Recouvrement\Entity\IncidentImpaye::class)->find($incidentId);
        self::assertNotSame('resolu', $incidentEncore->getStatut()->value, 'Le dossier impayé reste ouvert après une réouverture forcée (RG-SOCLE-07).');
    }
}
