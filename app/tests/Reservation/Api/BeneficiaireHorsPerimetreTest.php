<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Api;

use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\Crm\Entity\Famille;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Reservation\Entity\ListeAttente;
use App\Reservation\Entity\Reservation;
use App\Tests\Reservation\ReservationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D3/D8 — un bénéficiaire désigné dans le corps d'une requête se confronte au périmètre.
 *
 * Le trou fermé ici n'est pas un simple défaut de cloisonnement : la fiche désignée **apparaît
 * ensuite** dans la réservation, avec son identité et sa part de paiement, ou — en liste d'attente —
 * le rang obtenu confirme son existence. C'est une fuite de données personnelles.
 *
 * La règle appliquée est celle de `PerimetreCrmExtension` : le groupe du client porteur doit être
 * celui d'une région d'un établissement où l'utilisateur possède une affectation. Le montage crée
 * donc un **groupe neuf**, sur lequel personne n'est affecté — un client de l'établissement B ne
 * prouverait rien, l'admin de démonstration y étant affecté.
 */
final class BeneficiaireHorsPerimetreTest extends ReservationApiTestCase
{
    /** Ajouter un participant : la fiche d'un autre groupe est introuvable, et rien n'est écrit. */
    public function testAjouterUnParticipantHorsPerimetreEstRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $idCreneau = $this->creerCreneau($client, $entete);

        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => [
                'creneau' => '/api/reservation_creneaus/' . $idCreneau,
                'organisateur' => '/api/beneficiaires/' . $this->idBeneficiairePayeur(),
            ],
        ]);
        self::assertResponseIsSuccessful();
        $idReservation = $client->getResponse()->toArray()['id'];

        $idEtranger = $this->creerBeneficiaireDunAutreGroupe();

        $client->request('POST', '/api/reservation/reservations/' . $idReservation . '/participants', $entete + [
            'json' => ['personne' => '/api/beneficiaires/' . $idEtranger, 'partMontant' => '10.00'],
        ]);
        // Même message qu'un identifiant inexistant : pas d'oracle d'énumération sur les fiches clients.
        self::assertResponseStatusCodeSame(422);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $reservation = $em->getRepository(Reservation::class)->find($idReservation);
        self::assertNotNull($reservation);
        self::assertCount(0, $reservation->getParticipants(), 'Aucun participant ne doit avoir été écrit.');
    }

    /** Liste d'attente : même trou, même fermeture. */
    public function testInscrireUnBeneficiaireHorsPerimetreEstRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $idCreneau = $this->creerCreneau($client, $entete);

        $idEtranger = $this->creerBeneficiaireDunAutreGroupe();

        $client->request('POST', '/api/reservation/creneaux/' . $idCreneau . '/liste-attente', $entete + [
            'json' => ['beneficiaire' => '/api/beneficiaires/' . $idEtranger],
        ]);
        self::assertResponseStatusCodeSame(422);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        self::assertSame(0, (int) $em->getRepository(ListeAttente::class)->count([]), 'Aucune inscription ne doit avoir été écrite.');
    }

    /** Non-régression : un bénéficiaire du périmètre passe toujours, sinon la garde serait un mur. */
    public function testUnBeneficiaireDuPerimetrePasseToujours(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $idCreneau = $this->creerCreneau($client, $entete);

        $client->request('POST', '/api/reservation/creneaux/' . $idCreneau . '/liste-attente', $entete + [
            'json' => ['beneficiaire' => '/api/beneficiaires/' . $this->idBeneficiairePayeur()],
        ]);
        self::assertResponseIsSuccessful();
    }

    /** Un bénéficiaire rattaché à un groupe neuf, où personne n'est affecté. */
    private function creerBeneficiaireDunAutreGroupe(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        // Groupe -> region -> etablissement complets : `Client.etablissementCreation` est NOT NULL,
        // et rattacher la fiche a un etablissement du groupe A rendrait le montage incoherent — le
        // test doit ressembler a une vraie organisation voisine, pas a un bricolage.
        $groupe = (new Groupe())->setNom('Groupe hors perimetre ' . uniqid());
        $em->persist($groupe);

        $region = (new Region())->setNom('Region hors perimetre ' . uniqid())->setGroupe($groupe);
        $em->persist($region);

        $etablissement = (new Etablissement())->setNom('Etablissement hors perimetre ' . uniqid())->setRegion($region);
        $em->persist($etablissement);

        $clientCrm = (new Client())->setGroupe($groupe)->setNom('Etranger')->setPrenom('Fiche')
            ->setEtablissementCreation($etablissement);
        $em->persist($clientCrm);

        $famille = (new Famille())->setGroupe($groupe)->setLibelle('Famille hors perimetre')
            ->setPayeurPrincipal($clientCrm);
        $em->persist($famille);

        $beneficiaire = (new Beneficiaire())->setClient($clientCrm)->setFamille($famille);
        $em->persist($beneficiaire);
        $em->flush();

        return (string) $beneficiaire->getId();
    }

    /** @param array<string, mixed> $entete */
    private function creerCreneau(object $client, array $entete): string
    {
        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . $this->idRessource(ReservationFixtures::RESSOURCE_SALLE_LIBELLE),
                'activite' => '/api/reservation_activites/' . $this->idActivite(ReservationFixtures::ACTIVITE_GRATUITE_LIBELLE),
                'debut' => '2026-09-15T14:00:00+00:00',
                'fin' => '2026-09-15T15:00:00+00:00',
                'capacite' => 4,
            ],
        ]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray()['id'];
    }
}
