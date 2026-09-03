<?php

declare(strict_types=1);

namespace App\Tests\Facturation;

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Compta\Enum\VatCategory;
use App\Facturation\Einvoicing\CiiSerializer;
use App\Facturation\Einvoicing\FacturXAssembler;
use App\Facturation\Einvoicing\InvoiceNotEmittableException;
use App\Facturation\Einvoicing\InvoiceReadiness;
use App\Facturation\Entity\DestinataireFacturation;
use App\Facturation\Entity\Facture;
use App\Facturation\Entity\LigneFacture;
use App\Facturation\Enum\StatutFacture;
use App\Facturation\Enum\UnitCode;
use Dompdf\Dompdf;
use Dompdf\Options;
use PHPUnit\Framework\TestCase;

/**
 * FACTUR-X — CE QUE CES TESTS PROUVENT, ET CE QU'ILS NE PROUVENT PAS.
 *
 * ⚠ ILS PROUVENT LA STRUCTURE, PAS LA CONFORMITÉ. Les trois conditions de Factur-X sont vérifiées :
 * le PDF se déclare PDF/A-3, le fichier embarqué s'appelle `factur-x.xml`, et il est relié au
 * document en `Alternative`. Aucun validateur ne tourne ici — ni veraPDF pour le PDF/A-3, ni celui
 * de la FNFE pour le Factur-X, ni le schematron d'EN 16931 pour le XML.
 *
 * « La structure attendue est présente » et « un validateur l'accepte » se ressemblent et ne disent
 * pas la même chose. La seconde demande un outil qu'on n'a pas.
 */
final class FacturXAssemblerTest extends TestCase
{
    private FacturXAssembler $assembleur;

    protected function setUp(): void
    {
        $this->assembleur = new FacturXAssembler(new CiiSerializer(new InvoiceReadiness()));
    }

    /**
     * ⚠ LE REFUS AVANT LE RENDU, ET L'ORDRE EST LE POINT.
     *
     * Si le PDF était fabriqué d'abord, une facture incomplète coûterait un rendu complet pour rien
     * — et pire, un débordement du rendu masquerait le vrai motif du refus.
     */
    public function testElleRefuseAvantMemeDeFabriquerLePdf(): void
    {
        $facture = $this->factureComplete();
        $facture->setProfilExploitant(null);

        $this->expectException(InvoiceNotEmittableException::class);
        $this->assembleur->assemble('<p>peu importe</p>', $facture);
    }

    /**
     * ⚠ CE TEST PORTE SUR LE CABLAGE, PAS SUR LE MECANISME.
     *
     * `PoliceDeclareeTest` prouve que declarer la police resout la pollution du cache statique de
     * `Dompdf\FontMetrics::getFont()`. Il ne prouve pas que CET assembleur le fait : on pourrait
     * retirer l'appel a `PoliceDeclaree::dans()` de `FacturXAssembler` sans qu'aucun de ses tests
     * ne tombe.
     *
     * Celui-ci passe par l'assembleur reel, et se pollue lui-meme — il ne depend donc d'aucun
     * ordre. C'est ce qui manquait : le defaut n'etait visible que sur les 2233 tests de la suite
     * complete, `Boutique` precedant `Facturation` dans l'ordre alphabetique, et invisible sur les
     * 99 de ce module.
     */
    public function testElleResisteAUnDompdfAnterieurSansPoliceParDefaut(): void
    {
        // Exactement ce que fait `GenerateurPdfBillet` avant correction : aucun `setDefaultFont`,
        // donc `serif` -> Times, mis en cache sous la cle partagee `0`.
        $pollueur = new Dompdf(new Options());
        $pollueur->loadHtml('<p>Un billet, rendu avant nous.</p>', 'UTF-8');
        $pollueur->render();
        $pollueur->output();

        $pdf = $this->assembleur->assemble($this->html(), $this->factureComplete());
        self::assertStringStartsWith(
            '%PDF-',
            $pdf,
            'Un PDF rendu ailleurs dans le processus a impose sa police a Factur-X, et PDF/A l a refusee.',
        );
    }

    public function testElleProduitUnPdf(): void
    {
        $pdf = $this->assembleur->assemble($this->html(), $this->factureComplete());

        self::assertStringStartsWith('%PDF-', $pdf, 'la sortie doit etre un PDF');
        self::assertGreaterThan(1000, \strlen($pdf), 'un PDF de facture fait plus de mille octets');
    }

