<?php

declare(strict_types=1);

namespace App\Tests\Group\Api;

use App\Compta\Entity\TauxTva;
use App\Group\DataFixtures\GroupFixtures;
use App\Group\Entity\GroupBooking;
use App\Tests\Group\GroupApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Gratuités transverses : contingent, octroi (avec refus au-delà du quota), révocation qui recrédite,
 * et surtout — les gratuités sortent du décompte payant du devis.
 */
final class GroupGratuiteTest extends GroupApiTestCase
{
    public function testContingentOctroiEtRevocation(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();

        $client->request('POST', '/api/group_gratuite_contingents', $entete + [
            'json' => ['label' => 'Scolaires ville', 'quota' => 10],
        ]);
        self::assertResponseIsSuccessful();
        $cid = $client->getResponse()->toArray()['id'];
        self::assertSame(10, $client->getResponse()->toArray()['placesRestantes']);

        $bid = $this->creerBooking($client, $entete, 12);

        // Octroi de 3 gratuités.
        $client->request('POST', '/api/group/bookings/' . $bid . '/grant-gratuite', $entete + [
            'json' => ['contingent' => '/api/group_gratuite_contingents/' . $cid, 'quantite' => 3, 'motif' => 'élèves'],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/group_gratuites?booking=' . $bid, $entete);
        self::assertResponseIsSuccessful();
        self::assertSame(1, self::total($client->getResponse()->toArray()));
        $gid = self::membres($client->getResponse()->toArray())[0]['id'];

        self::assertSame(7, $this->placesRestantes($client, $entete), 'Le contingent est décompté de 3.');

        // Au-delà du reste : refus.
        $client->request('POST', '/api/group/bookings/' . $bid . '/grant-gratuite', $entete + [
            'json' => ['contingent' => '/api/group_gratuite_contingents/' . $cid, 'quantite' => 8],
        ]);
        self::assertResponseStatusCodeSame(409, 'Un contingent épuisé refuse l\'octroi.');

        // Révocation : le contingent est recrédité.
        $client->request('DELETE', '/api/group_gratuites/' . $gid, $entete);
        self::assertResponseStatusCodeSame(204);
        self::assertSame(10, $this->placesRestantes($client, $entete), 'La révocation recrédite le contingent.');
    }

    public function testLesGratuitesSortentDuDevis(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();

        $client->request('POST', '/api/group_gratuite_contingents', $entete + [
            'json' => ['label' => 'Contingent', 'quota' => 20],
        ]);
        self::assertResponseIsSuccessful();
        $cid = $client->getResponse()->toArray()['id'];

        $bid = $this->creerBooking($client, $entete, 10);
        $client->request('POST', '/api/group/bookings/' . $bid . '/grant-gratuite', $entete + [
            'json' => ['contingent' => '/api/group_gratuite_contingents/' . $cid, 'quantite' => 4],
        ]);
        self::assertResponseIsSuccessful();

        // 10 − 4 = 6 payants × 5,00 € HT à 20 % = 36,00 € TTC (et non 60,00 sans les gratuités).
        $client->request('POST', '/api/group/bookings/' . $bid . '/invoice', $entete + [
            'json' => ['tauxTva' => $this->idTauxTva(), 'prixUnitaireHT' => '5.00'],
        ]);
        self::assertResponseIsSuccessful();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $booking = $em->getRepository(GroupBooking::class)->find(Uuid::fromString($bid));
        self::assertNotNull($booking);
        $doc = $booking->getCommercialDocument();
        self::assertNotNull($doc, 'Un devis doit être rattaché.');
        self::assertEqualsWithDelta(36.0, (float) $doc->getTotalTTC(), 0.001, 'Seules les entrées payantes (6) sont facturées.');
    }

    public function testConfirmationRepartitPayantEtGratuit(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();

        // Un contingent, puis une réservation « par personne » d'effectif 3 avec 1 gratuité.
        // (Le créneau de démonstration a 3 places libres — l'effectif tient tout juste.)
        $client->request('POST', '/api/group_gratuite_contingents', $entete + [
            'json' => ['label' => 'Scolaires', 'quota' => 5],
        ]);
        self::assertResponseIsSuccessful();
        $cid = $client->getResponse()->toArray()['id'];

        $client->request('POST', '/api/group/bookings', $entete + [
            'json' => [
                'group' => '/api/participant_groups/' . $this->idGroupe(GroupFixtures::GROUPE_A_LABEL),
                'effectif' => 3,
                'grain' => 'per_person',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $bid = $client->getResponse()->toArray()['id'];

        $client->request('POST', '/api/group/bookings/' . $bid . '/grant-gratuite', $entete + [
            'json' => ['contingent' => '/api/group_gratuite_contingents/' . $cid, 'quantite' => 1, 'motif' => 'accompagnateur'],
        ]);
        self::assertResponseIsSuccessful();

        // Diagnostic : la gratuité est bien rattachée à la réservation avant la confirmation.
        $client->request('GET', '/api/group_gratuites?booking=' . $bid, $entete);
        self::assertSame(1, self::total($client->getResponse()->toArray()), 'La gratuité doit être rattachée avant confirmation.');

        $client->request('POST', '/api/group/bookings/' . $bid . '/assign', $entete + [
            'json' => ['creneau' => '/api/reservation_creneaux/' . $this->unCreneauDeA()],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/group/bookings/' . $bid . '/confirm', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        // 3 entrées = 2 payantes (vente_unite, différées) + 1 gratuite (gratuit) — le grain visiteur
        // du musée, avec sa sémantique argent. Paiement différé : montantDu nul, la somme est au devis.
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $booking = $em->getRepository(GroupBooking::class)->find(Uuid::fromString($bid));
        self::assertNotNull($booking);
        $modes = ['vente_unite' => 0, 'gratuit' => 0];
        foreach ($booking->getJaugeReservations() as $reservation) {
            $mode = $reservation->getModeDecompte()->value;
            $modes[$mode] = ($modes[$mode] ?? 0) + 1;
            self::assertSame('0.00', $reservation->getMontantDu(), 'Paiement différé : montantDu nul, la somme est portée par le devis.');
        }
        self::assertSame(2, $modes['vente_unite'], 'Deux entrées payantes en vente_unite (différées).');
        self::assertSame(1, $modes['gratuit'], 'Une entrée gratuite en gratuit.');
    }

    private function creerBooking(object $client, array $entete, int $effectif): string
    {
        $client->request('POST', '/api/group/bookings', $entete + [
            'json' => ['group' => '/api/participant_groups/' . $this->idGroupe(GroupFixtures::GROUPE_A_LABEL), 'effectif' => $effectif],
        ]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray()['id'];
    }

    /**
     * @param array<string, mixed> $entete
     */
    private function placesRestantes(object $client, array $entete): int
    {
        $client->request('GET', '/api/group_gratuite_contingents', $entete);
        self::assertResponseIsSuccessful();

        return (int) self::membres($client->getResponse()->toArray())[0]['placesRestantes'];
    }

    private function idTauxTva(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $taux = $em->getRepository(TauxTva::class)->findOneBy(['taux' => '20.00', 'actif' => true]);
        self::assertNotNull($taux);

        return (string) $taux->getId();
    }
}
