<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Api;

use App\Caisse\Entity\PointDeVente;
use App\Caisse\Entity\SessionCaisse;
use App\Reservation\Command\BasculerNoShowCommand;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Reservation\Entity\Activite;
use App\Reservation\Entity\FacturationNoShow;
use App\Reservation\Entity\RegleAnnulation;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\ModeFacturationNoShow;
use App\Reservation\Enum\ModeMontantAnnulation;
use App\Reservation\Enum\PorteeRegleAnnulation;
use App\Reservation\Sepa\ReservationEcheanceSepaSource;
use App\Tests\Reservation\ReservationApiTestCase;
use App\Vente\DataFixtures\VenteFixtures;
use App\Vente\Entity\Vente;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Stratégies de facturation no-show (décision structurante n°4 du plan, CA-11/12).
 * Réellement branchées : vente_differee_agent, debit_pmv. Squelettes documentés : prelevement_differe,
 * facture_a_encaisser. §7 cas limite : aucune RegleAnnulation active -> aucune facturation.
 */
final class FacturationNoShowStrategiesTest extends ReservationApiTestCase
{
    public function testCa11VenteDiffereeAgentEmiseParAgent(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idFacturation = $this->creerFacturationNoShow($client, $entete, ModeFacturationNoShow::VenteDiffereeAgent);

        // Une session est déjà ouverte (créée par `creerFacturationNoShow` pour la vente à l'unité de
        // la réservation initiale, RG-M2-01 : une seule session active par point de vente) : réutilisée
        // par l'agent pour émettre la vente no-show.
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $sessionOuverte = $em->getRepository(SessionCaisse::class)->findOneBy(['pointDeVente' => $em->getRepository(PointDeVente::class)->findOneBy(['libelle' => VenteFixtures::PDV_LIBELLE])]);
        self::assertNotNull($sessionOuverte);

        $client->request('POST', '/api/reservation/facturations-no-show/' . $idFacturation . '/emettre-vente', $entete + [
            'json' => ['session' => '/api/session_caisses/' . $sessionOuverte->getId()],
        ]);
        self::assertResponseIsSuccessful();
        $donnees = $client->getResponse()->toArray();
        self::assertSame('facturee', $donnees['statut'], 'CA-11 : la FacturationNoShow passe à facturée.');
        self::assertNotNull($donnees['venteRattachee'] ?? null);

        $facturation = $em->getRepository(FacturationNoShow::class)->find($idFacturation);
        self::assertNotNull($facturation->getVenteRattachee(), 'CA-11 : une vente M2 est traçable jusqu\'à la réservation d\'origine.');
        self::assertInstanceOf(Vente::class, $em->getRepository(Vente::class)->find($facturation->getVenteRattachee()->getId()));
    }

    public function testCa11DebitPmvAutomatiqueSansAgent(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        // Le bénéficiaire payeur dispose d'un PMV crédité (50 €, CrmFixtures) -> débit auto sans agent.
        $idFacturation = $this->creerFacturationNoShow($client, $entete, ModeFacturationNoShow::DebitPmv, montant: '10.00');

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $facturation = $em->getRepository(FacturationNoShow::class)->find($idFacturation);
        self::assertSame('facturee', $facturation->getStatut()->value, 'CA-11 : débit PMV automatique, sans agent présent.');
        self::assertNotNull($facturation->getVenteRattachee());
    }

