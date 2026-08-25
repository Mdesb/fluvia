<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Api;

use App\Acces\Entity\DroitAcces;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\TypeDroitAcces;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Tests\Reservation\ReservationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * CQ-3 + CQ-6 — carte de N réservations : un quota de **stock**, décompté **à la réservation**.
 *
 * La carte est désignée explicitement dans la requête : c'est le cas du comptoir, où l'agent la
 * scanne. La résolution automatique « la carte de ce bénéficiaire » attend CQ-0 (rattachement du
 * droit à un porteur), qui n'existe pas encore — la mécanique testée ici n'aura qu'un résolveur à
 * recevoir en amont.
 */
final class CarteReservationsTest extends ReservationApiTestCase
{
    /** Le solde se vide à la réservation, et une carte épuisée refuse — elle ne passe pas en vente. */
    public function testLaCarteSeDecompteAReserverEtRefuseUneFoisEpuisee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $idCreneau = $this->creerCreneau($client, $entete, 10);
        $idCarte = $this->creerCarte(SocleFixtures::ETAB_A_NOM, 2);

        $premiere = $this->reserverAvecCarte($client, $entete, $idCreneau, $idCarte);
        self::assertResponseIsSuccessful();
        self::assertSame('carte_stock', $premiere['modeDecompte']);
        self::assertSame('0.00', $premiere['montantDu'], 'Une séance déjà payée ne se refacture pas.');
        self::assertSame(1, $this->creditRestant($idCarte));

        $this->reserverAvecCarte($client, $entete, $idCreneau, $idCarte);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->creditRestant($idCarte));

        // Épuisée : refus explicite. Surtout pas un basculement silencieux vers la vente à l'unité —
        // le client croirait payer avec sa carte et paierait deux fois.
        $this->reserverAvecCarte($client, $entete, $idCreneau, $idCarte);
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('épuisée', $client->getResponse()->toArray(false)['detail'] ?? '');
    }

    /** L'annulation dans les délais rend la séance : le décompte ayant lieu à la réservation, ne pas la rendre la volerait. */
    public function testLAnnulationRendLaSeance(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $idCreneau = $this->creerCreneau($client, $entete, 10);
        $idCarte = $this->creerCarte(SocleFixtures::ETAB_A_NOM, 1);

        $reservation = $this->reserverAvecCarte($client, $entete, $idCreneau, $idCarte);
        self::assertResponseIsSuccessful();
        self::assertSame($idCarte, $reservation['creditDroitRef'], 'La réservation retient quelle carte elle a débitée.');
        self::assertSame(0, $this->creditRestant($idCarte));

        $client->request('POST', '/api/reservation/reservations/' . $reservation['id'] . '/annuler', $entete);
        self::assertResponseIsSuccessful();

        self::assertSame(1, $this->creditRestant($idCarte), 'La séance revient au client.');
    }

    /** Cloisonnement (D3/D8) : la carte est désignée par le client, donc elle se confronte au périmètre serveur. */
    public function testUneCarteDUnAutreEtablissementEstIntrouvable(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $idCreneau = $this->creerCreneau($client, $entete, 10);
        $idCarteB = $this->creerCarte(SocleFixtures::ETAB_B_NOM, 5);

        $this->reserverAvecCarte($client, $entete, $idCreneau, $idCarteB);
        // 404 et non 403 : confirmer l'existence d'une carte d'un autre établissement serait déjà une fuite.
        self::assertResponseStatusCodeSame(404);
        self::assertSame(5, $this->creditRestant($idCarteB), 'Et surtout : rien n\'a été débité.');
    }

    private function creerCarte(string $nomEtablissement, int $credit): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etablissement = $em->getRepository(Etablissement::class)->findOneBy(['nom' => $nomEtablissement]);
        self::assertNotNull($etablissement);

        $droit = new DroitAcces();
        $droit->setSourceType(TypeDroitAcces::CarteQuota)
            ->setCreditRestant($credit)
            ->setStatutProjection(StatutProjectionDroit::Valide)
            ->setFenetreDebut(new \DateTimeImmutable('-1 day'))
            ->setFenetreFin(new \DateTimeImmutable('+1 year'))
            ->setEtablissement($etablissement);
        $em->persist($droit);
        $em->flush();

        return (string) $droit->getId();
    }

    private function creditRestant(string $idCarte): ?int
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $droit = $em->getRepository(DroitAcces::class)->find($idCarte);
        self::assertNotNull($droit);

        return $droit->getCreditRestant();
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    private function reserverAvecCarte(object $client, array $entete, string $idCreneau, string $idCarte): array
    {
        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => [
                'creneau' => '/api/reservation_creneaus/' . $idCreneau,
                'organisateur' => '/api/beneficiaires/' . $this->idBeneficiairePayeur(),
                'carte' => $idCarte,
            ],
        ]);

        return $client->getResponse()->toArray(false);
    }

    /** @param array<string, mixed> $entete */
    private function creerCreneau(object $client, array $entete, int $capacite): string
    {
        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . $this->idRessource(ReservationFixtures::RESSOURCE_SALLE_LIBELLE),
                'activite' => '/api/reservation_activites/' . $this->idActivite(ReservationFixtures::ACTIVITE_GRATUITE_LIBELLE),
                'debut' => '2026-09-11T14:00:00+00:00',
                'fin' => '2026-09-11T15:00:00+00:00',
                'capacite' => $capacite,
            ],
        ]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray()['id'];
    }
}
