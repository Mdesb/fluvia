<?php

declare(strict_types=1);

namespace App\Tests\Ocr\Unit;

use App\Tests\SchemaDuHarnais;
use App\Ocr\Adapter\ManualExtractorAdapter;
use App\Ocr\Dto\DocumentToExtract;
use App\Ocr\Entity\ExtractionAttempt;
use App\Ocr\Enum\DocumentKind;
use App\Ocr\Service\AnthropicDocumentExtractorAdapterFactory;
use App\Ocr\Service\ChiffreurApiKeyOcr;
use App\Ocr\Service\OcrProviderConfigResolver;
use App\Ocr\Service\TenantAwareDocumentExtractor;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * RG-OCR-04 : chaque appel `extract()` (quel que soit le statut) produit **exactement 1**
 * `ExtractionAttempt`, jamais le contenu binaire du document source (seule une référence/aucune
 * donnée du fichier n'est en base).
 */
final class ExtractionAttemptTraceabilityTest extends KernelTestCase
{
    private const CONTENU_BINAIRE_SECRET = 'CECI-EST-LE-CONTENU-BINAIRE-DU-DOCUMENT-SOURCE-NE-DOIT-JAMAIS-ETRE-STOCKE';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine')->getManager();

        // Le schéma est construit UNE FOIS par processus, puis vidé entre les tests.
        // Le faire détruire et reconstruire par chaque `setUp()` coûtait ~10 s par test.
        SchemaDuHarnais::reinitialiser($this->em);
    }

    public function testChaqueAppelProduitExactementUneTentativeSansLeContenuBinaire(): void
    {
        $etablissement = $this->creerEtablissement();
        $extracteur = $this->creerExtracteur($etablissement);

        $extracteur->extract(new DocumentToExtract(self::CONTENU_BINAIRE_SECRET, 'image/png'), DocumentKind::SupplierInvoice);
        $extracteur->extract(new DocumentToExtract(self::CONTENU_BINAIRE_SECRET, 'image/png'), DocumentKind::ExpenseReceipt);

        $tentatives = $this->em->getRepository(ExtractionAttempt::class)->findAll();
        self::assertCount(2, $tentatives, 'Un ExtractionAttempt par appel extract(), jamais plus jamais moins.');

        foreach ($tentatives as $tentative) {
            $snapshot = json_encode($tentative->getExtractedFields());
            self::assertIsString($snapshot);
            self::assertStringNotContainsString(self::CONTENU_BINAIRE_SECRET, $snapshot, 'Le contenu binaire du document source ne doit jamais être journalisé.');
        }
    }

    private function creerExtracteur(Etablissement $etablissement): TenantAwareDocumentExtractor
    {
        $chiffreur = new ChiffreurApiKeyOcr('cle-test-traceability');

        $requestStack = new RequestStack();
        $request = new Request();
        $request->headers->set(ContexteEtablissement::HEADER, (string) $etablissement->getId());
        $requestStack->push($request);
        $contexte = new ContexteEtablissement($requestStack, $this->em);

        return new TenantAwareDocumentExtractor(
            $contexte,
            new OcrProviderConfigResolver($this->em),
            new ManualExtractorAdapter(),
            new AnthropicDocumentExtractorAdapterFactory(new MockHttpClient(), $chiffreur),
            $this->em,
            static::getContainer()->get(Security::class),
            new NullLogger(),
        );
    }

    private function creerEtablissement(): Etablissement
    {
        $groupe = (new Groupe())->setNom('Groupe Test Traceability');
        $this->em->persist($groupe);
        $region = (new Region())->setNom('Région Test Traceability')->setGroupe($groupe);
        $this->em->persist($region);
        $etablissement = (new Etablissement())->setNom('Etablissement Test Traceability')->setRegion($region)->setActif(true);
        $this->em->persist($etablissement);
        $this->em->flush();

        return $etablissement;
    }
}
