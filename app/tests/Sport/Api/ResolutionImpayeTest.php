<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use App\Membership\Entity\Membership;
use App\Membership\Entity\EcheanceSepa;
use App\Membership\Entity\StatutAccesFitness;
use App\Tests\Sport\SportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Résolution 1 clic & restauration de l'accès (US-SPORT-07, RG-SPORT-03, CA-8) : le moteur générique
 * (`App\Recouvrement`) restaure `DroitAcces` automatiquement dès l'encaissement confirmé, **sans
 * intervention d'un agent** ; ce test vérifie la conséquence propre à Sport
 * (`Membership.statut` + `StatutAccesFitness`), tenue à jour via
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

        // Règlement constaté par un agent : virement reçu.
        //
        // ⚠ CE TEST ATTENDAIT `app_1_clic`, ET CE N'ÉTAIT PAS UNE VÉRIFICATION. L'opération refusait
        //    tout corps (`input: false`) et le handler posait le canal en dur : `app_1_clic` était la
        //    seule valeur qu'un incident pût jamais porter. L'assertion ne mesurait donc rien — elle
        //    recopiait une constante. Ce que ce test doit prouver, c'est que l'accès fitness est
        //    restauré quand l'impayé est réglé, et ça ne dépend pas du canal.
        //
        // ⚠ ET `app_1_clic` NE PASSERAIT PLUS ICI : l'adaptateur d'encaissement CB refuse tant
        //    qu'aucun prestataire n'est raccordé. Le canal déclaré est donc celui du chemin réel.
        $client->request('POST', '/api/recouvrement/incidents/' . $incidentId . '/resoudre', $entete + ['json' => ['canal' => 'virement', 'moyenPaiement' => 'virement']]);
        self::assertResponseIsSuccessful();
        $incident = $client->getResponse()->toArray();
        self::assertSame('resolu', $incident['statut']);
        self::assertSame('virement', $incident['canalResolution'], 'Le canal enregistré est celui qui a été déclaré.');

        $em->clear();
        $abonnementRafraichi = $em->getRepository(Membership::class)->find($abonnement->getId());
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
        $abonnementRafraichi = $em->getRepository(Membership::class)->find($abonnement->getId());
        self::assertSame('actif', $abonnementRafraichi->getStatut()->value, 'Réouverture forcée : abonnement réactivé sans que le dossier impayé soit résolu.');

        $incidentEncore = $em->getRepository(\App\Recouvrement\Entity\IncidentImpaye::class)->find($incidentId);
        self::assertNotSame('resolu', $incidentEncore->getStatut()->value, 'Le dossier impayé reste ouvert après une réouverture forcée (RG-SOCLE-07).');
    }
}