    public function testPrelevementDiffereGenereEcheanceSepa(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        // Le bénéficiaire payeur dispose d'un mandat SEPA actif (SepaFixtures) -> échéance générée.
        $idFacturation = $this->creerFacturationNoShow($client, $entete, ModeFacturationNoShow::PrelevementDiffere);

        $client->request('POST', '/api/reservation/facturations-no-show/' . $idFacturation . '/emettre-vente', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $facturation = $em->getRepository(FacturationNoShow::class)->find($idFacturation);
        self::assertNotNull($facturation->getReferenceEcheanceSepa(), 'Squelette : échéance SEPA générée.');
        self::assertSame('a_facturer', $facturation->getStatut()->value, 'Squelette documenté : aucun encaissement garanti (statut inchangé, spec §8).');

        /** @var ReservationEcheanceSepaSource $source */
        $source = static::getContainer()->get(ReservationEcheanceSepaSource::class);
        $etablissement = $facturation->getReservation()->getEtablissement();
        $echeances = $source->echeancesDues($etablissement, new \DateTimeImmutable());
        $references = array_map(static fn ($e) => $e->referenceOrigine, $echeances);
        self::assertContains($idFacturation, $references, 'L\'échéance est exposée via le port EcheanceSepaSource (plan §3/§5).');
    }

    public function testCa12ExonerationTraceeSansEncaissement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idFacturation = $this->creerFacturationNoShow($client, $entete, ModeFacturationNoShow::VenteDiffereeAgent);

        $client->request('POST', '/api/reservation/facturations-no-show/' . $idFacturation . '/exonerer', $entete + [
            'json' => ['motif' => 'Membre exonéré (1ʳᵉ occurrence tolérée).'],
        ]);
        self::assertResponseIsSuccessful();
        $donnees = $client->getResponse()->toArray();
        self::assertSame('exoneree', $donnees['statut'], 'CA-12 : exonération sans encaissement.');
        self::assertNull($donnees['venteRattachee'] ?? null);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $facturation = $em->getRepository(FacturationNoShow::class)->find($idFacturation);
        self::assertNotNull($facturation->getExonerePar(), 'CA-12 : l\'exonération est tracée (auteur).');
        self::assertNotNull($facturation->getMotifExoneration());
    }

    public function testAucuneRegleActiveAucuneFacturation(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        // Désactive la règle établissement de démonstration : aucune règle active ne couvre le créneau.
        foreach ($em->getRepository(RegleAnnulation::class)->findAll() as $regle) {
            $regle->setActif(false);
        }
        $em->flush();

        $idCreneau = $this->creerCreneauHorsDelai($client, $entete);
        $idReservation = $this->reserverGratuit($client, $entete, $idCreneau);

        // Sans RegleAnnulation active, aucun délai franc n'est défini : l'annulation reste gratuite
        // (§7 cas limite, comportement historique — la moins agressive des options possibles).
        $client->request('POST', '/api/reservation/reservations/' . $idReservation . '/annuler', $entete);
        self::assertResponseIsSuccessful();
        self::assertSame('annulee_libre', $client->getResponse()->toArray()['statut']);

        // Bascule no-show automatique (créneau non annulé, sans présence) : même en no-show, sans
        // règle active, aucune FacturationNoShow n'est créée. Décalage horaire pour éviter un
        // chevauchement de ressource avec le créneau précédent.
        $idCreneau2 = $this->creerCreneauHorsDelai($client, $entete, 180);
        $idReservation2 = $this->reserverGratuit($client, $entete, $idCreneau2);

        /** @var BasculerNoShowCommand $commande */
        $commande = static::getContainer()->get(BasculerNoShowCommand::class);
        $commande->basculer((new \DateTimeImmutable())->modify('+5 hours'));

        $em->clear();
        $reservationApres = $em->getRepository(Reservation::class)->find($idReservation2);
        self::assertSame('no_show_facture', $reservationApres->getStatut()->value, 'Le statut réservation reflète le no-show même sans règle (point de vigilance UX documenté, plan §9).');
        $facturations = $em->getRepository(FacturationNoShow::class)->findBy(['reservation' => $reservationApres]);
        self::assertEmpty($facturations, '§7 : aucune RegleAnnulation active -> aucune FacturationNoShow créée (comportement historique).');
    }

    /**
     * @param array<string, mixed> $entete
     */
    private function creerFacturationNoShow(object $client, array $entete, ModeFacturationNoShow $mode, string $montant = '10.00'): string
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

        $idCreneau = $this->creerCreneauHorsDelai($client, $entete);
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
}
