<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Unit;

use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\ModeDecompteReservation;
use App\Reservation\Enum\StatutPaiementReservation;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutVente;
use PHPUnit\Framework\TestCase;

/**
 * `Reservation::statutPaiement()` (RG-RESAENC-03) : dérivé de `modeDecompte` + `venteRattachee.statut`,
 * jamais persisté, aucune dépendance base de données requise.
 */
final class StatutPaiementReservationTest extends TestCase
{
    public function testGratuiteSansVenteRattacheeEstSansObjet(): void
    {
        $reservation = (new Reservation())->setModeDecompte(ModeDecompteReservation::Gratuit);

        self::assertSame(StatutPaiementReservation::SansObjet, $reservation->statutPaiement());
        self::assertSame('sans_objet', $reservation->getStatutPaiement());
    }

    public function testQuotaFormuleEstSansObjet(): void
    {
        $reservation = (new Reservation())->setModeDecompte(ModeDecompteReservation::QuotaFormule);

        self::assertSame(StatutPaiementReservation::SansObjet, $reservation->statutPaiement());
    }

    public function testVenteUniteSansVenteRattacheeEstSansObjet(): void
    {
        // Garde défensive : vente_unite sans venteRattachee ne devrait jamais survenir en pratique,
        // mais reste un no-op sûr (source de vérité = Vente.statut, absente ici).
        $reservation = (new Reservation())->setModeDecompte(ModeDecompteReservation::VenteUnite);

        self::assertSame(StatutPaiementReservation::SansObjet, $reservation->statutPaiement());
    }

    public function testVenteEnCoursEstAPayer(): void
    {
        $vente = (new Vente())->setStatut(StatutVente::EnCours);
        $reservation = (new Reservation())->setModeDecompte(ModeDecompteReservation::VenteUnite)->setVenteRattachee($vente);

        self::assertSame(StatutPaiementReservation::APayer, $reservation->statutPaiement());
        self::assertSame('a_payer', $reservation->getStatutPaiement());
    }

    public function testVenteValideeEstPayee(): void
    {
        $vente = (new Vente())->setStatut(StatutVente::Validee);
        $reservation = (new Reservation())->setModeDecompte(ModeDecompteReservation::VenteUnite)->setVenteRattachee($vente);

        self::assertSame(StatutPaiementReservation::Payee, $reservation->statutPaiement());
        self::assertSame('payee', $reservation->getStatutPaiement());
    }

    public function testVenteAvoirEmisResteConsidereePayee(): void
    {
        // §4.3 : « payée puis remboursée » n'est pas un retour à « à payer ».
        $vente = (new Vente())->setStatut(StatutVente::AvoirEmis);
        $reservation = (new Reservation())->setModeDecompte(ModeDecompteReservation::VenteUnite)->setVenteRattachee($vente);

        self::assertSame(StatutPaiementReservation::Payee, $reservation->statutPaiement());
    }

    public function testVenteAnnuleeEstAnnulee(): void
    {
        $vente = (new Vente())->setStatut(StatutVente::Annulee);
        $reservation = (new Reservation())->setModeDecompte(ModeDecompteReservation::VenteUnite)->setVenteRattachee($vente);

        self::assertSame(StatutPaiementReservation::Annulee, $reservation->statutPaiement());
        self::assertSame('annulee', $reservation->getStatutPaiement());
    }
}
