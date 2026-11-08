<?php

declare(strict_types=1);

namespace App\Tests\Facturation;

use App\Caisse\Entity\PointDeVente;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\RegieRecettes;
use App\Dms\DocumentStore;
use App\Dms\Dto\StoreDocumentRequest;
use App\Dms\Dto\StoredDocument;
use App\Dms\Dto\StoredDocumentVersion;
use App\Facturation\Einvoicing\InvoiceHtmlRenderer;
use App\Facturation\Einvoicing\InvoiceIssuerSnapshot;
use App\Facturation\Entity\DestinataireFacturation;
use App\Facturation\Entity\Facture;
use App\Facturation\Enum\StatutFacture;
use App\Vente\Entity\Vente;
use Dompdf\Dompdf;
use Dompdf\Options;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * L'EN-TÊTE ÉMETTEUR DE LA FACTURE PDF (#345).
 *
 * Avant : le gabarit n'imprimait que numéro, date et destinataire — rien ne disait qui facturait.
 * Témoin : une facture sans profil n'imprime aucun en-tête (le test ne lit donc pas un texte que le
 * gabarit poserait en dur), et un guichet sans régie n'imprime aucune régie.
 */
final class InvoiceIssuerHeaderTest extends TestCase
{
    /** Un PNG 1×1 réel : Dompdf ne le dessine PAS sans GD, absent de l'image de production. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /**
     * Un JPEG 8×8 gris, assemblé segment par segment (aucune bibliothèque d'image n'est disponible
     * pour en produire un) : SOI, APP0 JFIF, DQT, SOF0, deux DHT d'un symbole, SOS, un octet, EOI.
     */
    private static function jpeg(): string
    {
        // Une table de Huffman d'un seul symbole (0x00), codé « 0 » sur un bit.
        $huffman = static fn (string $classe): string => 'ffc40014' . $classe . '01' . str_repeat('00', 15) . '00';

        return (string) hex2bin(
            'ffd8'
            . 'ffe000104a46494600010100000100010000'          // APP0 JFIF
            . 'ffdb004300' . str_repeat('01', 64)            // DQT : quantification unité
            . 'ffc0000b080008000801011100'                   // SOF0 : 8×8, une composante
            . $huffman('00') . $huffman('10')                // DHT DC puis AC
            . 'ffda000801010000' . '3f00'                    // SOS
            . '3f'                                           // DC nul (0) puis EOB (0), bourrage à 1
            . 'ffd9',
        );
    }

    public function testLEnTeteNommeLEmetteurSonAdresseSonSiretSaTvaEtLaRegie(): void
    {
        $html = $this->renderer()->render($this->facture(avecLogo: false, avecRegie: true));

        self::assertStringContainsString('Communauté d’Agglomération Test', $html);
        self::assertStringContainsString('1 place du Général de Gaulle', $html);
        self::assertStringContainsString('59600 Maubeuge', $html);
        self::assertStringContainsString('SIRET : 20004049500017', $html);
        self::assertStringContainsString('N° TVA intracommunautaire : FR44200040495', $html);
        self::assertStringContainsString('Régie de recettes émettrice : Régie piscine du Val', $html);
        self::assertStringContainsString('Acte constitutif : Arrêté n° 2026-118', $html);
        self::assertStringNotContainsString('<img', $html, 'Sans logo, aucune image.');
    }

    public function testLeLogoJpegEstIncrusteEtDessineDansLePdf(): void
    {
        $jpeg = self::jpeg();
        self::assertSame('image/jpeg', getimagesizefromstring($jpeg)['mime'] ?? null, 'Le témoin doit être un vrai JPEG.');

        $html = $this->renderer($jpeg, 'image/jpeg')->render($this->facture(avecLogo: true, avecRegie: false));
        self::assertStringContainsString('src="data:image/jpeg;base64,' . base64_encode($jpeg) . '"', $html);

        $pdf = $this->pdf($html);
        self::assertStringContainsString('/Subtype /Image', $pdf, 'Le logo doit être dessiné, pas seulement cité.');
        self::assertStringContainsString('/DCTDecode', $pdf);
    }

