<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Reservation\Enum\ModeFacturationNoShow;
use App\Tests\Reservation\ReservationApiTestCase;
use App\Tests\Reservation\Support\NoShowBillingScenarios;

/**
 * RÉSERVATION ET NO-SHOW : PAS DE VENTE, NI DE DÉBIT, SANS TAUX DE TVA (D123, Q-B3 ; G-9, É17).
 *
 * `VenteReservationHandler::creerVente()` crée la ligne de ses cinq appelants (réservation, confirmation,
 * padel, no-show par débit ou par l'agent). Il refuse désormais avant d'écrire quoi que ce soit, et le
 * no-show refuse donc avant le débit du porte-monnaie. Le produit du padel de démonstration est l'entrée
 * unitaire, de la catégorie que `ComptaFixtures` met en correspondance à 20 % ; on rend cette
 * correspondance invalide en désactivant son taux.
 */
final class ReservationLineWithoutVatRefusalTest extends ReservationApiTestCase
{
    use NoShowBillingScenarios;

    /** Une réservation payante sans taux : 422 qui nomme la catégorie, ni réservation ni vente. Témoin : rétablie, elle passe à 20 %. */
    public function testAPaidReservationWithoutRateWritesNothing(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $creneau = $this->creerCreneauHorsDelai($client, $entete, 120);
        $session = $this->idSessionOuverteOuNouvelle($client, $entete);
        $avant = [$this->compte('reservation_reservation'), $this->compte('vente_vente')];

        $this->rateActive(false);
        $this->reserver($client, $entete, $creneau, $session);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(OffreFixtures::CAT_COMPTABLE, $client->getResponse()->getContent(false));
        self::assertSame($avant, [$this->compte('reservation_reservation'), $this->compte('vente_vente')], 'Ni réservation ni vente.');

        $this->rateActive(true);
        $this->reserver($client, $entete, $creneau, $session);
        self::assertResponseIsSuccessful('Témoin : la même réservation passe, avec sa vente.');
        self::assertSame(['20.00'], $this->db()->fetchFirstColumn(
            'SELECT l.taux_tva FROM vente_ligne l JOIN reservation_reservation r ON r.vente_rattachee_id = l.vente_id WHERE r.creneau_id = UNHEX(:c)',
            ['c' => $this->hex($creneau)],
        ));
    }

    /** Le débit d'un no-show sans taux : refusé avant le débit, porte-monnaie intact, facturation à facturer. Témoin : rétablie, il débite. */
    public function testANoShowDebitWithoutRateLeavesTheWalletIntact(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $this->setWalletBalance('0.00');
        $facturation = $this->creerFacturationNoShow($client, $entete, ModeFacturationNoShow::DebitPmv);
        self::assertSame('a_facturer', $this->billingStatus($facturation));
        $this->setWalletBalance('50.00');

        $this->rateActive(false);
        $client->request('POST', '/api/reservation/facturations-no-show/' . $facturation . '/emettre-vente', $entete + ['json' => []]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(OffreFixtures::CAT_COMPTABLE, $client->getResponse()->getContent(false));
        self::assertSame(['50.00', 'a_facturer', 0], [$this->walletBalance(), $this->billingStatus($facturation), $this->noShowSales()]);

        $this->rateActive(true);
        $client->request('POST', '/api/reservation/facturations-no-show/' . $facturation . '/emettre-vente', $entete + ['json' => []]);
        self::assertResponseIsSuccessful('Témoin : la correspondance rétablie, le débit part.');
        self::assertSame(['40.00', 'facturee', 1], [$this->walletBalance(), $this->billingStatus($facturation), $this->noShowSales()]);
    }

    /**
     * @param array<string, mixed> $entete
     */
    private function reserver(object $client, array $entete, string $creneau, string $session): void
    {
        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => [
                'creneau' => '/api/reservation_creneaus/' . $creneau,
                'organisateur' => '/api/beneficiaires/' . $this->idBeneficiairePayeur(),
                'session' => '/api/session_caisses/' . $session,
            ],
        ]);
    }

    /** Le taux de la correspondance de `ComptaFixtures` (« Taux normal 20 % ») : inactif, la correspondance n'est plus valide. */
    private function rateActive(bool $actif): void
    {
        $this->db()->executeStatement(
            'UPDATE compta_taux_tva t JOIN compta_mapping_comptable m ON m.taux_tva_id = t.id SET t.actif = :a',
            ['a' => (int) $actif],
        );
    }

    private function compte(string $table): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }
}
