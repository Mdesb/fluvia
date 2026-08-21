<?php

declare(strict_types=1);

namespace App\Tests\Ocr\Api;

use App\DataFixtures\SocleFixtures;
use App\Ocr\Entity\OcrProviderConfig;
use App\Organisation\Entity\Etablissement;
use App\Tests\Ocr\OcrApiTestCase;

/**
 * `OcrProviderConfig` (plan-ocr.md §2/§3) : CA-6 — la clé API n'apparaît **jamais** en clair ni même
 * chiffrée dans la réponse JSON (positif : champ absent du payload), `hasApiKey` reflète l'état,
 * `security: ocr.configure` sur les 4 opérations (403 sans la permission), 1 configuration par
 * établissement (409 en cas de doublon), `establishment` toujours dérivé côté serveur.
 */
final class OcrProviderConfigApiTest extends OcrApiTestCase
{
    public function testHasApiKeyRefleteLetatSansJamaisExposerLaCle(): void
    {
        [$clientA, $enteteA] = $this->adminSurA();
        $etabA = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);
        $configA = $this->entite(OcrProviderConfig::class, ['establishment' => $etabA]);

        $clientA->request('GET', '/api/ocr_provider_configs/' . $configA->getId(), $enteteA);
        self::assertResponseIsSuccessful();
        $corpsA = $clientA->getResponse()->toArray();
        self::assertFalse($corpsA['hasApiKey'], 'Établissement A (fixtures) : aucune clé configurée.');

        [$clientB, $enteteB] = $this->adminSurB();
        $etabB = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM]);
        $configB = $this->entite(OcrProviderConfig::class, ['establishment' => $etabB]);

        $clientB->request('GET', '/api/ocr_provider_configs/' . $configB->getId(), $enteteB);
        self::assertResponseIsSuccessful();
        $corpsB = $clientB->getResponse()->toArray();
        self::assertTrue($corpsB['hasApiKey'], 'Établissement B (fixtures) : clé API configurée.');
    }

    public function testLaCleApiNestJamaisPresenteDansLaReponseJson(): void
    {
        [$client, $entete] = $this->adminSurB();

        $client->request('GET', '/api/ocr_provider_configs', $entete);
        self::assertResponseIsSuccessful();
        $corps = $client->getResponse()->getContent();

        self::assertStringNotContainsString('apiKeyEncrypted', $corps);
        self::assertStringNotContainsString('apiKeyPlain', $corps);
        self::assertStringNotContainsString('sk-ant-demo', $corps, 'Ni la clé de démo en clair...');
        self::assertStringNotContainsString('sk-ant-', $corps, '... ni son préfixe.');
    }

    public function testLectureRefuseeSansPermissionOcrConfigure(): void
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => ['X-Etablissement' => $idA]];

        $client->request('GET', '/api/ocr_provider_configs', $entete);
        self::assertResponseStatusCodeSame(403);
    }

    public function testEcritureRefuseeSansPermissionOcrConfigure(): void
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => ['X-Etablissement' => $idA]];

        $client->request('POST', '/api/ocr_provider_configs', $entete + ['json' => ['provider' => 'manual']]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testPostSurEtablissementDejaConfigureRetourne409Conflict(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/ocr_provider_configs', $entete + ['json' => ['provider' => 'manual']]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testPatchMetAJourLeProviderEtLaCleApiChiffreeeAuRepos(): void
    {
        [$client, $entete] = $this->adminSurA();
        $etabA = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);
        $configA = $this->entite(OcrProviderConfig::class, ['establishment' => $etabA]);

        $client->request('PATCH', '/api/ocr_provider_configs/' . $configA->getId(), [
            'headers' => $entete['headers'] + ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['provider' => 'anthropic', 'apiKeyPlain' => 'sk-ant-nouvelle-cle-test', 'confidenceThreshold' => '0.85'],
        ] + $entete);
        self::assertResponseIsSuccessful();
        $corps = $client->getResponse()->toArray();
        self::assertSame('anthropic', $corps['provider']);
        self::assertTrue($corps['hasApiKey']);
        self::assertSame('0.85', $corps['confidenceThreshold']);
        self::assertArrayNotHasKey('apiKeyEncrypted', $corps);
        self::assertArrayNotHasKey('apiKeyPlain', $corps);

        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $configApres = $em->getRepository(OcrProviderConfig::class)->find($configA->getId());
        self::assertNotNull($configApres->getApiKeyEncrypted());
        /** @var \App\Ocr\Service\ChiffreurApiKeyOcr $chiffreur */
        $chiffreur = static::getContainer()->get(\App\Ocr\Service\ChiffreurApiKeyOcr::class);
        self::assertSame('sk-ant-nouvelle-cle-test', $chiffreur->dechiffrer((string) $configApres->getApiKeyEncrypted()));
    }

    /**
     * La ressource `OcrProviderConfig` est bien **exposée** (découverte via `mapping.paths`) et sa
     * collection expose la configuration OCR de l'établissement de l'utilisateur (`configA`, fixtures).
     * Remplace l'ancien test de filtre `establishment` : ce filtre a été retiré (redondant avec le
     * cloisonnement `PerimetreOcrExtension`, et inopérant sur une relation UUID). L'exclusion des autres
     * établissements est couverte par `CloisonnementOcrTest`.
     */
    public function testCollectionExposeLaConfigDeLEtablissement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $iriA = '/api/etablissements/' . $this->idEtablissement(SocleFixtures::ETAB_A_NOM);

        $client->request('GET', '/api/ocr_provider_configs', $entete);
        self::assertResponseIsSuccessful();
        $liste = $client->getResponse()->toArray();
        $membres = $liste['member'] ?? $liste['hydra:member'];

        $configsDeA = array_filter($membres, static fn (array $item): bool => ($item['establishment'] ?? null) === $iriA);
        self::assertNotEmpty($configsDeA, 'La collection doit exposer la configuration OCR de l\'établissement A (ressource découverte + cloisonnement).');
    }
}
