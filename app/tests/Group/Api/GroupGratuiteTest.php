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
