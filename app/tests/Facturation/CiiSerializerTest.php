<?php

declare(strict_types=1);

namespace App\Tests\Facturation;

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Compta\Enum\VatCategory;
use App\Facturation\Einvoicing\CiiSerializer;
use App\Facturation\Einvoicing\InvoiceNotEmittableException;
use App\Facturation\Einvoicing\InvoiceReadiness;
use App\Facturation\Entity\DestinataireFacturation;
use App\Facturation\Entity\Facture;
use App\Facturation\Entity\LigneFacture;
use App\Facturation\Enum\StatutFacture;
use App\Facturation\Enum\UnitCode;
use PHPUnit\Framework\TestCase;

/**
 * LE FICHIER EUROPÉEN, ET SURTOUT SON REFUS.
 *
 * ⚠ LE TEST QUI COMPTE LE PLUS ICI EST CELUI DU REFUS. Un XML bien formé dont `<ram:Name/>` est vide
 * passe le schéma et se fait refuser des semaines plus tard par la plateforme destinataire — et
 * entre-temps l'exploitant croit avoir facturé. Un fichier absent se voit tout de suite ; un fichier
 * vide se voit dans un mois.
 *
 * ⚠ ET AUCUN DE CES TESTS NE PROUVE LA CONFORMITÉ. Ils prouvent que les 28 termes obligatoires sont
 * présents dans la structure attendue. EN 16931 compte une centaine de règles métier que seul le
 * schematron officiel vérifie, plus celles de chaque CIUS national. Le dire est ce qui empêche « le
 * XML sort » de devenir « on est conforme ».
 */
final class CiiSerializerTest extends TestCase
{
    private CiiSerializer $serialiseur;

    protected function setUp(): void
    {
        $this->serialiseur = new CiiSerializer(new InvoiceReadiness());
    }

    // ── Le refus ────────────────────────────────────────────────────────────────────────────────

    public function testElleRefuseUneFactureIncompleteAuLieuDEmettreUnFichierVide(): void
    {
        $facture = $this->factureComplete();
        $facture->setProfilExploitant(null);

        $this->expectException(InvoiceNotEmittableException::class);
        $this->serialiseur->serialize($facture);
    }

    /**
     * ⚠ LE REFUS NOMME CHAQUE TERME ET SON EMPLACEMENT.
     *
     * « Facture incomplète » enverrait chercher partout. Sans cette assertion, le test précédent
     * passerait aussi avec un message vide — il ne prouverait que l'existence d'une exception.
     */
    public function testLeRefusNommeLesTermesManquants(): void
    {
        $facture = $this->factureComplete();
        $facture->setProfilExploitant(null);

        try {
            $this->serialiseur->serialize($facture);
            self::fail('la serialisation aurait du etre refusee');
        } catch (InvoiceNotEmittableException $e) {
            self::assertNotSame([], $e->manques, 'le refus doit transporter les termes manquants');
            self::assertStringContainsString('BT-27', $e->getMessage(), 'le nom du vendeur doit etre nomme');
        }
    }

    // ── L'émission ──────────────────────────────────────────────────────────────────────────────

