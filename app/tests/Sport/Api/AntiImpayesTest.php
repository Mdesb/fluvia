<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use App\Sport\Entity\AbonnementFitness;
use App\Sport\Entity\EcheanceSepa;
use App\Sport\Entity\IncidentPrelevement;
use App\Sport\Entity\PolitiqueAntiImpayes;
use App\Sport\Entity\RepresentationSepa;
use App\Sport\Entity\StatutAccesFitness;
use App\Sport\Enum\MomentRefusBadge;
use App\Tests\Sport\SportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Moteur anti-impayés (US-SPORT-05/06, RG-SPORT-01/02, décision actée, CA-5/CA-6/CA-7, `critique`).
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
        // représentation n'a pas échoué (RG-SPORT-01).
        $statutAcces = $this->statutAccesAbonnement($echeance->getAbonnement());
        self::assertTrue($statutAcces->isActif());
    }

    public function testCa6RepresentationEnEchecRefuseLeBadgeEtBasculeEnRecouvrement(): void
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

        $client->request('POST', '/api/sport/representations/' . $representation->getId() . '/enregistrer-resultat', $entete + [
            'json' => ['resultat' => 'echouee'],
        ]);
        self::assertResponseIsSuccessful();

        $em->clear();
        $incident = $em->getRepository(IncidentPrelevement::class)->find($incidentId);
        self::assertSame('recouvrement', $incident->getStatut()->value);

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
        $politique = $em->getRepository(PolitiqueAntiImpayes::class)->findOneBy([]);
        $politique->setMomentRefusBadge(MomentRefusBadge::Apres1erEchec);
        $em->flush();

        $echeance = $this->premiereEcheanceAVenir();

        $client->request('POST', '/api/sport/echeances/' . $echeance->getId() . '/simuler-rejet', $entete + [
            'json' => ['codeRetour' => 'AM04'],
        ]);
        self::assertResponseIsSuccessful();
        $incident = $client->getResponse()->toArray();

        $em->clear();
        // Badge refusé immédiatement, en parallèle de la 1ère représentation programmée (toujours créée).
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
