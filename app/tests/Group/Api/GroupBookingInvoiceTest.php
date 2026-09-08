<?php

declare(strict_types=1);

namespace App\Tests\Group\Api;

use App\Compta\Entity\TauxTva;
use App\Group\DataFixtures\GroupFixtures;
use App\Group\Entity\GroupBooking;
use App\Group\Entity\ParticipantGroup;
use App\Tests\Group\GroupApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Facturation d'une réservation de groupe : génère un devis pour le payeur (chaîne
 * devis → bon de commande → facture NF525), refuse sans payeur, et ne facture pas deux fois.
 */
final class GroupBookingInvoiceTest extends GroupApiTestCase
{
    public function testFacturerGenereUnDevisRattache(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();
        $idBooking = $this->idBookingDemo();

        $client->request('POST', '/api/group/bookings/' . $idBooking . '/invoice', $entete + [
            'json' => ['tauxTva' => $this->idTauxTva(), 'prixUnitaireHT' => '12.00'],
        ]);
        self::assertResponseIsSuccessful();
        $r = $client->getResponse()->toArray();
        self::assertNotNull($r['commercialDocument'] ?? null, 'Le devis doit être rattaché à la réservation.');
        self::assertSame('purchase_order', $r['paymentStatus'], 'Le paiement passe à « bon de commande ».');

        // On ne facture pas deux fois : un devis existe déjà.
        $client->request('POST', '/api/group/bookings/' . $idBooking . '/invoice', $entete + [
            'json' => ['tauxTva' => $this->idTauxTva(), 'prixUnitaireHT' => '12.00'],
        ]);
        self::assertResponseStatusCodeSame(422, 'Une réservation déjà facturée refuse un second devis.');
    }

    public function testFacturerSansPayeurRefuse(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();

        // Un groupe sans client → sa réservation n'a pas de payeur → refus.
        $client->request('POST', '/api/participant_groups', $entete + [
            'json' => ['label' => 'Groupe sans payeur', 'organizerName' => 'X'],
        ]);
        self::assertResponseIsSuccessful();
        $gid = $client->getResponse()->toArray()['id'];

        $client->request('POST', '/api/group/bookings', $entete + [
            'json' => ['group' => '/api/participant_groups/' . $gid, 'effectif' => 5],
        ]);
        self::assertResponseIsSuccessful();
        $bid = $client->getResponse()->toArray()['id'];

        $client->request('POST', '/api/group/bookings/' . $bid . '/invoice', $entete + [
            'json' => ['tauxTva' => $this->idTauxTva(), 'prixUnitaireHT' => '10.00'],
        ]);
        self::assertResponseStatusCodeSame(422, 'Sans payeur (ni sur la réservation ni sur le groupe), pas de devis.');
    }

    private function idBookingDemo(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $groupe = $em->getRepository(ParticipantGroup::class)->findOneBy(['label' => GroupFixtures::GROUPE_A_LABEL]);
        self::assertNotNull($groupe, 'Groupe de démonstration introuvable.');
        $booking = $em->getRepository(GroupBooking::class)->findOneBy(['group' => $groupe]);
        self::assertNotNull($booking, 'Réservation de démonstration introuvable.');

        return (string) $booking->getId();
    }

    private function idTauxTva(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $taux = $em->getRepository(TauxTva::class)->findOneBy(['taux' => '20.00', 'actif' => true]);
        self::assertNotNull($taux, 'Taux de TVA 20 % introuvable dans les fixtures.');

        return (string) $taux->getId();
    }
}
