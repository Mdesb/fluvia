<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Api;

use App\Crm\DataFixtures\CrmFixtures;
use App\Reservation\DataFixtures\ReservationFixtures;
use App\Reservation\Entity\Ressource;
use App\Tests\Reservation\ReservationApiTestCase;

/** Ressource partageable — jauge propre par créneau + jauge globale (RG-M5-08, CA-14). */
final class RessourcePartageableTest extends ReservationApiTestCase
{
    public function testCa14JaugeRessourceMereCoherente(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $bassin = $this->entite(Ressource::class, ['libelle' => ReservationFixtures::RESSOURCE_BASSIN_LIBELLE]);
        // Réduit la capacité du bassin (jauge mère) à 2 pour un test rapide de dépassement.
        $bassin->setCapacitePropre(2);
        $em->flush();

        $ligne1 = $this->idRessource(ReservationFixtures::RESSOURCE_LIGNE_1_LIBELLE);
        $ligne2 = $this->idRessource(ReservationFixtures::RESSOURCE_LIGNE_2_LIBELLE);

        $idCreneauLigne1 = $this->creerCreneau($client, $entete, $ligne1, 5);
        $idCreneauLigne2 = $this->creerCreneau($client, $entete, $ligne2, 5);

        $idPayeur = $this->idBeneficiairePayeur();
        $idEnfant = $this->idBeneficiaireParPrenom(CrmFixtures::ENFANT_PRENOM);
        $idConjoint = $this->idBeneficiaireParPrenom(CrmFixtures::CONJOINT_PRENOM);

        // 1ʳᵉ réservation sur la ligne 1 : occupation mère = 1/2.
        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => ['creneau' => '/api/reservation_creneaus/' . $idCreneauLigne1, 'organisateur' => '/api/beneficiaires/' . $idPayeur],
        ]);
        self::assertResponseIsSuccessful();

        // 2ᵉ réservation sur la ligne 2 (différente) : occupation mère = 2/2, chaque créneau a encore
        // de la place individuellement (capacité 5 chacun).
        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => ['creneau' => '/api/reservation_creneaus/' . $idCreneauLigne2, 'organisateur' => '/api/beneficiaires/' . $idEnfant],
        ]);
        self::assertResponseIsSuccessful();

        // 3ᵉ réservation : la ligne 1 a encore de la place (2/5), mais la jauge de la ressource mère
        // (bassin, 2/2) est atteinte -> refusée malgré la place individuelle du créneau.
        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => ['creneau' => '/api/reservation_creneaus/' . $idCreneauLigne1, 'organisateur' => '/api/beneficiaires/' . $idConjoint],
        ]);
        self::assertResponseStatusCodeSame(409, 'CA-14 : la jauge de la ressource mère (bassin) est cohérente et bloque le dépassement.');

        $em->clear();
        $bassinApres = $em->getRepository(Ressource::class)->find($bassin->getId());
        self::assertSame(2, $bassinApres->getOccupationCourante(), 'Le compteur de la ressource mère reste cohérent avec la somme des occupations.');
    }

    /**
     * @param array<string, mixed> $entete
     */
    private function creerCreneau(object $client, array $entete, string $idRessource, int $capacite): string
    {
        static $compteur = 0;
        ++$compteur;
        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => '/api/reservation_ressources/' . $idRessource,
                'debut' => sprintf('2026-10-0%dT09:00:00+00:00', $compteur),
                'fin' => sprintf('2026-10-0%dT10:00:00+00:00', $compteur),
                'capacite' => $capacite,
            ],
        ]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray()['id'];
    }
}