    /**
     * Témoin du filtre : un PNG enregistré avant la restriction ne doit pas empêcher la facture de sortir.
     * Sans le filtre, Dompdf lève « The PHP GD extension is required » et AUCUNE facture ne se rend.
     */
    public function testUnLogoPngEstEcarteEtLaFactureSortQuandMeme(): void
    {
        $html = $this->renderer((string) base64_decode(self::PNG, true), 'image/png')
            ->render($this->facture(avecLogo: true, avecRegie: false));
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('SIRET : 20004049500017', $html);
        self::assertStringStartsWith('%PDF-', $this->pdf($html));
    }

    /**
     * L'ÉMETTEUR FIGÉ (#345) : la régie et le profil changent APRÈS l'émission, la réimpression
     * montre ceux d'origine. Témoin : la même facture SANS instantané (brouillon, ou émise avant
     * l'instantané) montre les nouveaux — le rendu ne pose donc pas un texte constant.
     */
    public function testUneFactureEmiseGardeSaRegieEtSonEmetteurDOrigine(): void
    {
        $facture = $this->facture(avecLogo: false, avecRegie: true);
        $facture->setEmetteurFige($this->instantane()->capture($facture));

        $facture->getVenteOrigine()?->getPointDeVente()?->getRegie()?->setLibelle('Régie renommée')->setActeNomination('Arrêté n° 2027-001');
        $facture->getProfilExploitant()?->setRaisonSociale('Nouvelle dénomination')->setSiret('99988877700011');

        $html = $this->renderer()->render($facture);
        self::assertStringContainsString('Régie de recettes émettrice : Régie piscine du Val', $html);
        self::assertStringContainsString('Acte constitutif : Arrêté n° 2026-118', $html);
        self::assertStringContainsString('Communauté d’Agglomération Test', $html);
        self::assertStringContainsString('SIRET : 20004049500017', $html);
        self::assertStringNotContainsString('Régie renommée', $html);
        self::assertStringNotContainsString('Nouvelle dénomination', $html);

        $temoin = $this->renderer()->render($facture->setEmetteurFige(null));
        self::assertStringContainsString('Régie renommée', $temoin);
        self::assertStringContainsString('Nouvelle dénomination', $temoin);
    }

    /** Le logo figé est relu dans la VERSION imprimée à l'émission, pas dans la version courante. */
    public function testLeLogoFigeEstReluDansSaVersionDOrigine(): void
    {
        $store = new class implements DocumentStore {
            /** @var list<string|null> */
            public array $versions = [];

            public function store(StoreDocumentRequest $request): StoredDocument
            {
                throw new \LogicException('Pas d’écriture dans ce test.');
            }

            public function currentVersion(Uuid $documentId): ?StoredDocumentVersion
            {
                return new StoredDocumentVersion(Uuid::fromString('0190f3a0-0000-7000-8000-000000000001'), 1, 'x', 1, 'image/jpeg');
            }

            public function readContent(Uuid $documentId, ?Uuid $versionId = null)
            {
                $this->versions[] = $versionId?->toRfc4122();
                $flux = fopen('php://memory', 'r+b');
                \assert(\is_resource($flux));
                fwrite($flux, 'octets');
                rewind($flux);

                return $flux;
            }
        };

        $facture = $this->facture(avecLogo: true, avecRegie: false);
        $facture->setEmetteurFige((new InvoiceIssuerSnapshot($store))->capture($facture));
        $twig = new Environment(new FilesystemLoader(\dirname(__DIR__, 2) . '/templates'));
        (new InvoiceHtmlRenderer($twig, $store))->render($facture);

        self::assertSame(['0190f3a0-0000-7000-8000-000000000001'], $store->versions, 'La version figée est nommée à la lecture.');
    }

