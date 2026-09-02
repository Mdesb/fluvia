<?php

declare(strict_types=1);

namespace App\Tests\Facturation;

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Compta\Enum\VatCategory;
use App\Facturation\Einvoicing\CiiSerializer;
use App\Facturation\Einvoicing\InvoiceNotEmittableException;
use App\Facturation\Einvoicing\InvoiceMentions;
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
     * ⚠ UN SEUL ELEMENT DU DOCUMENT PORTE `currencyID`, ET C'EST `TaxTotalAmount`.
     *
     * ── LA VERSION PRECEDENTE DE CE TEST ETAIT TROP ETROITE, ET C'EST CE QUI A LAISSE PASSER ────
     *
     * Elle verifiait l'absence de `currencyID` sur les totaux d'EN-TETE, un par un. Le serialiseur
     * en posait un sur le total de LIGNE, que ce chemin XPath ne regardait pas. Le schematron
     * officiel a refuse le fichier : « [CII-DT-031] - currencyID should not be present ».
     *
     * La regle etait juste ; sa PORTEE ne l'etait pas. On l'exprime donc universellement — « un seul
     * dans tout le document, et c'est celui-la » — au lieu d'enumerer les endroits ou on a pense a
     * regarder. Un quantificateur universel ne peut pas oublier un cas.
     */
    public function testUnSeulElementDuDocumentPorteLaDevise(): void
    {
        $xpath = $this->xpath($this->serialiseur->serialize($this->factureComplete()));

        $tous = $xpath->query('//*[@currencyID]');
        self::assertNotFalse($tous);
        self::assertSame(1, $tous->length, 'un seul element doit porter currencyID dans tout le document');
        self::assertSame('TaxTotalAmount', $tous->item(0)?->localName);
    }

    /**
     * BG-23 — LA VENTILATION DE TVA, QUI MANQUAIT ENTIEREMENT.
     *
     * Le schematron l'a reclamee trois fois : `BR-CO-18` (au moins un groupe), `BR-S-01` (une ligne
     * au taux standard exige un groupe de sa categorie) et `BR-CO-14` (le total de TVA doit egaler
     * la somme des groupes). Aucun de mes tests ne l'avait vue manquer : ils verifiaient que ce que
     * j'ecrivais etait au bon endroit, pas que rien ne manquait.
     */
    public function testLaVentilationDeTvaEstPresenteEtChiffree(): void
    {
        $xpath = $this->xpath($this->serialiseur->serialize($this->factureComplete()));

        $groupes = $xpath->query('//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax');
        self::assertNotFalse($groupes);
        self::assertSame(1, $groupes->length, 'une facture a un seul taux doit avoir un seul groupe');

        foreach ([
            'ram:CalculatedAmount' => '15.00',
            'ram:TypeCode' => 'VAT',
            'ram:BasisAmount' => '75.00',
            'ram:CategoryCode' => 'S',
            'ram:RateApplicablePercent' => '20.00',
        ] as $nom => $attendu) {
            $n = $xpath->query('//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/' . $nom);
            self::assertNotFalse($n);
            self::assertSame($attendu, trim((string) $n->item(0)?->nodeValue), $nom);
        }
    }

    /**
     * ⚠ L'ORDRE DES ENFANTS N'EST PAS LIBRE DANS CII.
     *
     * C'est une syntaxe a sequence : `CalculatedAmount`, `TypeCode`, `BasisAmount`, `CategoryCode`,
     * `RateApplicablePercent`. Les memes elements dans un autre ordre produisent un fichier refuse
     * par le SCHEMA, avant meme le schematron — et `testLaVentilationDeTvaEstPresenteEtChiffree`
     * passerait quand meme, puisqu'il interroge chaque nom separement.
     */
    public function testLOrdreDeLaVentilationSuitLaSequenceCii(): void
    {
        $xpath = $this->xpath($this->serialiseur->serialize($this->factureComplete()));

        $groupe = $xpath->query('//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax')?->item(0);
        self::assertNotNull($groupe);

        $noms = [];
        foreach ($groupe->childNodes as $enfant) {
            if ($enfant instanceof \DOMElement) {
                $noms[] = $enfant->localName;
            }
        }

        self::assertSame(
            ['CalculatedAmount', 'TypeCode', 'BasisAmount', 'CategoryCode', 'RateApplicablePercent'],
            $noms,
        );
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

    // ── BG-1 : les trois mentions du profil francais ────────────────────────────────────────────

    /**
     * ⚠ SANS FOURNISSEUR, AUCUNE NOTE — ET C'EST LE COMPORTEMENT VOULU.
     *
     * Le serialiseur marche sans acces a la base : ses tests assemblent une facture en memoire. Une
     * note vide satisferait la presence de la balise et affirmerait au client des conditions
     * blanches ; l'absence, elle, se voit dans le rapport de validation.
     */
    public function testSansFournisseurAucuneNoteNEstEcrite(): void
    {
        $xpath = $this->xpath($this->serialiseur->serialize($this->factureComplete()));

        $notes = $xpath->query('//rsm:ExchangedDocument/ram:IncludedNote');
        self::assertNotFalse($notes);
        self::assertSame(0, $notes->length);
    }

    /**
     * BR-FR-05 / BT-22 — les trois notes, chacune avec son code sujet.
     *
     * Mesure du 02/09 : sans elles, le validateur Factur-X rendait trois avertissements du profil
     * francais. Avec, il n'en rend plus aucun.
     */
    public function testLesTroisMentionsSortentAvecLeurCodeSujet(): void
    {
        $serialiseur = new CiiSerializer(new InvoiceReadiness(), $this->mentions([
            'PMT' => 'Frais de recouvrement : texte de test.',
            'PMD' => 'Penalites de retard : texte de test.',
            'AAB' => 'Escompte : texte de test.',
        ]));

        $xpath = $this->xpath($serialiseur->serialize($this->factureComplete()));

        $codes = [];
        $noeuds = $xpath->query('//rsm:ExchangedDocument/ram:IncludedNote/ram:SubjectCode');
        self::assertNotFalse($noeuds);
        foreach ($noeuds as $n) {
            $codes[] = trim((string) $n->nodeValue);
        }

        self::assertSame(['PMT', 'PMD', 'AAB'], $codes);
    }

    /**
     * ⚠ UNE MENTION VIDE N'EST PAS UNE MENTION.
     *
     * Sans ce test, `testLesTroisMentionsSortent…` passerait aussi si le serialiseur ecrivait une
     * note pour chaque cle, remplie ou non — et le client recevrait une facture affirmant des
     * conditions blanches. C'est la difference entre « la balise est la » et « quelque chose est
     * dit ».
     */
    public function testUneMentionVideNeProduitAucuneNote(): void
    {
        $serialiseur = new CiiSerializer(new InvoiceReadiness(), $this->mentions([
            'PMT' => 'Seule celle-ci est redigee.',
            'PMD' => '',
            'AAB' => null,
        ]));

        $xpath = $this->xpath($serialiseur->serialize($this->factureComplete()));

        $notes = $xpath->query('//rsm:ExchangedDocument/ram:IncludedNote');
        self::assertNotFalse($notes);
        self::assertSame(1, $notes->length, 'une chaine vide et un null ne sont pas des mentions');
    }

    /**
     * ⚠ L'ORDRE DANS LA SEQUENCE : les notes viennent APRES la date d'emission.
     *
     * CII est une syntaxe a sequence. Une note ecrite plus haut produit un fichier que le SCHEMA
     * refuse, avant meme le schematron — et les tests de contenu passeraient quand meme.
     */
    public function testLesNotesViennentApresLaDateDEmission(): void
    {
        $serialiseur = new CiiSerializer(new InvoiceReadiness(), $this->mentions(['PMT' => 'x']));
        $xpath = $this->xpath($serialiseur->serialize($this->factureComplete()));

        $doc = $xpath->query('//rsm:ExchangedDocument')?->item(0);
        self::assertNotNull($doc);

        $noms = [];
        foreach ($doc->childNodes as $enfant) {
            if ($enfant instanceof \DOMElement) {
                $noms[] = $enfant->localName;
            }
        }

        self::assertSame(['ID', 'TypeCode', 'IssueDateTime', 'IncludedNote'], $noms);
    }

    /** @param array<string, string|null> $textes */
    private function mentions(array $textes): InvoiceMentions
    {
        return new class($textes) implements InvoiceMentions {
            /** @param array<string, string|null> $textes */
            public function __construct(private readonly array $textes)
            {
            }

            public function pour(Facture $facture): array
            {
                return $this->textes;
            }
        };
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
