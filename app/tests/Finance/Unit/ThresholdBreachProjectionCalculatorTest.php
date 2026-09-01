<?php

declare(strict_types=1);

namespace App\Tests\Finance\Unit;

use App\DataFixtures\SocleFixtures;
use App\Facturation\Entity\DestinataireFacturation;
use App\Facturation\Entity\Facture;
use App\Facturation\Enum\NatureFacture;
use App\Facturation\Enum\OrigineFacture;
use App\Facturation\Enum\StatutFacture;
use App\Facturation\Enum\TypeDestinataire;
use App\Finance\DataFixtures\FinanceFixtures;
use App\Finance\SupplierInvoice\Entity\SupplierInvoice;
use App\Finance\Treasury\Service\ThresholdBreachProjectionCalculator;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Tests\Finance\TreasuryApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;

/**
 * T3 du plan (§0.4, RG-TRE-11/12) — `ThresholdBreachProjectionCalculator::projeter()` : réutilise
 * `TreasuryPositionCalculator`/`PaymentScheduleCalculator` tels quels, aucun second moteur de calcul.
 */
final class ThresholdBreachProjectionCalculatorTest extends TreasuryApiTestCase
{
    /** RG-TRE-11 — scénario de la mission : solde 5000 €, sortie 6000 € à J+12, fenêtre 30 j. */
    public function testFranchissementDetecteRetientLaPremiereDate(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->creerCompteBancaire($client, $entete, ['openingBalance' => '5000.00']);

        $aujourdhui = new \DateTimeImmutable('today');
        $echeance = $aujourdhui->modify('+12 days');
        $facture = $this->approuverFactureFournisseur($client, $entete, $echeance, '500.00', '10.000', 'FACT-BREACH-001');

        $etablissement = $this->etablissementA();
        $calculator = $this->calculator();

        $projection = $calculator->projeter($etablissement, $aujourdhui, 0, 30);

        self::assertNotNull($projection->breachDate);
        self::assertSame($echeance->format('Y-m-d'), $projection->breachDate->format('Y-m-d'));
        self::assertSame(-100000, $projection->balanceCentsAtBreach, 'Solde cumulé = 500 000 − 600 000 = −100 000 centimes.');
        self::assertSame('supplier_invoice', $projection->causeSource);
        self::assertSame((string) $facture->getId(), $projection->causeSourceId);
        self::assertSame(600000, $projection->causeAmountCents);
    }

    /** §4.2 point 5/§4.3 — érosion sans grosse échéance isolée (breach porté par une entrée insuffisante, aucun exit avant la date) : causeSource = null. */
    public function testAucuneSortieAvantLeFranchissementCauseNulle(): void
    {
        [$client, $entete] = $this->adminSurA();
        // Solde déjà sous le seuil (0 € face à un seuil de 1000 €) : la première date de franchissement
        // est celle du premier mouvement connu, une simple entrée client — aucune sortie ne figure dans
        // l'échéancier avant cette date.
        $this->creerCompteBancaire($client, $entete, ['openingBalance' => '0.00']);
        $this->creerFactureClient($this->etablissementA(), new \DateTimeImmutable('today +5 days'), '200.00', 'FA-BREACH-CAUSE-NULLE');

        $projection = $this->calculator()->projeter($this->etablissementA(), new \DateTimeImmutable('today'), 100000, 30);

        self::assertNotNull($projection->breachDate);
        self::assertNull($projection->causeSource, 'Aucune sortie avant la date de franchissement : cause non nommée (dégradation propre, §4.3).');
        self::assertNull($projection->causeSourceId);
        self::assertNull($projection->causeAmountCents);
    }

    /** RG-TRE-11 point 5 — un mouvement qui ramène le solde EXACTEMENT au seuil n'est pas un franchissement (égalité stricte). */
    public function testEgaliteAuSeuilNestPasUnFranchissement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->creerCompteBancaire($client, $entete, ['openingBalance' => '5000.00']);

        $echeance = new \DateTimeImmutable('today +12 days');
        $this->approuverFactureFournisseur($client, $entete, $echeance, '500.00', '10.000', 'FACT-EGALITE-001');

        // Seuil = -100 000 centimes : le cumul après la sortie de 600 000 vaut EXACTEMENT -100 000.
        $projection = $this->calculator()->projeter($this->etablissementA(), new \DateTimeImmutable('today'), -100000, 30);

