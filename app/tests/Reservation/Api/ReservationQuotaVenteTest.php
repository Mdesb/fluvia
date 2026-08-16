<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Api;

use App\Offre\Entity\Formule;
use App\Offre\Entity\ServiceInclus;
use App\Offre\Enum\PeriodiciteFormule;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Reservation\Entity\Activite;
use App\Reservation\Port\Adapter\FormuleBeneficiaireStub;
use App\Reservation\Service\JaugeCreneauGuard;
use App\Tests\Reservation\ReservationApiTestCase;
use App\Vente\Entity\Vente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/** Décompte quota / vente à l'unité / créneau complet (US-RES-02/03, RG-M5-01/02, CA-3/CA-4). */
final class ReservationQuotaVenteTest extends ReservationApiTestCase
{
    public function testCa3ReservationDecompteQuota(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idCreneau = $this->creerCreneau($client, $entete, ReservationFixtures::RESSOURCE_TERRAIN_LIBELLE, ReservationFixtures::ACTIVITE_PADEL_LIBELLE, 4);
        $idBeneficiaire = $this->idBeneficiairePayeur();

        // Le bénéficiaire dispose d'un quota via le port stub (Risque n°5 du plan).
        $this->declarerQuota($idBeneficiaire, ReservationFixtures::ACTIVITE_PADEL_LIBELLE, 2);

        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => [
                'creneau' => '/api/reservation_creneaus/' . $idCreneau,
                'organisateur' => '/api/beneficiaires/' . $idBeneficiaire,
            ],
        ]);

        self::assertResponseIsSuccessful();
        $donnees = $client->getResponse()->toArray();
        self::assertSame('quota_formule', $donnees['modeDecompte'], 'CA-3 : réservation confirmée sans encaissement, quota décompté.');
        self::assertSame('confirmee', $donnees['statut']);
        self::assertSame('0.00', $donnees['montantDu']);
    }

    public function testCa3ReservationDeclencheVenteUniteSansQuota(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idCreneau = $this->creerCreneau($client, $entete, ReservationFixtures::RESSOURCE_TERRAIN_LIBELLE, ReservationFixtures::ACTIVITE_PADEL_LIBELLE, 4);
        $idBeneficiaire = $this->idBeneficiairePayeur();

        $session = $this->ouvrirSession($client, $entete);

        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => [
                'creneau' => '/api/reservation_creneaus/' . $idCreneau,
                'organisateur' => '/api/beneficiaires/' . $idBeneficiaire,
                'session' => '/api/session_caisses/' . $session['id'],
            ],
        ]);

        self::assertResponseIsSuccessful();
        $donnees = $client->getResponse()->toArray();
        self::assertSame('vente_unite', $donnees['modeDecompte'], 'CA-3 : quota épuisé/absent -> vente à l\'unité déclenchée avant confirmation.');
        self::assertSame('24.00', $donnees['montantDu']);
        self::assertNotNull($donnees['venteRattachee'] ?? null);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $ventes = $em->getRepository(Vente::class)->findAll();
        self::assertNotEmpty($ventes, 'Une Vente M2 réelle doit être générée pour la vente à l\'unité (RG-M5-02).');
    }

    public function testCa3SansQuotaEtSansSessionRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idCreneau = $this->creerCreneau($client, $entete, ReservationFixtures::RESSOURCE_TERRAIN_LIBELLE, ReservationFixtures::ACTIVITE_PADEL_LIBELLE, 4);
        $idBeneficiaire = $this->idBeneficiairePayeur();

        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => [
                'creneau' => '/api/reservation_creneaus/' . $idCreneau,
                'organisateur' => '/api/beneficiaires/' . $idBeneficiaire,
            ],
        ]);

        self::assertResponseStatusCodeSame(422, 'Sans quota ni session de caisse, la vente à l\'unité ne peut être déclenchée.');
    }

    public function testCa4CreneauCompletProposeListeAttente(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idCreneau = $this->creerCreneau($client, $entete, ReservationFixtures::RESSOURCE_SALLE_LIBELLE, ReservationFixtures::ACTIVITE_GRATUITE_LIBELLE, 1);
        $idPayeur = $this->idBeneficiairePayeur();
        $idEnfant = $this->idBeneficiaireParPrenom(\App\Crm\DataFixtures\CrmFixtures::ENFANT_PRENOM);

        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => ['creneau' => '/api/reservation_creneaus/' . $idCreneau, 'organisateur' => '/api/beneficiaires/' . $idPayeur],
        ]);
        self::assertResponseIsSuccessful();

        // Deuxième réservation : créneau complet (capacité 1) -> refusée.
        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => ['creneau' => '/api/reservation_creneaus/' . $idCreneau, 'organisateur' => '/api/beneficiaires/' . $idEnfant],
        ]);
        self::assertResponseStatusCodeSame(409, 'CA-4 : créneau complet, réservation directe refusée.');

        // Seule l'inscription en liste d'attente est proposée.
        $client->request('POST', '/api/reservation/creneaux/' . $idCreneau . '/liste-attente', $entete + [
            'json' => ['beneficiaire' => '/api/beneficiaires/' . $idEnfant],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $client->getResponse()->toArray()['rang']);
    }

    /**
     * @param array<string, mixed> $entete
     */
    private function creerCreneau(object $client, array $entete, string $ressourceLibelle, string $activiteLibelle, int $capacite): string
    {
        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . $this->idRessource($ressourceLibelle),
                'activite' => '/api/reservation_activites/' . $this->idActivite($activiteLibelle),
                'debut' => '2026-09-10T14:00:00+00:00',
                'fin' => '2026-09-10T15:00:00+00:00',
                'capacite' => $capacite,
            ],
        ]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray()['id'];
    }

    private function declarerQuota(string $idBeneficiaire, string $libelleActivite, int $quota): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $activite = $em->getRepository(Activite::class)->findOneBy(['libelle' => $libelleActivite]);
        self::assertNotNull($activite);

        $formule = (new Formule())->setPeriodicite(PeriodiciteFormule::Mensuel);
        $em->persist($formule);
        $service = (new ServiceInclus())->setFormule($formule)->setActiviteRef($activite->getId())->setQuota($quota);
        $em->persist($service);
        $em->flush();

        /** @var FormuleBeneficiaireStub $stub */
        $stub = static::getContainer()->get(FormuleBeneficiaireStub::class);
        $stub->definir(Uuid::fromString($idBeneficiaire), $activite->getId(), $service);
    }
}