    private function instantane(): InvoiceIssuerSnapshot
    {
        return new InvoiceIssuerSnapshot(new class implements DocumentStore {
            public function store(StoreDocumentRequest $request): StoredDocument
            {
                throw new \LogicException('Pas d’écriture dans ce test.');
            }

            public function currentVersion(Uuid $documentId): ?StoredDocumentVersion
            {
                return null;
            }

            public function readContent(Uuid $documentId, ?Uuid $versionId = null)
            {
                throw new \LogicException('Pas de lecture dans ce test.');
            }
        });
    }

    private function pdf(string $html): string
    {
        // Les options de `GenerateurPdfFacture` : sans accès distant, sous chroot.
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setChroot(\sys_get_temp_dir());
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    public function testTemoinSansProfilNiRegieAucunEnTete(): void
    {
        $facture = $this->facture(avecLogo: false, avecRegie: false)->setProfilExploitant(null);
        $html = $this->renderer()->render($facture);

        self::assertStringContainsString('Facture FA-2026-0042', $html, 'Le reste du gabarit est rendu.');
        self::assertStringNotContainsString('SIRET', $html);
        self::assertStringNotContainsString('Agglomération', $html);
        self::assertStringNotContainsString('Régie de recettes', $html);
    }

    public function testHorsDeFranceLeNumeroNeSAppellePasSiren(): void
    {
        $facture = $this->facture(avecLogo: false, avecRegie: false);
        $facture->getProfilExploitant()?->setSiret(null)->setAdresse(['rue' => 'Rue Haute 1', 'cp' => '1000', 'ville' => 'Bruxelles', 'pays' => 'BE']);
        $html = $this->renderer()->render($facture);

        self::assertStringNotContainsString('SIREN', $html);
        self::assertStringContainsString('Identifiant légal : 200040495', $html);
    }

    private function renderer(string $octets = '', string $mime = 'image/jpeg'): InvoiceHtmlRenderer
    {
        $twig = new Environment(new FilesystemLoader(\dirname(__DIR__, 2) . '/templates'));

        return new InvoiceHtmlRenderer($twig, new class($octets, $mime) implements DocumentStore {
            public function __construct(private readonly string $octets, private readonly string $mime)
            {
            }

            public function store(StoreDocumentRequest $request): StoredDocument
            {
                throw new \LogicException('Pas d’écriture dans ce test.');
            }

            public function currentVersion(Uuid $documentId): ?StoredDocumentVersion
            {
                return new StoredDocumentVersion(Uuid::v4(), 1, 'x', \strlen($this->octets), $this->mime);
            }

            public function readContent(Uuid $documentId, ?Uuid $versionId = null)
            {
                $flux = fopen('php://memory', 'r+b');
                \assert(\is_resource($flux));
                fwrite($flux, $this->octets);
                rewind($flux);

                return $flux;
            }
        });
    }

    private function facture(bool $avecLogo, bool $avecRegie): Facture
    {
        $profil = (new ProfilExploitant())
            ->setRaisonSociale('Communauté d’Agglomération Test')
            ->setSiren('200040495')
            ->setSiret('20004049500017')
            ->setTvaIntracommunautaire('FR44200040495')
            ->setAdresse(['rue' => '1 place du Général de Gaulle', 'cp' => '59600', 'ville' => 'Maubeuge', 'pays' => 'FR']);
        if ($avecLogo) {
            $profil->setLogoDocumentId(Uuid::v4());
        }

        $facture = (new Facture())
            ->setStatut(StatutFacture::Emise)
            ->setNumero('FA-2026-0042')
            ->setDateEmission(new \DateTimeImmutable('2026-11-05'))
            ->setProfilExploitant($profil)
            ->setDestinataire((new DestinataireFacturation())->setRaisonSociale('Club de natation'));

        $pdv = new PointDeVente();
        if ($avecRegie) {
            $pdv->setRegie((new RegieRecettes())->setLibelle('Régie piscine du Val')->setActeNomination('Arrêté n° 2026-118'));
        }
        $facture->setVenteOrigine((new Vente())->setPointDeVente($pdv));

        return $facture;
    }
}
