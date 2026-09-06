<?php

declare(strict_types=1);

namespace App\Tests\Securite\Api;

use App\DataFixtures\SocleFixtures;
use App\Securite\DataFixtures\L7Fixtures;
use App\Securite\Entity\Utilisateur;
use App\Tests\Securite\SecuriteApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cycle de vie utilisateur (RG-M8-01, US-L7-03) : invitation, activation, suspension.
 */
final class CycleVieUtilisateurTest extends SecuriteApiTestCase
{
    /** CA-1 — Création par un admin sans mot de passe ⇒ statut=invite, jeton jamais renvoyé en clair. */
    public function testCa1CreationInviteJetonGenere(): void
    {
        [$client, $entete] = $this->adminSurA();

        $reponse = $client->request('POST', '/api/utilisateurs', $entete + [
            'json' => ['email' => 'nouveau.invite@itcotation.com', 'nom' => 'Nouveau Invité'],
        ]);
        self::assertResponseStatusCodeSame(201);
        $donnees = $reponse->toArray();
        self::assertSame('invite', $donnees['statut'] ?? null);
        self::assertArrayNotHasKey('jetonInvitation', $donnees);
        self::assertArrayNotHasKey('motDePasse', $donnees);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $utilisateur = $em->getRepository(Utilisateur::class)->findOneBy(['email' => 'nouveau.invite@itcotation.com']);
        self::assertNotNull($utilisateur);
        self::assertNotNull($utilisateur->getJetonInvitation(), 'Le jeton (haché) doit être persisté.');
        self::assertNotNull($utilisateur->getJetonInvitationExpire());
    }

    /** CA-2 — Activation avec jeton valide ⇒ statut=actif, jeton consommé ; jeton expiré ⇒ refus. */
    public function testCa2ActivationJetonValideEtExpire(): void
    {
        $client = static::createClient();

        $reponse = $client->request('POST', '/utilisateurs/activation', [
            'json' => ['jeton' => L7Fixtures::INVITE_JETON_CLAIR, 'motDePasse' => 'NouveauMdp#2026'],
        ]);
        self::assertResponseIsSuccessful();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $utilisateur = $em->getRepository(Utilisateur::class)->findOneBy(['email' => L7Fixtures::INVITE_EMAIL]);
        self::assertNotNull($utilisateur);
        self::assertTrue($utilisateur->isActif());
        self::assertNull($utilisateur->getJetonInvitation());

        // Rejouer le même jeton (déjà consommé) : refus.
        $client->request('POST', '/utilisateurs/activation', [
            'json' => ['jeton' => L7Fixtures::INVITE_JETON_CLAIR, 'motDePasse' => 'Autre#2026-douze'],
        ]);
        self::assertResponseStatusCodeSame(422);

        // Jeton expiré : refus (410).
        $client->request('POST', '/utilisateurs/activation', [
            'json' => ['jeton' => L7Fixtures::INVITE_EXPIRE_JETON_CLAIR, 'motDePasse' => 'Autre#2026-douze'],
        ]);
        self::assertResponseStatusCodeSame(410);
    }

    /** CA-3 — Suspension ⇒ tokenVersion++, l'ancien JWT est refusé (401) dès la requête suivante. */
    public function testCa3SuspensionInvalideJwt(): void
    {
        // Un seul client tout au long du test : les helpers de statique d'assertion
        // (`assertResponseStatusCodeSame`) portent sur la dernière requête du DERNIER client créé.
        $client = static::createClient();
        $token = $this->jeton($client, L7Fixtures::ADMIN2_EMAIL, L7Fixtures::ADMIN2_MDP);

        // Le jeton fonctionne avant suspension.
        $client->request('GET', '/me', ['auth_bearer' => $token]);
        self::assertResponseIsSuccessful();

        // L'admin principal suspend admin2 (qui n'est pas le dernier admin de A, cf. fixtures).
        $tokenAdmin = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);
        $idAdmin2 = $this->idUtilisateur(L7Fixtures::ADMIN2_EMAIL);
        $idEtabA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $entete = ['auth_bearer' => $tokenAdmin, 'headers' => [\App\Securite\Service\ContexteEtablissement::HEADER => $idEtabA]];
        $client->request('POST', '/api/utilisateurs/' . $idAdmin2 . '/suspendre', $entete);
        self::assertResponseIsSuccessful();

        // L'ancien jeton d'admin2 est désormais refusé.
        $client->request('GET', '/me', ['auth_bearer' => $token]);
        self::assertResponseStatusCodeSame(401);

        // L'historique d'audit n'est pas supprimé (au moins une entrée existe encore).
        $audits = $client->request('GET', '/api/entree_audits', $entete);
        self::assertResponseIsSuccessful();
        $total = $audits->toArray()['totalItems'] ?? $audits->toArray()['hydra:totalItems'] ?? 0;
        self::assertGreaterThan(0, $total);
    }

    /** CA-11 (variante) — Suspendre le SEUL administrateur d'un établissement est refusé (422). */
    public function testSuspensionDernierAdministrateurRefusee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $idAdmin = $this->idUtilisateur(SocleFixtures::ADMIN_EMAIL);

        // L'admin socle est seul administrateur de l'établissement B (pas de second admin dessus).
        $client->request('POST', '/api/utilisateurs/' . $idAdmin . '/suspendre', $entete);
        self::assertResponseStatusCodeSame(422);
    }
}
