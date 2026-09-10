<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Entity\Controleur;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Equipement;
use App\Sport\Entity\StatutAccesFitness;
use App\Tests\Sport\SportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Couplage statut de paiement ↔ droit d'accès, hors-ligne (US-SPORT-08, RG-SPORT-04, CA-9/CA-10).
 * Réutilise **intégralement** le mécanisme générique L3 (`DroitAcces.statutProjection`, `POST
 * /acces/synchro`) : aucun développement Sport supplémentaire n'est requis pour le hors-ligne — le
 * point de couplage est uniquement l'écriture faite par `App\Recouvrement\Service\PropagationAccesHandler`
 * (moteur de recouvrement partagé, refactor extraction depuis `App\Sport`).
 */
final class AccesHorsLigneTest extends SportApiTestCase
{
    public function testCa9AccesAutoriseLocalementTantQueLeStatutMisEnCacheEstValide(): void
    {
        [$client, $entete] = $this->adminSurA();
        [$abonnementId, $droitId] = $this->rattacherDroitDemo($client, $entete);

        $lot = $this->lotSynchro(
            (new \DateTimeImmutable('2026-07-01T09:00:00+00:00')),
        );
        $resultat = $client->request('POST', '/api/acces/synchro', $entete + ['json' => $lot])->toArray();
        self::assertCount(1, $resultat['inseres'], 'Abonnement actif → droit valide → passage autorisé, sans appel réseau supplémentaire côté contrôleur.');
    }

    public function testCa9AccesRefuseLocalementSiStatutDevalideAvantLaCoupure(): void
    {
        [$client, $entete] = $this->adminSurA();
        [$abonnementId, $droitId] = $this->rattacherDroitDemo($client, $entete);

        // Impayé confirmé côté serveur AVANT la synchro (badge refusé) : le contrôleur, en se
        // synchronisant, applique désormais le statut dévalidé (RG-SPORT-04).
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $droit = $em->getRepository(DroitAcces::class)->find($droitId);
        self::assertNotNull($droit);

        $this->creerIncidentEtEchecRepresentation($client, $entete);

        $lot = $this->lotSynchro(new \DateTimeImmutable('2026-07-02T09:00:00+00:00'));
        $resultat = $client->request('POST', '/api/acces/synchro', $entete + ['json' => $lot])->toArray();
        self::assertCount(1, $resultat['inseres'], 'Le passage est journalisé (inséré), mais refusé.');

        $client->request('GET', '/api/passages/' . $resultat['inseres'][0], $entete);
        $passage = $client->getResponse()->toArray();
        self::assertSame('refuse', $passage['resultat'], 'RG-SPORT-04 : le badge dévalidé côté serveur est refusé localement à la synchro.');
    }

    public function testCa10RestaurationHorsLigneAccepteUnNouveauPassageApresSynchro(): void
    {
        [$client, $entete] = $this->adminSurA();
        [$abonnementId, $droitId] = $this->rattacherDroitDemo($client, $entete);

        $incident = $this->creerIncidentEtEchecRepresentation($client, $entete);

        // Régularisation confirmée côté serveur (résolution 1 clic, moteur générique de recouvrement).
        $client->request('POST', '/api/recouvrement/incidents/' . $incident . '/resoudre', $entete + ['json' => ['canal' => 'virement', 'moyenPaiement' => 'virement']]);
        self::assertResponseIsSuccessful();

        // « Nouveau passage de badge » (re-badge) après la synchro suivante : accepté normalement,
        // aucune procédure manuelle supplémentaire (décision actée §4.7).
        $lot = $this->lotSynchro(new \DateTimeImmutable('2026-07-03T09:00:00+00:00'));
        $resultat = $client->request('POST', '/api/acces/synchro', $entete + ['json' => $lot])->toArray();
        self::assertCount(1, $resultat['inseres'], 'Re-badge accepté sans procédure manuelle après restauration.');
    }

    /** @return array{0: string, 1: string} idAbonnement, idDroitAcces rattaché */
    private function rattacherDroitDemo(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $entete): array
    {
        $abonnementId = $this->idAbonnementDemo();
        $droitId = $this->idDroitAccesDemo();

        $client->request('POST', '/api/sport/abonnements/' . $abonnementId . '/rattacher-droit-acces', $entete + [
            'json' => ['droitAcces' => '/api/droit_acces/' . $droitId],
        ]);
        self::assertResponseIsSuccessful();

        return [$abonnementId, $droitId];
    }

    /** Rejet + échec de représentation → badge refusé (motif impayé), même chemin qu'AntiImpayesTest. */
    private function creerIncidentEtEchecRepresentation(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $entete): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $abonnement = $this->abonnementDemo();
        $echeance = $em->getRepository(\App\Sport\Entity\EcheanceSepa::class)->findOneBy(['abonnement' => $abonnement], ['dateProgrammee' => 'ASC']);

        $client->request('POST', '/api/sport/echeances/' . $echeance->getId() . '/simuler-rejet', $entete + [
            'json' => ['codeRetour' => 'AM04'],
        ]);
        $incidentId = $client->getResponse()->toArray()['id'];

        $representation = $em->getRepository(\App\Recouvrement\Entity\RepresentationSepa::class)->findOneBy(['incident' => $incidentId]);
        $client->request('POST', '/api/recouvrement/representations/' . $representation->getId() . '/enregistrer-resultat', $entete + [
            'json' => ['resultat' => 'echouee'],
        ]);
        self::assertResponseIsSuccessful();

        return $incidentId;
    }

    /** @return array{controleur: string, lot: list<array<string, mixed>>} */
    private function lotSynchro(\DateTimeImmutable $horodatage): array
    {
        return [
            'controleur' => '/api/controleurs/' . $this->idControleur(),
            'lot' => [[
                'equipementId' => $this->idEquipement(),
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
                'sens' => 'entree',
                'horodatage' => $horodatage->format(DATE_ATOM),
                'cleIdempotence' => (string) Uuid::v4(),
            ]],
        ];
    }

    private function idControleur(): string
    {
        return (string) $this->entite(Controleur::class, ['libelle' => AccesFixtures::CONTROLEUR_LIBELLE])->getId();
    }

    private function idEquipement(): string
    {
        return (string) $this->entite(Equipement::class, ['libelle' => AccesFixtures::EQUIPEMENT_LIBELLE])->getId();
    }
}