    /**
     * Les trois conditions de Factur-X, chacune vérifiée séparément.
     *
     * ⚠ ON NE SE CONTENTE PAS DE « le PDF contient le mot factur-x ». Un PDF qui porterait ce nom
     * sans se déclarer PDF/A-3, ou sans la relation `Alternative`, serait refusé — et la seule
     * assertion du nom ne l'aurait pas vu.
     */
    public function testLesTroisConditionsDeFacturXSontPresentes(): void
    {
        $pdf = $this->assembleur->assemble($this->html(), $this->factureComplete());

        // ⚠ ON N'ASSERTE PAS `pdfaid` DIRECTEMENT, ET C'EST UNE MESURE, PAS UN RENONCEMENT.
        //
        // Sonde du 02/09 sur un PDF produit : `pdfaid` apparait ZERO fois en clair, `/Metadata` deux
        // fois, `FlateDecode` cinq fois. Le XMP est donc ecrit dans un flux COMPRESSE. On verifie la
        // presence du flux ; son contenu demanderait de le decompresser, ce qui ferait de ce test un
        // lecteur de PDF au lieu d'un test de notre assemblage.
        //
        // ⚠ CETTE COMPRESSION NE CASSE RIEN, ET JE M'ETAIS INQUIETE A TORT. veraPDF rend
        // « PASS ... 3b » sur un fichier produit par l'assembleur (02/09), et « FAIL » sur un PDF
        // ordinaire — il sait donc refuser. J'avais infere de memoire que la norme interdisait un
        // XMP compresse ; l'observation etait juste, l'inference non. Rejouable :
        // `infra/valider-facturx.sh`.
        self::assertStringContainsString('/Metadata', $pdf, 'le PDF doit porter un flux XMP');
        self::assertStringContainsString('factur-x.xml', $pdf, 'le fichier embarque doit porter le nom impose');
        self::assertStringContainsString('/AFRelationship', $pdf, 'le fichier doit etre relie au document');
        self::assertStringContainsString('Alternative', $pdf, 'la relation doit etre Alternative');
        self::assertStringContainsString('/OutputIntents', $pdf, 'PDF/A exige une intention de sortie');
    }

    /**
     * Le témoin qui prouve que le test précédent mesure notre travail.
     *
     * ⚠ Sans lui, `testLesTroisConditions…` passerait aussi si Dompdf posait ces marqueurs de
     * lui-même sur n'importe quel PDF — il ne prouverait alors rien de l'assembleur. Un rendu nu,
     * sans PDF/A ni pièce jointe, ne doit porter aucune des trois marques.
     */
    public function testUnPdfOrdinaireNePorteAucuneDesTroisMarques(): void
    {
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($this->html(), 'UTF-8');
        $dompdf->render();
        $nu = (string) $dompdf->output();

        self::assertStringStartsWith('%PDF-', $nu);
        self::assertStringNotContainsString('factur-x.xml', $nu);
        self::assertStringNotContainsString('/AFRelationship', $nu);
        self::assertStringNotContainsString('/OutputIntents', $nu);
    }

    /**
     * ⚠ LES DEUX BLOCS XMP, ET L'UN SANS L'AUTRE CASSE TOUT. MESURE, PAS DEDUIT.
     *
     * Le 02/09, le validateur Factur-X rendait HUIT erreurs sur les metadonnees XMP. J'ai pose le
     * bloc `fx:` qui les nomme — et veraPDF est passe de `PASS` a `FAIL` :
     *
     *     « All properties specified in XMP form shall use either the predefined schemas
     *       […] or […] be described in an extension schema »
     *
     * PDF/A interdit une propriete XMP d'un espace de noms inconnu si rien ne la DECRIT. Il faut donc
     * les deux : `fx:` qui dit ce que le fichier embarque est, et `pdfaExtension` qui declare ce
     * qu'est `fx:`.
     *
     * ⚠ DEUX VALIDATEURS QUI NE POSENT PAS LA MEME QUESTION, ET UN SEUL DES DEUX AURAIT LAISSE
     * PASSER. C'est la raison d'etre des trois couches d'`infra/valider-facturx.sh`.
     */
    public function testLesDeuxBlocsXmpSontPresents(): void
    {
        // ⚠ ON LIT LE BLOC CONSTRUIT, PAS LES OCTETS DU PDF. Le XMP finit dans un flux COMPRESSE :
        // chercher `fx:DocumentType` dans le fichier fini echoue meme quand il y est. L'INJECTION
        // est prouvee ailleurs, par un vrai validateur — `infra/valider-facturx.sh`.
        $xmp = $this->assembleur->xmpFacturX();

        self::assertStringContainsString('fx:DocumentType', $xmp, 'le bloc fx: dit ce que le fichier embarque est');
        self::assertStringContainsString('fx:DocumentFileName', $xmp);
        self::assertStringContainsString('fx:ConformanceLevel', $xmp);

        self::assertStringContainsString(
            'pdfaExtension:schemas',
            $xmp,
            'sans le schema d extension, PDF/A refuse le bloc fx: — mesure le 02/09',
        );
        self::assertStringContainsString('Factur-X PDFA Extension Schema', $xmp);
    }

    /**
     * Le nom declare dans le XMP doit etre celui reellement embarque.
     *
     * ⚠ Une plateforme lit `fx:DocumentFileName` pour savoir QUOI extraire du PDF. Les deux valeurs
     * viennent de la meme constante ; ce test garde qu'elles ne divergent pas le jour ou quelqu'un
     * ecrira l'une des deux en dur.
     */
    public function testLeNomDeclareEstCeluiEmbarque(): void
    {
        self::assertStringContainsString(
            '<fx:DocumentFileName>' . FacturXAssembler::NOM_EMBARQUE . '</fx:DocumentFileName>',
            $this->assembleur->xmpFacturX(),
        );
    }

    // ---------------------------------------------------------------- montage

    private function html(): string
    {
        return '<html><body><h1>Facture FA-2026-0007</h1><p>Location de creneau</p></body></html>';
    }

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
