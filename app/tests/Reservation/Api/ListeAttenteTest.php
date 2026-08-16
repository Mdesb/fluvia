<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Api;

use App\Crm\DataFixtures\CrmFixtures;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Reservation\Entity\ListeAttente;
use App\Reservation\Service\PromotionListeAttenteHandler;
use App\Tests\Reservation\ReservationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/** Liste d'attente : promotion automatique au désistement (RG-M5-06, CA-5) + expiration (§7 point 11). */
final class ListeAttenteTest extends ReservationApiTestCase
{
    public function testCa5PromotionAutomatiqueAuDesistement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idCreneau = $this->creerCreneauUnePlace($client, $entete);
        $idPayeur = $this->idBeneficiairePayeur();
        $idEnfant = $this->idBeneficiaireParPrenom(CrmFixtures::ENFANT_PRENOM);

        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => ['creneau' => '/api/reservation_creneaus/' . $idCreneau, 'organisateur' => '/api/beneficiaires/' . $idPayeur],
        ]);
        self::assertResponseIsSuccessful();
        $idReservation = $client->getResponse()->toArray()['id'];

        $client->request('POST', '/api/reservation/creneaux/' . $idCreneau . '/liste-attente', $entete + [
            'json' => ['beneficiaire' => '/api/beneficiaires/' . $idEnfant],
        ]);
        self::assertResponseIsSuccessful();

        // Désistement de la réservation confirmée -> promotion automatique du 1er de la liste d'attente.
        $client->request('POST', '/api/reservation/reservations/' . $idReservation . '/annuler', $entete);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/reservation_liste_attentes', $entete + ['query' => ['itemsPerPage' => 10]]);
        self::assertResponseIsSuccessful();
        $membres = $client->getResponse()->toArray()['member'] ?? $client->getResponse()->toArray()['hydra:member'];
        self::assertSame('promue', $membres[0]['statut'], 'CA-5 : le premier de la liste d\'attente est promu automatiquement, sans validation manuelle.');
        self::assertNotNull($membres[0]['promueEn'] ?? null);
    }

    public function testExpirationPromotionSansConfirmationPromeutLeRangSuivant(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idCreneau = $this->creerCreneauUnePlace($client, $entete);
        $idPayeur = $this->idBeneficiairePayeur();
        $idEnfant = $this->idBeneficiaireParPrenom(CrmFixtures::ENFANT_PRENOM);
        $idConjoint = $this->idBeneficiaireParPrenom(CrmFixtures::CONJOINT_PRENOM);

        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => ['creneau' => '/api/reservation_creneaus/' . $idCreneau, 'organisateur' => '/api/beneficiaires/' . $idPayeur],
        ]);
        self::assertResponseIsSuccessful();
        $idReservation = $client->getResponse()->toArray()['id'];

        $client->request('POST', '/api/reservation/creneaux/' . $idCreneau . '/liste-attente', $entete + ['json' => ['beneficiaire' => '/api/beneficiaires/' . $idEnfant]]);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/reservation/creneaux/' . $idCreneau . '/liste-attente', $entete + ['json' => ['beneficiaire' => '/api/beneficiaires/' . $idConjoint]]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/reservation/reservations/' . $idReservation . '/annuler', $entete);
        self::assertResponseIsSuccessful();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $premiere = $em->getRepository(ListeAttente::class)->findOneBy(['rang' => 1]);
        self::assertNotNull($premiere);
        self::assertSame('promue', $premiere->getStatut()->value);

        /** @var PromotionListeAttenteHandler $handler */
        $handler = static::getContainer()->get(PromotionListeAttenteHandler::class);
        $traites = $handler->expirerPromotionsDepassees((new \DateTimeImmutable())->modify('+1 hour'));
        self::assertSame(1, $traites, '§7 point 11 : la promotion non confirmée dans le délai expire.');

        $em->clear();
        $premiereApres = $em->getRepository(ListeAttente::class)->findOneBy(['rang' => 1]);
        self::assertSame('expiree', $premiereApres->getStatut()->value);
        $deuxieme = $em->getRepository(ListeAttente::class)->findOneBy(['rang' => 2]);
        self::assertSame('promue', $deuxieme->getStatut()->value, 'Le rang suivant est promu après expiration.');
    }

    /**
     * @param array<string, mixed> $entete
     */
    private function creerCreneauUnePlace(object $client, array $entete): string
    {
        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . $this->idRessource(ReservationFixtures::RESSOURCE_SALLE_LIBELLE),
                'activite' => '/api/reservation_activites/' . $this->idActivite(ReservationFixtures::ACTIVITE_GRATUITE_LIBELLE),
                'debut' => '2026-09-11T14:00:00+00:00',
                'fin' => '2026-09-11T15:00:00+00:00',
                'capacite' => 1,
            ],
        ]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray()['id'];
    }
}