    public function testElleProduitUnXmlBienForme(): void
    {
        $xml = $this->serialiseur->serialize($this->factureComplete());

        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($xml), 'le fichier doit etre un XML bien forme');
        self::assertSame('CrossIndustryInvoice', $dom->documentElement?->localName);
    }

    /**
     * Les termes obligatoires, à l'endroit où la syntaxe les attend.
     *
     * On interroge par XPath plutôt que par `assertStringContainsString` : une chaîne présente
     * n'importe où dans le fichier ne prouve pas qu'elle est au bon endroit, et c'est l'endroit que
     * le validateur regarde.
     */
    public function testLesTermesObligatoiresSontALeurPlace(): void
    {
        $xpath = $this->xpath($this->serialiseur->serialize($this->factureComplete()));

        $attendus = [
            'BT-1  numero' => ['//rsm:ExchangedDocument/ram:ID', 'FA-2026-0007'],
            'BT-3  type' => ['//rsm:ExchangedDocument/ram:TypeCode', '380'],
            'BT-2  date' => ['//udt:DateTimeString', '20260831'],
            'BT-27 vendeur' => ['//ram:SellerTradeParty/ram:Name', 'Regie des Sports'],
            'BT-30 SIREN' => ['//ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:ID', '130025265'],
            'BT-31 TVA' => ['//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID', 'FR12130025265'],
            'BT-44 acheteur' => ['//ram:BuyerTradeParty/ram:Name', 'Commune de Test'],
            'BT-5  devise' => ['//ram:InvoiceCurrencyCode', 'EUR'],
            'BT-130 unite' => ['//ram:BilledQuantity/@unitCode', 'HUR'],
            'BT-151 categorie' => ['//ram:ApplicableTradeTax/ram:CategoryCode', 'S'],
            'BT-152 taux' => ['//ram:ApplicableTradeTax/ram:RateApplicablePercent', '20.00'],
        ];

        foreach ($attendus as $quoi => [$chemin, $valeur]) {
            $noeuds = $xpath->query($chemin);
            self::assertNotFalse($noeuds, $quoi . ' : chemin XPath invalide');
            self::assertGreaterThan(0, $noeuds->length, $quoi . ' : absent de ' . $chemin);
            self::assertSame($valeur, trim((string) $noeuds->item(0)?->nodeValue), $quoi);
        }
    }

    /**
     * ⚠ SEUL `TaxTotalAmount` PORTE `currencyID`, ET C'EST LA SYNTAXE QUI L'EXIGE.
     *
     * En ajouter partout « pour être sûr » ferait refuser le fichier. Ce test garde la règle dans
     * les deux sens : présent là où il le faut, absent partout ailleurs.
     */
    public function testSeulLeTotalDeTvaPorteLaDevise(): void
    {
        $xpath = $this->xpath($this->serialiseur->serialize($this->factureComplete()));

        $avec = $xpath->query('//ram:TaxTotalAmount/@currencyID');
        self::assertNotFalse($avec);
        self::assertSame(1, $avec->length, 'TaxTotalAmount doit porter currencyID');

        foreach (['LineTotalAmount', 'TaxBasisTotalAmount', 'GrandTotalAmount', 'DuePayableAmount'] as $nom) {
            $sans = $xpath->query(sprintf('//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:%s/@currencyID', $nom));
            self::assertNotFalse($sans);
            self::assertSame(0, $sans->length, $nom . ' ne doit PAS porter currencyID');
        }
    }

    /**
     * ⚠ « DUPONT & FILS » N'EST PAS UN CAS TORDU, C'EST UN NOM D'ENTREPRISE.
     *
     * Concaténer une valeur dans le troisième argument de `createElementNS` ne l'échappe pas : une
     * esperluette produirait un XML mal formé, et le fichier serait rejeté sans que rien dans le
     * produit ne l'ait signalé.
     */
    public function testUneEsperluetteDansLeNomNeCassePasLeFichier(): void
    {
        $facture = $this->factureComplete();
        $facture->getProfilExploitant()?->setRaisonSociale('Dupont & Fils <SARL>');

        $xml = $this->serialiseur->serialize($facture);

        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($xml), 'une esperluette ne doit pas casser le fichier');

        $xpath = $this->xpath($xml);
        $noeuds = $xpath->query('//ram:SellerTradeParty/ram:Name');
        self::assertNotFalse($noeuds);
        self::assertSame('Dupont & Fils <SARL>', $noeuds->item(0)?->nodeValue);
    }

    // ---------------------------------------------------------------- montage

    private function xpath(string $xml): \DOMXPath
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
        $xpath->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
        $xpath->registerNamespace('udt', 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100');

        return $xpath;
    }

    /** Une facture qui porte les 28 termes obligatoires — celle qu'on doit pouvoir emettre. */
    private function factureComplete(): Facture
    {
        $profil = (new ProfilExploitant())
            ->setRaisonSociale('Regie des Sports')
            ->setSiren('130025265')
            ->setTvaIntracommunautaire('FR12130025265')
            ->setAdresse(['rue' => '2 rue du Port', 'cp' => '34200', 'ville' => 'Sete', 'pays' => 'FR']);

        $destinataire = (new DestinataireFacturation())
            ->setRaisonSociale('Commune de Test')
            ->setAdresse(['rue' => '1 rue de la Mairie', 'cp' => '75001', 'ville' => 'Paris', 'pays' => 'FR']);

        $taux = (new TauxTva())
            ->setLibelle('Taux normal 20 %')
            ->setTaux('20.00')
            ->setVatCategory(VatCategory::Standard);

        $facture = (new Facture())
            ->setStatut(StatutFacture::Emise)
            ->setNumero('FA-2026-0007')
            ->setDateEmission(new \DateTimeImmutable('2026-08-31'))
            ->setProfilExploitant($profil)
            ->setDestinataire($destinataire);

        $ligne = (new LigneFacture())
            ->setDesignation('Location de creneau')
            ->setQuantite(3)
            ->setUnitCode(UnitCode::Hour)
            ->setPrixUnitaireHT('25.00')
            ->setTauxTva($taux);
        $ligne->recalculer();

        $facture->addLigne($ligne);
        $facture->recalculerTotaux();

        return $facture;
    }
}