        self::assertNull($projection->breachDate, 'Une égalité au seuil ne doit jamais être traitée comme un franchissement.');
    }

    /** RG-TRE-12 — deux sorties, la plus grosse (antérieure ou égale à la date de franchissement) l'emporte comme cause. */
    public function testPlusGrosseSortieAvantLaDateRetenueCommeCause(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->creerCompteBancaire($client, $entete, ['openingBalance' => '10000.00']);

        // HT 3 000,00 € -> TTC 3 600,00 € (360 000 c) ; HT 8 000,00 € -> TTC 9 600,00 € (960 000 c).
        $petite = $this->approuverFactureFournisseur($client, $entete, new \DateTimeImmutable('today +5 days'), '300.00', '10.000', 'FACT-CAUSE-PETITE');
        $grosse = $this->approuverFactureFournisseur($client, $entete, new \DateTimeImmutable('today +10 days'), '800.00', '10.000', 'FACT-CAUSE-GROSSE');

        // Cumul : 1 000 000 − 360 000 (J+5) = 640 000 ; 640 000 − 960 000 (J+10) = −320 000 < seuil (0).
        $projection = $this->calculator()->projeter($this->etablissementA(), new \DateTimeImmutable('today'), 0, 30);

        self::assertNotNull($projection->breachDate);
        self::assertSame((new \DateTimeImmutable('today +10 days'))->format('Y-m-d'), $projection->breachDate->format('Y-m-d'));
        self::assertSame((string) $grosse->getId(), $projection->causeSourceId, 'La sortie de 9 600 € doit être retenue plutôt que celle de 3 600 €.');
        self::assertNotSame((string) $petite->getId(), $projection->causeSourceId);
        self::assertSame(960000, $projection->causeAmountCents);
    }

    /** §4.2 — aucune date de franchissement trouvée dans l'horizon -> projection vide. */
    public function testAucunFranchissementDansLHorizonRenvoieProjectionVide(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->creerCompteBancaire($client, $entete, ['openingBalance' => '1000.00']);

        $projection = $this->calculator()->projeter($this->etablissementA(), new \DateTimeImmutable('today'), 0, 30);

        self::assertNull($projection->breachDate);
        self::assertNull($projection->balanceCentsAtBreach);
        self::assertNull($projection->causeSource);
        self::assertNull($projection->causeSourceId);
        self::assertNull($projection->causeAmountCents);
    }

    private function calculator(): ThresholdBreachProjectionCalculator
    {
        $calculator = static::getContainer()->get(ThresholdBreachProjectionCalculator::class);
        \assert($calculator instanceof ThresholdBreachProjectionCalculator);

        return $calculator;
    }

    private function etablissementA(): Etablissement
    {
        $etablissement = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etablissement);

        return $etablissement;
    }

    /** Facture fournisseur créée + validée via l'API, échéance repositionnée directement en base (§ pattern `PaymentScheduleCalculatorFactureTest`). */
    private function approuverFactureFournisseur(
        Client $client,
        array $entete,
        \DateTimeImmutable $dueDate,
        string $unitPriceExclTax,
        string $quantity,
        string $numero,
    ): SupplierInvoice {
        $idFournisseur = $this->idFournisseur(FinanceFixtures::FOURNISSEUR_ACTIF);
        $facture = $this->creerFactureAvecLigne($client, $entete, $idFournisseur, [
            'unitPriceExclTax' => $unitPriceExclTax,
            'quantity' => $quantity,
        ], $numero);
        $client->request('POST', '/api/finance/supplier-invoices/' . $facture['id'] . '/approve', $entete);

        $entite = $this->em()->getRepository(SupplierInvoice::class)->find($facture['id']);
        self::assertInstanceOf(SupplierInvoice::class, $entite);
        $entite->setDueDate($dueDate);
        $this->em()->flush();

        return $entite;
    }

    /** Facture client en attente de paiement (entrée d'échéancier) — persistée directement, même patron que `PaymentScheduleCalculatorFactureTest`. */
    private function creerFactureClient(Etablissement $etablissement, \DateTimeImmutable $dueDate, string $totalTTC, string $numero): Facture
    {
        $em = $this->em();
        $admin = $em->getRepository(Utilisateur::class)->findOneBy(['email' => SocleFixtures::ADMIN_EMAIL]);
        self::assertInstanceOf(Utilisateur::class, $admin);

        $destinataire = new DestinataireFacturation();
        $destinataire->setType(TypeDestinataire::Particulier);
        $destinataire->setNom('Client Test Cause Nulle');
        $em->persist($destinataire);

        $facture = new Facture();
        $facture->setEtablissement($etablissement);
        $facture->setProfilExploitant($this->profilExploitant());
        $facture->setDestinataire($destinataire);
        $facture->setNature(NatureFacture::Facture);
        $facture->setOrigine(OrigineFacture::VenteATerme);
        $facture->setNumero($numero);
        $facture->setStatut(StatutFacture::EnAttentePaiement);
        $facture->setDateEcheance($dueDate);
        $facture->setTotalHT($totalTTC);
        $facture->setTotalTVA('0.00');
        $facture->setTotalTTC($totalTTC);
        $facture->setCreePar($admin);
        $em->persist($facture);
        $em->flush();

        return $facture;
    }
}
