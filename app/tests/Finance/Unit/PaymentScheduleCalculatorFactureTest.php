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
use App\Finance\Treasury\Service\PaymentScheduleCalculator;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Tests\Finance\TreasuryApiTestCase;

/**
 * §0.8 du plan — solde de facture client calculé depuis `Facture::getSoldeDu()` (propre à ce lot,
 * `App\Facturation` n'expose pas de solde déjà calculé côté API mais la méthode existe sur l'entité,
 * réutilisée telle quelle, aucun second calcul).
 */
final class PaymentScheduleCalculatorFactureTest extends TreasuryApiTestCase
{
    public function testSoldeFactureClientCalculeDepuisReglementFacture(): void
    {
        $em = $this->em();
        $etablissement = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etablissement);
        $admin = $em->getRepository(Utilisateur::class)->findOneBy(['email' => SocleFixtures::ADMIN_EMAIL]);
        self::assertInstanceOf(Utilisateur::class, $admin);

        $destinataire = new DestinataireFacturation();
        $destinataire->setType(TypeDestinataire::Particulier);
        $destinataire->setNom('Client Test');
        $em->persist($destinataire);

        $facture = new Facture();
        $facture->setEtablissement($etablissement);
        $facture->setProfilExploitant($this->profilExploitant());
        $facture->setDestinataire($destinataire);
        $facture->setNature(NatureFacture::Facture);
        $facture->setOrigine(OrigineFacture::VenteATerme);
        $facture->setNumero('FA-TRE-TEST-0001');
        $facture->setStatut(StatutFacture::EnAttentePaiement);
        $facture->setDateEcheance(new \DateTimeImmutable('2026-09-15'));
        $facture->setTotalHT('100.00');
        $facture->setTotalTVA('20.00');
        $facture->setTotalTTC('120.00');
        $facture->setCreePar($admin);
        $em->persist($facture);
        $em->flush();

        $calculator = static::getContainer()->get(PaymentScheduleCalculator::class);
        \assert($calculator instanceof PaymentScheduleCalculator);

        $resultat = $calculator->echeancier([$etablissement->getId()->toBinary()], new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-30'));

        $entree = null;
        foreach ($resultat['entries'] as $candidate) {
            if ($candidate['sourceId'] === (string) $facture->getId()) {
                $entree = $candidate;
            }
        }
        self::assertNotNull($entree, 'La facture en attente de paiement doit apparaître dans entries[].');
        self::assertSame(12000, $entree['amountCents'], 'Solde = totalTTC - Σreglements = 120,00 € (aucun règlement).');
        self::assertSame('invoice', $entree['source']);
    }
}
