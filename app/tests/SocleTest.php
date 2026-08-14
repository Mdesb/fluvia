<?php

declare(strict_types=1);

namespace App\Tests;

use App\DataFixtures\SocleFixtures;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Couverture des critères d'acceptation du socle (CA-1..CA-6).
 */
final class SocleTest extends SocleApiTestCase
{
    /** CA-1 — Créer une Région sans Groupe est refusé (422). */
    public function testCa1RegionSansGroupeRefusee(): void
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);

        $client->request('POST', '/api/regions', [
            'auth_bearer' => $token,
            'json' => ['nom' => 'Région orpheline'],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    /** CA-2 — Connexion : bons identifiants -> jeton ; mauvais -> 401. */
    public function testCa2Connexion(): void
    {
        $client = static::createClient();

        $ok = $client->request('POST', '/auth', [
            'json' => ['email' => SocleFixtures::ADMIN_EMAIL, 'motDePasse' => SocleFixtures::ADMIN_MDP],
        ]);
        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('token', $ok->toArray());

        $client->request('POST', '/auth', [
            'json' => ['email' => SocleFixtures::ADMIN_EMAIL, 'motDePasse' => 'mauvais'],
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    /** CA-3 — Après 5 échecs, le compte est verrouillé (même un bon mot de passe est refusé). */
    public function testCa3VerrouillageApres5Echecs(): void
    {
        $client = static::createClient();

        for ($i = 0; $i < 5; $i++) {
            $client->request('POST', '/auth', [
                'json' => ['email' => SocleFixtures::LECTEUR_EMAIL, 'motDePasse' => 'mauvais'],
            ]);
            self::assertResponseStatusCodeSame(401);
        }

        // Bon mot de passe désormais refusé : compte verrouillé.
        $client->request('POST', '/auth', [
            'json' => ['email' => SocleFixtures::LECTEUR_EMAIL, 'motDePasse' => SocleFixtures::LECTEUR_MDP],
        ]);
        self::assertResponseStatusCodeSame(401);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $lecteur = $em->getRepository(Utilisateur::class)->findOneBy(['email' => SocleFixtures::LECTEUR_EMAIL]);
        self::assertNotNull($lecteur);
        self::assertGreaterThanOrEqual(5, $lecteur->getTentativesEchouees());
        self::assertTrue($lecteur->estVerrouille(), 'Le compte doit être verrouillé après 5 échecs.');
    }

    /** CA-4 — Un utilisateur affecté à A seulement ne voit pas B. */
    public function testCa4CloisonnementEtablissement(): void
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);

        $reponse = $client->request('GET', '/api/etablissements', ['auth_bearer' => $token]);
        self::assertResponseIsSuccessful();

        $donnees = $reponse->toArray();
        $noms = array_map(static fn (array $e): string => $e['nom'], $donnees['member'] ?? $donnees['hydra:member']);

        self::assertContains(SocleFixtures::ETAB_A_NOM, $noms);
        self::assertNotContains(SocleFixtures::ETAB_B_NOM, $noms);
    }

    /** CA-5 — Sans permission organisation.gerer, créer un Établissement -> 403. */
    public function testCa5CreationEtablissementSansPermission(): void
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);
        $idRegion = $this->idRegion();

        $client->request('POST', '/api/etablissements', [
            'auth_bearer' => $token,
            'json' => ['nom' => 'Établissement interdit', 'region' => '/api/regions/' . $idRegion, 'actif' => true],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    /** CA-6 — Une action sensible crée une EntreeAudit ; l'audit est en lecture seule (POST -> 405). */
    public function testCa6AuditCreeEtImmuable(): void
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);

        // Action sensible : création d'un Groupe.
        $client->request('POST', '/api/groupes', [
            'auth_bearer' => $token,
            'json' => ['nom' => 'Groupe audité'],
        ]);
        self::assertResponseStatusCodeSame(201);

        // Des entrées d'audit existent.
        $audits = $client->request('GET', '/api/entree_audits', ['auth_bearer' => $token]);
        self::assertResponseIsSuccessful();
        $donnees = $audits->toArray();
        $total = $donnees['totalItems'] ?? $donnees['hydra:totalItems'] ?? 0;
        self::assertGreaterThan(0, $total, 'Au moins une entrée d\'audit doit exister.');

        // L'audit est append-only : POST non exposé -> 405.
        $client->request('POST', '/api/entree_audits', [
            'auth_bearer' => $token,
            'json' => ['action' => 'triche'],
        ]);
        self::assertResponseStatusCodeSame(405);
    }

    private function idRegion(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $region = $em->getRepository(\App\Organisation\Entity\Region::class)->findOneBy([]);
        self::assertNotNull($region);

        return (string) $region->getId();
    }
}
