<?php

declare(strict_types=1);

namespace App\Tests\Ocr\Api;

use App\DataFixtures\SocleFixtures;
use App\Ocr\Entity\ExtractionAttempt;
use App\Ocr\Entity\OcrProviderConfig;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Ocr\OcrApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cloisonnement multi-entités (RG-SOCLE-05, plan-ocr.md §3) — même patron que
 * `App\Tests\Compta\Api\CloisonnementTest`/`App\Tests\Acces\Api\CloisonnementTest` : un utilisateur
 * affecté uniquement à l'établissement A, même muni des permissions `ocr.configure`/
 * `ocr.read_extraction`, ne voit ni la config ni les tentatives de l'établissement B (403/404, jamais
 * un filtre silencieux qui renverrait une liste vide sans distinguer « vide » de « hors périmètre »).
 */
final class CloisonnementOcrTest extends OcrApiTestCase
{
    public function testUtilisateurScopeANeVoitPasLaConfigDeB(): void
    {
        [$email, $motDePasse] = $this->creerUtilisateurOcrScopeSurA();
        $etabB = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM]);
        $configB = $this->entite(OcrProviderConfig::class, ['establishment' => $etabB]);

        $client = static::createClient();
        $token = $this->jeton($client, $email, $motDePasse);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        // Lecture directe par id (IDOR) : la config de B doit rester invisible (404, jamais 200).
        $client->request('GET', '/api/ocr_provider_configs/' . $configB->getId(), $entete);
        self::assertResponseStatusCodeSame(404, (string) $client->getResponse()->getContent(false));

        // Lecture de la collection : aucune trace de B (filtre silencieux interdit — mais la ressource
        // reste accessible via la permission, seule la portée est restreinte).
        $client->request('GET', '/api/ocr_provider_configs', $entete);
        self::assertResponseIsSuccessful();
        $corps = $client->getResponse()->getContent();
        self::assertStringNotContainsString((string) $configB->getId(), $corps);
    }

    public function testUtilisateurScopeANeVoitPasLesTentativesDeB(): void
    {
        [$email, $motDePasse] = $this->creerUtilisateurOcrScopeSurA();
        $etabB = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM]);
        $tentativeB = $this->entite(ExtractionAttempt::class, ['establishment' => $etabB]);

        $client = static::createClient();
        $token = $this->jeton($client, $email, $motDePasse);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        $client->request('GET', '/api/extraction_attempts/' . $tentativeB->getId(), $entete);
        self::assertResponseStatusCodeSame(404, (string) $client->getResponse()->getContent(false));

        $client->request('GET', '/api/extraction_attempts', $entete);
        self::assertResponseIsSuccessful();
        $corps = $client->getResponse()->getContent();
        self::assertStringNotContainsString((string) $tentativeB->getId(), $corps);
    }

    public function testUtilisateurScopeANePeutPasPatcherLaConfigDeB(): void
    {
        [$email, $motDePasse] = $this->creerUtilisateurOcrScopeSurA();
        $etabB = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM]);
        $configB = $this->entite(OcrProviderConfig::class, ['establishment' => $etabB]);

        $client = static::createClient();
        $token = $this->jeton($client, $email, $motDePasse);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idA]];

        $client->request('PATCH', '/api/ocr_provider_configs/' . $configB->getId(), [
            'headers' => $entete['headers'] + ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['provider' => 'anthropic'],
        ] + $entete);
        self::assertResponseStatusCodeSame(404, (string) $client->getResponse()->getContent(false));

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $configApres = $em->getRepository(OcrProviderConfig::class)->find($configB->getId());
        self::assertNotNull($configApres);
        self::assertSame('anthropic', $configApres->getProvider()->value, 'La config B (fixtures) était déjà anthropic — reste inchangée, aucune écriture cross-établissement.');
    }

    /** @return array{0: string, 1: string} email, mot de passe d'un utilisateur ocr.configure/ocr.read_extraction affecté sur A uniquement */
    private function creerUtilisateurOcrScopeSurA(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $etabA = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertNotNull($etabA);

        $permConfigure = $em->getRepository(Permission::class)->findOneBy(['module' => 'ocr', 'action' => 'configure']);
        $permLire = $em->getRepository(Permission::class)->findOneBy(['module' => 'ocr', 'action' => 'read_extraction']);
        self::assertNotNull($permConfigure);
        self::assertNotNull($permLire);

        $role = (new Role())->setNom('Gestionnaire OCR (scope A, test cloisonnement)');
        $role->addPermission($permConfigure)->addPermission($permLire);
        $em->persist($role);

        $email = 'gestionnaire.ocr.scope-a@itcotation.com';
        $motDePasse = 'GestionnaireOcr#2026';
        $utilisateur = (new Utilisateur())->setEmail($email)->setNom('Gestionnaire OCR Scope A')->setActif(true);
        $utilisateur->setMotDePasse($hasher->hashPassword($utilisateur, $motDePasse));
        $em->persist($utilisateur);

        $em->persist((new Affectation())->setUtilisateur($utilisateur)->setRole($role)->setEtablissement($etabA));

        $em->flush();

        return [$email, $motDePasse];
    }
}
