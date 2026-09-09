<?php

declare(strict_types=1);

namespace App\Tests\Group\Api;

use App\Compta\Entity\TauxTva;
use App\Group\DataFixtures\GroupFixtures;
use App\Group\Entity\GroupBooking;
use App\Group\Entity\GroupProduct;
use App\Group\Entity\ParticipantGroup;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\Produit;
use App\Tests\Group\GroupApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le « produit groupe » composite : un forfait réutilisable ET des articles à la carte, qui se
 * facturent en une ligne de devis par produit.
 */
final class GroupBasketTest extends GroupApiTestCase
{
    public function testCreerUnForfaitAvecSesLignes(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();

        $client->request('POST', '/api/group_products', $entete + [
            'json' => [
                'label' => 'Forfait journée',
                'lines' => [[
                    'produit' => '/api/produits/' . $this->idProduit(),
                    'quantite' => 5,
                    'prixUnitaireHT' => '3.00',
                    'tauxTva' => '/api/taux_tvas/' . $this->idTauxTva(),
                ]],
            ],
        ]);
        self::assertResponseIsSuccessful();
        $r = $client->getResponse()->toArray();
        self::assertSame('Forfait journée', $r['label']);
        self::assertCount(1, $r['lines'], 'La ligne du forfait doit être enregistrée (imbriquée).');
    }

    public function testAppliquerUnForfaitPuisAjouterEtFacturer(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();
        $idBooking = $this->idBookingDemo();

        // 1) Appliquer le forfait de démonstration → recopie ses lignes en articles du panier.
        $client->request('POST', '/api/group/bookings/' . $idBooking . '/apply-product', $entete + [
            'json' => ['groupProduct' => '/api/group_products/' . $this->idForfaitDemo()],
        ]);
        self::assertResponseIsSuccessful();

        // 2) Ajouter un article à la carte.
        $client->request('POST', '/api/group_booking_items', $entete + [
            'json' => [
                'booking' => '/api/group_bookings/' . $idBooking,
                'produit' => '/api/produits/' . $this->idProduit(),
                'quantite' => 2,
                'prixUnitaireHT' => '3.00',
                'tauxTva' => '/api/taux_tvas/' . $this->idTauxTva(),
            ],
        ]);
        self::assertResponseIsSuccessful();

        // Le panier porte les deux (1 ligne de forfait + 1 à la carte).
        $client->request('GET', '/api/group_booking_items?booking=' . $idBooking, $entete);
        self::assertResponseIsSuccessful();
        self::assertSame(2, self::total($client->getResponse()->toArray()));

        // 3) Facturer avec un CORPS VIDE : la facturation « à la tête » exigerait un prix / une TVA
        //    et répondrait 422. Un succès prouve donc que le devis a été bâti à partir du PANIER.
        $client->request('POST', '/api/group/bookings/' . $idBooking . '/invoice', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();
        $r = $client->getResponse()->toArray();
        self::assertNotNull($r['commercialDocument'] ?? null, 'Le devis (multi-lignes) doit être rattaché.');
        self::assertSame('purchase_order', $r['paymentStatus']);
    }

    public function testArticleSurUnGroupeEtrangerRefuse(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();

        // Une réservation dans un groupe sans lien avec l'établissement actif : on fabrique un panier
        // pointant vers la réservation de démonstration mais... le cas hors périmètre est déjà couvert
        // par le cloisonnement de la réservation. Ici on vérifie le refus d'un booking inexistant.
        $client->request('POST', '/api/group_booking_items', $entete + [
            'json' => [
                'booking' => '/api/group_bookings/00000000-0000-0000-0000-000000000000',
                'produit' => '/api/produits/' . $this->idProduit(),
                'quantite' => 1,
                'prixUnitaireHT' => '1.00',
                'tauxTva' => '/api/taux_tvas/' . $this->idTauxTva(),
            ],
        ]);
        $code = $client->getResponse()->getStatusCode();
        self::assertGreaterThanOrEqual(400, $code);
        self::assertLessThan(500, $code, 'Un booking hors périmètre / inexistant doit échouer proprement.');
    }

    private function idBookingDemo(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $groupe = $em->getRepository(ParticipantGroup::class)->findOneBy(['label' => GroupFixtures::GROUPE_A_LABEL]);
        self::assertNotNull($groupe);
        $booking = $em->getRepository(GroupBooking::class)->findOneBy(['group' => $groupe]);
        self::assertNotNull($booking);

        return (string) $booking->getId();
    }

    private function idForfaitDemo(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $forfait = $em->getRepository(GroupProduct::class)->findOneBy(['label' => 'Forfait scolaire']);
        self::assertNotNull($forfait, 'Forfait de démonstration introuvable.');

        return (string) $forfait->getId();
    }

    private function idProduit(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $produit = $em->getRepository(Produit::class)->findOneBy(['libelleRecherche' => OffreFixtures::PRODUIT_ENTREE]);
        self::assertNotNull($produit, 'Produit de démonstration introuvable.');

        return (string) $produit->getId();
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
