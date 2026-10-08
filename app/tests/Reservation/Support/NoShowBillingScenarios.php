<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Support;

use App\Caisse\Entity\PointDeVente;
use App\Caisse\Entity\SessionCaisse;
use App\Crm\DataFixtures\CrmFixtures;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Reservation\Entity\Activite;
use App\Reservation\Entity\FacturationNoShow;
use App\Reservation\Entity\RegleAnnulation;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\ModeFacturationNoShow;
use App\Reservation\Enum\ModeMontantAnnulation;
use App\Reservation\Enum\PorteeRegleAnnulation;
use App\Vente\DataFixtures\VenteFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Une facturation d'absence réelle : une règle sur l'activité de padel, une réservation gratuite sur un
 * créneau tout proche, puis son annulation hors délai. Le porte-monnaie du payeur (CrmFixtures) et les
 * ventes du no-show se lisent EN BASE.
 */
trait NoShowBillingScenarios
{
    /**
     * @param array<string, mixed> $entete
     */
    private function creerFacturationNoShow(object $client, array $entete, ModeFacturationNoShow $mode, string $montant = '10.00', int $decalageMinutes = 10): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $activite = $em->getRepository(Activite::class)->findOneBy(['libelle' => ReservationFixtures::ACTIVITE_PADEL_LIBELLE]);

        $regle = (new RegleAnnulation())->setEtablissement($activite->getEtablissement())
            ->setPortee(PorteeRegleAnnulation::Activite)->setCibleActivite($activite)
            ->setDelaiFrancMinutes(1440)->setModeMontant(ModeMontantAnnulation::Fixe)->setValeurMontant($montant)
            ->setModeFacturation($mode)->setMargePostCreneauMinutes(0)->setActif(true);
        $em->persist($regle);
        $em->flush();

        $idCreneau = $this->creerCreneauHorsDelai($client, $entete, $decalageMinutes);
        $idReservation = $this->reserverGratuit($client, $entete, $idCreneau);

        $client->request('POST', '/api/reservation/reservations/' . $idReservation . '/annuler', $entete);
        self::assertResponseIsSuccessful();
        self::assertSame('annulee_tardive_facturee', $client->getResponse()->toArray()['statut']);

        return $this->facturationDeReservation($idReservation);
    }

    private function facturationDeReservation(string $idReservation): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $reservation = $em->getRepository(Reservation::class)->find($idReservation);
        $facturation = $em->getRepository(FacturationNoShow::class)->findOneBy(['reservation' => $reservation]);
        self::assertNotNull($facturation);

        return (string) $facturation->getId();
    }

    /**
     * @param array<string, mixed> $entete
     */
    private function creerCreneauHorsDelai(object $client, array $entete, int $decalageMinutes = 10): string
    {
        $debut = (new \DateTimeImmutable(sprintf('+%d minutes', $decalageMinutes)))->format(\DateTimeInterface::ATOM);
        $fin = (new \DateTimeImmutable(sprintf('+%d minutes', $decalageMinutes + 60)))->format(\DateTimeInterface::ATOM);
        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . $this->idRessource(ReservationFixtures::RESSOURCE_TERRAIN_LIBELLE),
                'activite' => '/api/reservation_activites/' . $this->idActivite(ReservationFixtures::ACTIVITE_PADEL_LIBELLE),
                'debut' => $debut,
                'fin' => $fin,
                'capacite' => 4,
            ],
        ]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray()['id'];
    }

    /**
     * @param array<string, mixed> $entete
     */
    private function reserverGratuit(object $client, array $entete, string $idCreneau): string
    {
        $idSession = $this->idSessionOuverteOuNouvelle($client, $entete);
        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => [
                'creneau' => '/api/reservation_creneaus/' . $idCreneau,
                'organisateur' => '/api/beneficiaires/' . $this->idBeneficiairePayeur(),
                'session' => '/api/session_caisses/' . $idSession,
            ],
        ]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray()['id'];
    }

    /**
     * Réutilise une session déjà ouverte sur le point de vente de démonstration si elle existe
     * (RG-M2-01 : une seule session active par point de vente), sinon en ouvre une nouvelle.
     *
     * @param array<string, mixed> $entete
     */
    private function idSessionOuverteOuNouvelle(object $client, array $entete): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $pdv = $em->getRepository(PointDeVente::class)->findOneBy(['libelle' => VenteFixtures::PDV_LIBELLE]);
        $sessionExistante = $pdv !== null ? $em->getRepository(SessionCaisse::class)->findOneBy(['pointDeVente' => $pdv]) : null;
        if ($sessionExistante !== null && $sessionExistante->estOuverte()) {
            return (string) $sessionExistante->getId();
        }

        return (string) $this->ouvrirSession($client, $entete)['id'];
    }

    private function walletBalance(): string
    {
        return (string) $this->db()->fetchOne(
            'SELECT p.solde FROM crm_pmv p JOIN crm_client c ON c.id = p.client_id WHERE c.email = :e',
            ['e' => CrmFixtures::PAYEUR_EMAIL],
        );
    }

    private function setWalletBalance(string $solde): void
    {
        $this->db()->executeStatement(
            'UPDATE crm_pmv p JOIN crm_client c ON c.id = p.client_id SET p.solde = :s WHERE c.email = :e',
            ['s' => $solde, 'e' => CrmFixtures::PAYEUR_EMAIL],
        );
    }

    private function billingStatus(string $facturation): string
    {
        return (string) $this->db()->fetchOne('SELECT statut FROM reservation_facturation_no_show WHERE id = UNHEX(:f)', ['f' => $this->hex($facturation)]);
    }

    /** Les ventes d'absence en base, d'après le libellé que leur donne la stratégie (`DebitPmvStrategie`, `VenteDiffereeAgentStrategie`). */
    private function noShowSales(string $libelle = 'No-show (débit PMV automatique)%'): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(DISTINCT vente_id) FROM vente_ligne WHERE note LIKE :n', ['n' => $libelle]);
    }

    private function db(): Connection
    {
        /** @var Connection $connexion */
        $connexion = static::getContainer()->get('doctrine')->getConnection();

        return $connexion;
    }

    private function hex(string $uuid): string
    {
        return str_replace('-', '', $uuid);
    }
}
