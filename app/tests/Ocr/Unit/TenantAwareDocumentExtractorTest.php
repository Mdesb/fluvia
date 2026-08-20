<?php

declare(strict_types=1);

namespace App\Tests\Ocr\Unit;

use App\Ocr\Adapter\ManualExtractorAdapter;
use App\Ocr\Dto\DocumentToExtract;
use App\Ocr\Entity\ExtractionAttempt;
use App\Ocr\Entity\OcrProviderConfig;
use App\Ocr\Enum\DocumentKind;
use App\Ocr\Enum\ExtractionStatus;
use App\Ocr\Enum\OcrProvider;
use App\Ocr\Service\AnthropicDocumentExtractorAdapterFactory;
use App\Ocr\Service\ChiffreurApiKeyOcr;
use App\Ocr\Service\OcrProviderConfigResolver;
use App\Ocr\Service\TenantAwareDocumentExtractor;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * `TenantAwareDocumentExtractor` (plan-ocr.md §0.2, composite qui implémente `DocumentExtractor`) :
 * CA-2 (aucune config → délègue à `manual`) ; CA-4 (delegate anthropic lève
 * `OcrProviderUnavailableException` → résultat `failed` retourné, jamais propagée) ; CA-5 (delegate
 * `success` avec `confidenceScore=0.4` et seuil `0.7` → rétrogradé `low_confidence`) ; un
 * `ExtractionAttempt` est bien journalisé dans chaque cas (RG-OCR-04).
 *
 * Intégration kernel réel (patron `App\Tests\Personnel\Unit\RecalculFenetreBadgeHandlerTest`) : le
 * seul mock est la couche transport HTTP (`MockHttpClient`), tout le reste (EntityManager,
 * ContexteEtablissement, factory Anthropic réelle) est exercé pour de vrai.
 */
final class TenantAwareDocumentExtractorTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine')->getManager();

        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $this->em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $this->em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testAucuneConfigDelegueAManualEtJournaliseUneTentative(): void
    {
        $etablissement = $this->creerEtablissement();
        $extracteur = $this->creerExtracteur($etablissement, new MockHttpClient());

        $resultat = $extracteur->extract(new DocumentToExtract('x', 'image/png'), DocumentKind::SupplierInvoice);

        self::assertSame(ExtractionStatus::Failed, $resultat->status);
        self::assertSame('manual', $resultat->provider);

        $tentatives = $this->em->getRepository(ExtractionAttempt::class)->findAll();
        self::assertCount(1, $tentatives);
        self::assertSame('manual', $tentatives[0]->getProvider());
        self::assertSame(ExtractionStatus::Failed, $tentatives[0]->getStatus());
        self::assertSame($etablissement->getId(), $tentatives[0]->getEstablishment()?->getId());
    }

    public function testDelegateAnthropicIndisponibleRetombeSurFailedJamaisPropagee(): void
    {
        $etablissement = $this->creerEtablissement();
        $chiffreur = new ChiffreurApiKeyOcr('cle-test-tenant-aware');
        $this->creerConfig($etablissement, OcrProvider::Anthropic, $chiffreur, '0.70');

        $httpClient = new MockHttpClient(new MockResponse('', ['http_code' => 500]));
        $extracteur = $this->creerExtracteur($etablissement, $httpClient, $chiffreur);

        $resultat = $extracteur->extract(new DocumentToExtract('x', 'image/png'), DocumentKind::SupplierInvoice);

        self::assertSame(ExtractionStatus::Failed, $resultat->status);

        $tentatives = $this->em->getRepository(ExtractionAttempt::class)->findAll();
        self::assertCount(1, $tentatives);
        self::assertSame(ExtractionStatus::Failed, $tentatives[0]->getStatus());
    }

    public function testConfianceSousLeSeuilRetrogradeSuccesEnLowConfidence(): void
    {
        $etablissement = $this->creerEtablissement();
        $chiffreur = new ChiffreurApiKeyOcr('cle-test-tenant-aware');
        $this->creerConfig($etablissement, OcrProvider::Anthropic, $chiffreur, '0.70');

        $corpsAnthropic = [
            'content' => [
                ['type' => 'text', 'text' => json_encode(['supplierName' => 'ACME', 'confidenceScore' => 0.4], JSON_THROW_ON_ERROR)],
            ],
        ];
        $httpClient = new MockHttpClient(new MockResponse(json_encode($corpsAnthropic, JSON_THROW_ON_ERROR), ['http_code' => 200]));
        $extracteur = $this->creerExtracteur($etablissement, $httpClient, $chiffreur);

        $resultat = $extracteur->extract(new DocumentToExtract('x', 'image/png'), DocumentKind::SupplierInvoice);

        self::assertSame(ExtractionStatus::LowConfidence, $resultat->status);
        self::assertSame(0.4, $resultat->confidenceScore);

        $tentatives = $this->em->getRepository(ExtractionAttempt::class)->findAll();
        self::assertCount(1, $tentatives);
        self::assertSame(ExtractionStatus::LowConfidence, $tentatives[0]->getStatus());
        self::assertSame(0.4, $tentatives[0]->getConfidenceScore());
    }

    public function testAucunEtablissementActifDegradeSansExceptionEtSansJournalisation(): void
    {
        $requestStack = new RequestStack();
        // Aucune requête poussée : `ContexteEtablissement::idActif()` retourne `null` (échec fermé).
        $contexte = new ContexteEtablissement($requestStack, $this->em);
        $chiffreur = new ChiffreurApiKeyOcr('cle-test-tenant-aware');

        $extracteur = new TenantAwareDocumentExtractor(
            $contexte,
            new OcrProviderConfigResolver($this->em),
            new ManualExtractorAdapter(),
            new AnthropicDocumentExtractorAdapterFactory(new MockHttpClient(), $chiffreur),
            $this->em,
            static::getContainer()->get(Security::class),
            new NullLogger(),
        );

        $resultat = $extracteur->extract(new DocumentToExtract('x', 'image/png'), DocumentKind::SupplierInvoice);

        self::assertSame(ExtractionStatus::Failed, $resultat->status);
        self::assertCount(0, $this->em->getRepository(ExtractionAttempt::class)->findAll());
    }

    private function creerExtracteur(Etablissement $etablissement, MockHttpClient $httpClient, ?ChiffreurApiKeyOcr $chiffreur = null): TenantAwareDocumentExtractor
    {
        $chiffreur ??= new ChiffreurApiKeyOcr('cle-test-tenant-aware');

        $requestStack = new RequestStack();
        $request = new Request();
        $request->headers->set(ContexteEtablissement::HEADER, (string) $etablissement->getId());
        $requestStack->push($request);
        $contexte = new ContexteEtablissement($requestStack, $this->em);

        return new TenantAwareDocumentExtractor(
            $contexte,
            new OcrProviderConfigResolver($this->em),
            new ManualExtractorAdapter(),
            new AnthropicDocumentExtractorAdapterFactory($httpClient, $chiffreur),
            $this->em,
            static::getContainer()->get(Security::class),
            new NullLogger(),
        );
    }

    private function creerConfig(Etablissement $etablissement, OcrProvider $provider, ChiffreurApiKeyOcr $chiffreur, string $seuil): OcrProviderConfig
    {
        $config = new OcrProviderConfig();
        $config->setEstablishment($etablissement)
            ->setProvider($provider)
            ->setApiKeyEncrypted($chiffreur->chiffrer('sk-ant-demo-key'))
            ->setConfidenceThreshold($seuil);
        $this->em->persist($config);
        $this->em->flush();

        return $config;
    }

    private function creerEtablissement(): Etablissement
    {
        $groupe = (new Groupe())->setNom('Groupe Test OCR');
        $this->em->persist($groupe);
        $region = (new Region())->setNom('Région Test OCR')->setGroupe($groupe);
        $this->em->persist($region);
        $etablissement = (new Etablissement())->setNom('Etablissement Test OCR')->setRegion($region)->setActif(true);
        $this->em->persist($etablissement);
        $this->em->flush();

        return $etablissement;
    }
}
