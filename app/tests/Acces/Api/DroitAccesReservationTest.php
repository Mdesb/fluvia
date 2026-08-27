<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Tests\SchemaDuHarnais;
use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Acces\DataFixtures\AccesFixtures;
use App\Compta\DataFixtures\ComptaFixtures;
use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client as CrmClient;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Organisation\Entity\Etablissement;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Reservation\Entity\ProjectionAccesReservation;
use App\Reservation\Entity\Reservation;
use App\Reservation\Entity\Ressource;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Sepa\DataFixtures\SepaFixtures;
use App\Vente\DataFixtures\VenteFixtures;
use Doctrine\ORM\EntityManagerInterface;

/**
 * CA-2 (RG-ACC3-07, plan-acc3.md §7) : un `DroitAcces` de type `Booking` projeté depuis une
 * réservation confirmée (ACC-3) suit exactement le moteur générique L3 une fois appairé, sans aucune
 * modification de `ValidationPassageHandler`. Charge la fois les fixtures socle du module Réservation
 * (pour créer une réservation réelle via l'API) et celles du module Accès (permissions/topologie).
 */
final class DroitAccesReservationTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();

        // Le schéma est construit UNE FOIS par processus, puis vidé entre les tests. Le faire
        // détruire et reconstruire par chaque `setUp()` coûtait ~10 s par test — six heures sur
        // la suite complète, et donc une suite que personne ne lançait.
        SchemaDuHarnais::reinitialiser($em);

        foreach ([
            SocleFixtures::class, OffreFixtures::class, ComptaFixtures::class, VenteFixtures::class,
            CrmFixtures::class, SepaFixtures::class, ReservationFixtures::class, AccesFixtures::class,
        ] as $classe) {
            $fixture = $container->get($classe);
            $fixture->load($em);
        }

        self::ensureKernelShutdown();
    }

    public function testAppairageEtPassageAccepteDansLaFenetre(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        [$idReservation, $debut] = $this->creerReservationOuvreAcces($client, $entete);
        $droitId = $this->droitPourReservation($idReservation);

        $client->request('POST', '/api/acces/appairages', $entete + [
            'json' => [
                'identifiantSupport' => 'RFID-RESA-ACC3-0001',
                'typeSupport' => 'RFID',
                'droit' => '/api/droit_acces/' . $droitId,
                'mode' => 'caisse',
            ],
        ]);
        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent(false));

        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => [
                'equipement' => '/api/equipements/' . $this->idEquipement(),
                'identifiantSupport' => 'RFID-RESA-ACC3-0001',
                'horodatage' => $debut->modify('+5 minutes')->format(DATE_ATOM),
            ],
        ]);
        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent(false));
        self::assertSame('valide', $client->getResponse()->toArray()['resultat'], 'CA-2 : passage dans la fenêtre accepté, moteur générique inchangé.');
    }

    public function testPassageRefuseHorsFenetre(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        [$idReservation, , $fin] = $this->creerReservationOuvreAcces($client, $entete);
        $droitId = $this->droitPourReservation($idReservation);

        $client->request('POST', '/api/acces/appairages', $entete + [
            'json' => [
                'identifiantSupport' => 'RFID-RESA-ACC3-0002',
                'typeSupport' => 'RFID',
                'droit' => '/api/droit_acces/' . $droitId,
                'mode' => 'caisse',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => [
                'equipement' => '/api/equipements/' . $this->idEquipement(),
                'identifiantSupport' => 'RFID-RESA-ACC3-0002',
                'horodatage' => $fin->modify('+1 hour')->format(DATE_ATOM),
            ],
        ]);
        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent(false));
        $reponse = $client->getResponse()->toArray();
        self::assertSame('refuse', $reponse['resultat']);
        self::assertSame('hors_marge', $reponse['codeMotif'], 'CA-2 : passage hors fenêtre refusé (CodeMotifRefus::HorsMarge).');
    }

    /** @return array{0: string, 1: \DateTimeImmutable, 2: \DateTimeImmutable} id réservation, début, fin fenêtre */
    private function creerReservationOuvreAcces(Client $client, array $entete): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $terrain = $em->getRepository(Ressource::class)->findOneBy(['libelle' => ReservationFixtures::RESSOURCE_TERRAIN_LIBELLE]);
        self::assertNotNull($terrain);
        $terrain->setOuvreAcces(true);
        $em->flush();

        $debut = new \DateTimeImmutable('2026-11-10T10:00:00+00:00');
        $fin = $debut->modify('+60 minutes');

        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . $terrain->getId(),
                'activite' => '/api/reservation_activites/' . $this->idActivite(),
                'debut' => $debut->format(DATE_ATOM),
                'fin' => $fin->format(DATE_ATOM),
                'capacite' => 4,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $idCreneau = $client->getResponse()->toArray()['id'];

        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => ['creneau' => '/api/reservation_creneaus/' . $idCreneau, 'organisateur' => '/api/beneficiaires/' . $this->idBeneficiairePayeur()],
        ]);
        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent(false));

        return [$client->getResponse()->toArray()['id'], $debut, $fin];
    }

    private function droitPourReservation(string $idReservation): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $reservation = $em->getRepository(Reservation::class)->find($idReservation);
        self::assertNotNull($reservation);
        $projection = $em->getRepository(ProjectionAccesReservation::class)->findOneBy(['reservation' => $reservation]);
        self::assertNotNull($projection, 'CA-1 : une projection a été créée sur confirmation.');
        $droitRef = $projection->getDroitAccesRef();
        self::assertNotNull($droitRef, 'CA-1 : la projection réelle référence un DroitAcces.');

        return (string) $droitRef;
    }

    protected function jeton(Client $client, string $email, string $motDePasse): string
    {
        return $client->request('POST', '/auth', [
            'json' => ['email' => $email, 'motDePasse' => $motDePasse],
        ])->toArray()['token'];
    }

    /** @return array{0: Client, 1: array<string, mixed>} */
    private function adminSurA(): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);
        $idA = (string) $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM])->getId();
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        return [$client, $entete];
    }

    private function idActivite(): string
    {
        return (string) $this->entite(\App\Reservation\Entity\Activite::class, ['libelle' => ReservationFixtures::ACTIVITE_GRATUITE_LIBELLE])->getId();
    }

    private function idBeneficiairePayeur(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $payeur = $em->getRepository(CrmClient::class)->findOneBy(['email' => CrmFixtures::PAYEUR_EMAIL]);
        self::assertNotNull($payeur);
        $beneficiaire = $em->getRepository(Beneficiaire::class)->findOneBy(['client' => $payeur]);
        self::assertNotNull($beneficiaire);

        return (string) $beneficiaire->getId();
    }

    private function idEquipement(): string
    {
        return (string) $this->entite(\App\Acces\Entity\Equipement::class, ['libelle' => AccesFixtures::EQUIPEMENT_LIBELLE])->getId();
    }

    /**
     * @template T of object
     *
     * @param class-string<T>      $classe
     * @param array<string, mixed> $criteres
     *
     * @return T
     */
    private function entite(string $classe, array $criteres): object
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $entite = $em->getRepository($classe)->findOneBy($criteres);
        self::assertNotNull($entite);

        return $entite;
    }
}
