<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Api;

use App\Reservation\Command\BasculerNoShowCommand;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Reservation\Entity\FacturationNoShow;
use App\Reservation\Entity\Reservation;
use App\Tests\Reservation\ReservationApiTestCase;
use App\Vente\Entity\Vente;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Réservation payante → encaissement en caisse (`M5 × M2`, comblement de gaps G1/G2/G3) : statut de
 * paiement observable (RG-RESAENC-03), avoir de remboursement à l'annulation d'une réservation payée
 * dans le délai franc (RG-RESAENC-09, G1), nettoyage d'une Vente pendante jamais réglée (RG-RESAENC-10,
 * G2), non-régression produit gratuit (G3) et no-show (RG-M5-09).
 */
final class ReservationEncaissementTest extends ReservationApiTestCase
{
    public function testCa1ReservationPayanteExposeStatutAPayer(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idCreneau = $this->creerCreneau($client, $entete, '2026-10-05T10:00:00+00:00', '2026-10-05T11:00:00+00:00');
        $session = $this->ouvrirSession($client, $entete);

        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => [
                'creneau' => '/api/reservation_creneaus/' . $idCreneau,
                'organisateur' => '/api/beneficiaires/' . $this->idBeneficiairePayeur(),
                'session' => '/api/session_caisses/' . $session['id'],
            ],
        ]);
        self::assertResponseIsSuccessful();
        $donnees = $client->getResponse()->toArray();

        self::assertSame('vente_unite', $donnees['modeDecompte']);
        self::assertSame('24.00', $donnees['montantDu']);
        self::assertSame('a_payer', $donnees['statutPaiement'], 'CA-1 : vente rattachée en_cours -> statutPaiement = a_payer.');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $vente = $em->getRepository(Vente::class)->findAll()[0] ?? null;
        self::assertNotNull($vente);
        self::assertSame('en_cours', $vente->getStatut()->value);
    }

    public function testCa2EncaissementGuichetPasseReservationPayee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idCreneau = $this->creerCreneau($client, $entete, '2026-10-06T10:00:00+00:00', '2026-10-06T11:00:00+00:00');
        $idReservation = $this->reserverPayant($client, $entete, $idCreneau);

        $reservationAvant = $client->request('GET', '/api/reservations/' . $idReservation, $entete)->toArray();
        $idVente = $this->extraireIdIri($reservationAvant['venteRattachee']);

        $client->request('POST', '/api/ventes/' . $idVente . '/paiements', $entete + ['json' => ['moyen' => 'especes', 'montant' => '24.00']]);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/ventes/' . $idVente . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/reservations/' . $idReservation, $entete);
        self::assertResponseIsSuccessful();
        $donnees = $client->getResponse()->toArray();
        self::assertSame('payee', $donnees['statutPaiement'], 'CA-2 : Vente validée -> statutPaiement = payee.');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $vente = $em->getRepository(Vente::class)->find($idVente);
        self::assertSame('validee', $vente->getStatut()->value);
    }

    public function testCa3ProduitGratuitAucuneVenteStatutSansObjet(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idCreneau = $this->creerCreneau($client, $entete, '2026-10-07T10:00:00+00:00', '2026-10-07T11:00:00+00:00', ReservationFixtures::RESSOURCE_SALLE_LIBELLE, ReservationFixtures::ACTIVITE_GRATUITE_LIBELLE);

        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => [
                'creneau' => '/api/reservation_creneaus/' . $idCreneau,
                'organisateur' => '/api/beneficiaires/' . $this->idBeneficiairePayeur(),
            ],
        ]);
        self::assertResponseIsSuccessful();
        $donnees = $client->getResponse()->toArray();

        self::assertSame('gratuit', $donnees['modeDecompte']);
        self::assertSame('0.00', $donnees['montantDu']);
        self::assertNull($donnees['venteRattachee'] ?? null);
        self::assertSame('sans_objet', $donnees['statutPaiement'], 'CA-3/G3 : produit gratuit -> aucune Vente, statutPaiement = sans_objet.');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        self::assertCount(0, $em->getRepository(Vente::class)->findAll());
    }

    public function testCa4AnnulationResaPayeeDansDelaiGenereAvoir(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        // Créneau largement au-delà du délai franc (24h) : annulation immédiate = dans le délai.
        // Relatif au lancement, pas une date fixe (voir `creneauDans()`).
        $idCreneau = $this->creerCreneau($client, $entete, ...self::creneauDans(30));
        $idReservation = $this->reserverPayant($client, $entete, $idCreneau);

        $reservation = $client->request('GET', '/api/reservations/' . $idReservation, $entete)->toArray();
        $idVente = $this->extraireIdIri($reservation['venteRattachee']);
        $this->encaisser($client, $entete, $idVente, '24.00');

        $client->request('POST', '/api/reservation/reservations/' . $idReservation . '/annuler', $entete);
        self::assertResponseIsSuccessful();
        $donnees = $client->getResponse()->toArray();
        self::assertSame('annulee_libre', $donnees['statut'], 'G1 : place libérée dans le délai franc.');
        self::assertSame('payee', $donnees['statutPaiement'], '§4.3 : avoir_emis reste "payée" (payée puis remboursée).');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $vente = $em->getRepository(Vente::class)->find($idVente);
        self::assertSame('avoir_emis', $vente->getStatut()->value, 'G1 : Vente passée en avoir_emis.');

        $avoirs = $em->getRepository(\App\Vente\Entity\Avoir::class)->findBy(['venteOrigine' => $vente]);
        self::assertCount(1, $avoirs, 'G1 : un unique Avoir de remboursement généré.');
        self::assertSame('remboursement', $avoirs[0]->getNature());
        self::assertSame('24.00', $avoirs[0]->getMontant(), 'G1 : remboursement intégral (RG-RESAENC-09).');
    }

    public function testCa5AnnulationResaPayeeHorsDelaiAucunRemboursement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idCreneau = $this->creerCreneau($client, $entete, '2027-06-10T10:00:00+00:00', '2027-06-10T11:00:00+00:00');
        $idReservation = $this->reserverPayant($client, $entete, $idCreneau);

        $reservation = $client->request('GET', '/api/reservations/' . $idReservation, $entete)->toArray();
        $idVente = $this->extraireIdIri($reservation['venteRattachee']);
        $this->encaisser($client, $entete, $idVente, '24.00');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $entiteReservation = $em->getRepository(Reservation::class)->find($idReservation);
        $entiteReservation->setDateLimiteAnnulation((new \DateTimeImmutable())->modify('-5 minutes'));
        $em->flush();
        $em->clear();

        $client->request('POST', '/api/reservation/reservations/' . $idReservation . '/annuler', $entete);
        self::assertResponseIsSuccessful();
        $donnees = $client->getResponse()->toArray();
        self::assertSame('annulee_tardive_facturee', $donnees['statut']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $vente = $em->getRepository(Vente::class)->find($idVente);
        self::assertSame('validee', $vente->getStatut()->value, 'CA-5 : hors délai franc, aucun remboursement -> Vente reste validee.');
        self::assertCount(0, $em->getRepository(\App\Vente\Entity\Avoir::class)->findBy(['venteOrigine' => $vente]), 'CA-5 : aucun Avoir créé.');
    }

    public function testCa6AnnulationResaAPayerNonRegleeNettoieVente(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        // Dans le délai franc lui aussi (« annulee_libre » attendu) : même date fixe, même bombe, qui
        // aurait sauté le 08/10 à 12:00 (Paris). Relatif au lancement (voir `creneauDans()`).
        $idCreneau = $this->creerCreneau($client, $entete, ...self::creneauDans(30));
        $idReservation = $this->reserverPayant($client, $entete, $idCreneau);

        $reservation = $client->request('GET', '/api/reservations/' . $idReservation, $entete)->toArray();
        $idVente = $this->extraireIdIri($reservation['venteRattachee']);

        $client->request('POST', '/api/reservation/reservations/' . $idReservation . '/annuler', $entete);
        self::assertResponseIsSuccessful();
        $donnees = $client->getResponse()->toArray();
        self::assertSame('annulee_libre', $donnees['statut']);
        self::assertSame('annulee', $donnees['statutPaiement'], 'G2 : Vente pendante nettoyée -> statutPaiement = annulee.');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $vente = $em->getRepository(Vente::class)->find($idVente);
        self::assertSame('annulee', $vente->getStatut()->value, 'G2 : Vente en_cours jamais réglée -> annulee.');
        self::assertSame('0.00', $vente->getTotal(), 'G2 : lignes vidées, total recalculé à 0.');
        self::assertCount(0, $vente->getLignes(), 'G2 : aucune ligne persistante restante.');
        self::assertCount(0, $em->getRepository(\App\Vente\Entity\Avoir::class)->findBy(['venteOrigine' => $vente]), 'G2 : aucun Avoir (rien n\'a été encaissé).');
    }

    public function testCa9NoShowSurReservationDejaPayeeNeRembourseRienNiDoubleFacture(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idCreneau = $this->creerCreneau($client, $entete, '2026-09-22T10:00:00+00:00', '2026-09-22T11:00:00+00:00');
        $idReservation = $this->reserverPayant($client, $entete, $idCreneau);

        $reservation = $client->request('GET', '/api/reservations/' . $idReservation, $entete)->toArray();
        $idVente = $this->extraireIdIri($reservation['venteRattachee']);
        $this->encaisser($client, $entete, $idVente, '24.00');

        /** @var BasculerNoShowCommand $commande */
        $commande = static::getContainer()->get(BasculerNoShowCommand::class);
        $commande->basculer(new \DateTimeImmutable('2026-09-22T11:05:00+00:00'));

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $reservationApres = $em->getRepository(Reservation::class)->find($idReservation);
        self::assertSame('no_show_facture', $reservationApres->getStatut()->value, 'CA-9 : bascule no-show malgré paiement préalable.');

        $vente = $em->getRepository(Vente::class)->find($idVente);
        self::assertSame('validee', $vente->getStatut()->value, 'CA-9 : la Vente initiale déjà payée reste acquise, aucun remboursement automatique.');
        self::assertCount(0, $em->getRepository(\App\Vente\Entity\Avoir::class)->findBy(['venteOrigine' => $vente]));

        $facturations = $em->getRepository(FacturationNoShow::class)->findBy(['reservation' => $reservationApres]);
        self::assertNotEmpty($facturations, 'CA-9 : le mécanisme no-show standard continue de s\'appliquer.');
    }

    public function testCa10VenteEnCoursNettoyeeAussiHorsDelaiFranc(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idCreneau = $this->creerCreneau($client, $entete, '2027-06-11T10:00:00+00:00', '2027-06-11T11:00:00+00:00');
        $idReservation = $this->reserverPayant($client, $entete, $idCreneau);

        $reservation = $client->request('GET', '/api/reservations/' . $idReservation, $entete)->toArray();
        $idVente = $this->extraireIdIri($reservation['venteRattachee']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $entiteReservation = $em->getRepository(Reservation::class)->find($idReservation);
        $entiteReservation->setDateLimiteAnnulation((new \DateTimeImmutable())->modify('-5 minutes'));
        $em->flush();
        $em->clear();

        $client->request('POST', '/api/reservation/reservations/' . $idReservation . '/annuler', $entete);
        self::assertResponseIsSuccessful();
        $donnees = $client->getResponse()->toArray();
        self::assertSame('annulee_tardive_facturee', $donnees['statut']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $vente = $em->getRepository(Vente::class)->find($idVente);
        self::assertSame('annulee', $vente->getStatut()->value, 'Décision n°2 : G2 nettoie aussi hors délai franc (pas de panier fantôme).');

        $reservationEntite = $em->getRepository(Reservation::class)->find($idReservation);
        $facturations = $em->getRepository(FacturationNoShow::class)->findBy(['reservation' => $reservationEntite]);
        self::assertNotEmpty($facturations, 'Le mécanisme no-show standard coexiste sans conflit avec le nettoyage G2.');
    }

    /**
     * @param array<string, mixed> $entete
     */
    private function creerCreneau(
        object $client,
        array $entete,
        string $debut,
        string $fin,
        string $ressourceLibelle = ReservationFixtures::RESSOURCE_TERRAIN_LIBELLE,
        string $activiteLibelle = ReservationFixtures::ACTIVITE_PADEL_LIBELLE,
    ): string {
        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . $this->idRessource($ressourceLibelle),
                'activite' => '/api/reservation_activites/' . $this->idActivite($activiteLibelle),
                'debut' => $debut,
                'fin' => $fin,
                'capacite' => 4,
            ],
        ]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray()['id'];
    }

    /**
     * Réserve un créneau payant (activité PADEL, 24,00 €) avec session de caisse ouverte : déclenche
     * la Vente rattachée (RG-M5-02).
     *
     * @param array<string, mixed> $entete
     */
    private function reserverPayant(object $client, array $entete, string $idCreneau): string
    {
        $session = $this->ouvrirSession($client, $entete);
        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => [
                'creneau' => '/api/reservation_creneaus/' . $idCreneau,
                'organisateur' => '/api/beneficiaires/' . $this->idBeneficiairePayeur(),
                'session' => '/api/session_caisses/' . $session['id'],
            ],
        ]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray()['id'];
    }

    /**
     * @param array<string, mixed> $entete
     */
    private function encaisser(object $client, array $entete, string $idVente, string $montant): void
    {
        $client->request('POST', '/api/ventes/' . $idVente . '/paiements', $entete + ['json' => ['moyen' => 'especes', 'montant' => $montant]]);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/ventes/' . $idVente . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();
    }

    private function extraireIdIri(mixed $iriOuTableau): string
    {
        if (\is_array($iriOuTableau)) {
            return (string) ($iriOuTableau['id'] ?? '');
        }

        return basename((string) $iriOuTableau);
    }
}
