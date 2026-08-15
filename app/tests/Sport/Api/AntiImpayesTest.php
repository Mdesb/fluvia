<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use App\Recouvrement\Entity\PolitiqueRecouvrement;
use App\Recouvrement\Entity\RepresentationSepa;
use App\Recouvrement\Enum\MomentRefusAcces;
use App\Sport\Entity\AbonnementFitness;
use App\Sport\Entity\EcheanceSepa;
use App\Sport\Entity\StatutAccesFitness;
use App\Tests\Sport\SportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Couplage Sport ↔ moteur de recouvrement partagé (US-SPORT-05/06, RG-SPORT-01/02, décision actée,
 * CA-5/CA-6/CA-7, `critique`). Le moteur générique lui-même (rejet → incident → représentation →
 * accès bloqué) est testé indépendamment de Sport dans `App\Tests\Recouvrement` — ce test-ci vérifie
 * uniquement la conséquence propre à Sport : `AbonnementFitness.statut` et
 * `StatutAccesFitness.actif/motifInactivite`, tenus à jour via
 * `App\Sport\EventListener\SynchroniserImpayeFitnessListener` (refactor extraction, aucune logique
 * anti-impayés n'est plus écrite dans `App\Sport`).
 */
final class AntiImpayesTest extends SportApiTestCase
{
    public function testCa5RejetProgrammeUneRepresentationEtLaccesResteActif(): void
    {
        [$client, $entete] = $this->adminSurA();
        $echeance = $this->premiereEcheanceAVenir();

        $client->request('POST', '/api/sport/echeances/' . $echeance->getId() . '/simuler-rejet', $entete + [
            'json' => ['codeRetour' => 'AM04', 'libelleRetour' => 'Fonds insuffisants'],
        ]);
        self::assertResponseIsSuccessful();
        $incident = $client->getResponse()->toArray();
        self::assertSame('representation', $incident['statut']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();

        $representations = $em->getRepository(RepresentationSepa::class)->findBy(['incident' => $incident['id']]);
        self::assertCount(1, $representations, 'Une représentation est programmée automatiquement selon le calendrier.');

        // Politique par défaut = apres_representation_echouee : l'accès reste actif tant que la
        // représentation n'a pas échoué (RG-SPORT-01). L'abonnement bascule impayé immédiatement.
        $statutAcces = $this->statutAccesAbonnement($echeance->getAbonnement());
        self::assertTrue($statutAcces->isActif());

        $abonnement = $em->getRepository(AbonnementFitness::class)->find($echeance->getAbonnement()->getId());
        self::assertSame('impaye', $abonnement->getStatut()->value, 'Sport synchronise son propre statut via IncidentImpayeDetecteEvent.');
    }

    public function testCa6RepresentationEnEchecCoupeLaccesFitnessEtBasculeEnRecouvrement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $echeance = $this->premiereEcheanceAVenir();

        $client->request('POST', '/api/sport/echeances/' . $echeance->getId() . '/simuler-rejet', $entete + [
            'json' => ['codeRetour' => 'AM04'],
        ]);
        $incidentId = $client->getResponse()->toArray()['id'];

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $representation = $em->getRepository(RepresentationSepa::class)->findOneBy(['incident' => $incidentId]);
        self::assertNotNull($representation);

        $client->request('POST', '/api/recouvrement/representations/' . $representation->getId() . '/enregistrer-resultat', $entete + [
            'json' => ['resultat' => 'echouee'],
        ]);
        self::assertResponseIsSuccessful();

        $em->clear();
        $statutAcces = $this->statutAccesAbonnement($echeance->getAbonnement());
        self::assertFalse($statutAcces->isActif());
        self::assertSame('impaye', $statutAcces->getMotifInactivite()->value);
        self::assertNotNull($statutAcces->getDroitAcces());
        self::assertSame('devalide', $statutAcces->getDroitAcces()->getStatutProjection()->value);
    }

    public function testCa7RefusDesLe1erEchecSiPolitiqueLeParametre(): void
    {
        [$client, $entete] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $politique = $em->getRepository(PolitiqueRecouvrement::class)->findOneBy([]);
        $politique->setMomentRefusAcces(MomentRefusAcces::Apres1erEchec);
        $em->flush();

        $echeance = $this->premiereEcheanceAVenir();

        $client->request('POST', '/api/sport/echeances/' . $echeance->getId() . '/simuler-rejet', $entete + [
            'json' => ['codeRetour' => 'AM04'],
        ]);
        self::assertResponseIsSuccessful();
        $incident = $client->getResponse()->toArray();

        $em->clear();
        // Accès coupé immédiatement, en parallèle de la 1ère représentation programmée (toujours créée).
        $statutAcces = $this->statutAccesAbonnement($echeance->getAbonnement());
        self::assertFalse($statutAcces->isActif());
        self::assertSame('impaye', $statutAcces->getMotifInactivite()->value);

        $representations = $em->getRepository(RepresentationSepa::class)->findBy(['incident' => $incident['id']]);
        self::assertCount(1, $representations, 'La représentation reste programmée même en refus immédiat.');
    }

    private function premiereEcheanceAVenir(): EcheanceSepa
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $abonnement = $this->abonnementDemo();
        $echeance = $em->getRepository(EcheanceSepa::class)->findOneBy(['abonnement' => $abonnement], ['dateProgrammee' => 'ASC']);
        self::assertNotNull($echeance);

        return $echeance;
    }

    private function statutAccesAbonnement(AbonnementFitness $abonnement): StatutAccesFitness
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $statutAcces = $em->getRepository(StatutAccesFitness::class)->findOneBy(['abonnement' => $abonnement]);
        self::assertNotNull($statutAcces);

        return $statutAcces;
    }
}
