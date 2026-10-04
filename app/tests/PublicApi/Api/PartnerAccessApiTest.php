<?php

declare(strict_types=1);

namespace App\Tests\PublicApi\Api;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\PublicApi\Entity\ApiGrant;
use App\PublicApi\Entity\PartnerApplication;
use App\PublicApi\Enum\GrantStatus;
use App\PublicApi\Service\PartnerAccessManager;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Tests\PublicApi\PublicApiTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Le consentement, côté exploitant : accorder des portées sur SON établissement, les retirer
 * (spec API partenaire v1, §3.1 et critère d'acceptation n°2).
 */
final class PartnerAccessApiTest extends PublicApiTestCase
{
    private const B_ONLY_EMAIL = 'exploitant.b@example.test';
    private const B_ONLY_PASSWORD = 'ExploitantB#2026';

    /** La clé passe de « voit A » à « ne voit rien », immédiatement, et chaque geste laisse sa trace sur A. */
    public function testAccorderPuisRetirerOuvrePuisRefermeA(): void
    {
        $application = $this->createApplication();
        ['secret' => $secret] = $this->issueKey($application['id']);
        $a = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);

        [$client, $headers] = $this->admin();
        $list = $client->request('GET', '/api/partner-accesses', $headers)->toArray()['member'] ?? [];
        self::assertSame([$application['id']], array_column($list, 'id'));
        self::assertNull($list[0]['grant']);
        $offered = array_column($list[0]['availableScopes'], 'value');
        self::assertContains('access:read', $offered, 'sinon le refus ci-dessous ne prouverait rien');
        self::assertNotContains('bookings:write', $offered);

        $granted = $client->request('POST', '/api/partner-accesses/'.$application['id'].'/grant', $headers + [
            'json' => ['scopes' => ['access:read']],
        ])->toArray();
        self::assertSame(['access:read'], $granted['grant']['scopes']);

        [, $me] = $this->me($secret);
        self::assertSame([['establishment' => $a, 'scopes' => ['access:read']]], $me['grants'], 'la clé voit A');

        [$client, $headers] = $this->admin();
        $client->request('POST', '/api/partner-accesses/'.$application['id'].'/withdraw', $headers);
        self::assertResponseIsSuccessful();

        [$status, $me] = $this->me($secret);
        self::assertSame(200, $status);
        self::assertSame([], $me['grants'], 'la clé ne voit plus rien');

        $partner = $this->em()->getRepository(PartnerApplication::class)->find($application['id']);
        $grant = $this->em()->getRepository(ApiGrant::class)->findOneBy(['application' => $partner]);
        self::assertNotNull($grant);
        self::assertSame(GrantStatus::Revoked, $grant->getStatus());
        self::assertSame($a, $this->auditEstablishment(PartnerAccessManager::ACTION_GRANT_GRANTED, (string) $grant->getId()));
        self::assertSame($a, $this->auditEstablishment(PartnerAccessManager::ACTION_GRANT_WITHDRAWN, (string) $grant->getId()));
    }

    /**
     * Un exploitant de B ne voit pas l'accord de A, ne peut pas le retirer, et ne peut pas se faire
     * passer pour A. Témoin : l'accord de A est toujours là après ses trois tentatives.
     */
    public function testUnExploitantDeBNeVoitNiNeRetireLAccordDeA(): void
    {
        $application = $this->createApplication();
        ['secret' => $secret] = $this->issueKey($application['id']);
        [$client, $headers] = $this->admin();
        $client->request('POST', '/api/partner-accesses/'.$application['id'].'/grant', $headers + [
            'json' => ['scopes' => ['access:read']],
        ]);
        self::assertResponseIsSuccessful();

        [$clientB, $headersB] = $this->exploitantOfBOnly();

        $list = $clientB->request('GET', '/api/partner-accesses', $headersB)->toArray()['member'] ?? [];
        self::assertSame([$application['id']], array_column($list, 'id'), 'B voit l’application…');
        self::assertNull($list[0]['grant'], '… mais pas l’accord donné par A');

        $clientB->request('POST', '/api/partner-accesses/'.$application['id'].'/withdraw', $headersB);
        self::assertResponseIsSuccessful('retirer « son » accord ne touche que B — il n’en a pas');

        $headersBAsA = $headersB;
        $headersBAsA['headers']['X-Etablissement'] = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $refused = $clientB->request('POST', '/api/partner-accesses/'.$application['id'].'/withdraw', $headersBAsA);
        self::assertSame(404, $refused->getStatusCode(), 'B ne peut pas se présenter comme A');

        [, $me] = $this->me($secret);
        self::assertCount(1, $me['grants'], 'témoin : l’accord de A tient toujours');
        self::assertSame($this->idEtablissement(SocleFixtures::ETAB_A_NOM), $me['grants'][0]['establishment']);
    }

    /** `bookings:write` n'existe plus : un exploitant ne peut pas consentir à une écriture que rien n'exécute. */
    public function testUnePorteeInconnueOuRetireeEstRefusee(): void
    {
        $application = $this->createApplication();
        [$client, $headers] = $this->admin();

        $refused = $client->request('POST', '/api/partner-accesses/'.$application['id'].'/grant', $headers + [
            'json' => ['scopes' => ['bookings:write']],
        ]);
        self::assertSame(422, $refused->getStatusCode());
        self::assertCount(0, $this->em()->getRepository(ApiGrant::class)->findAll());
    }

    /** Sans `api.gerer`, ni la liste ni le geste. Témoin : l'administratrice, qui le porte, passe. */
    public function testSansLaPermissionApiGererRienNEstAccessible(): void
    {
        $client = static::createClient();
        $reader = [
            'auth_bearer' => $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP),
            'headers' => ['X-Etablissement' => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)],
        ];
        self::assertSame(403, $client->request('GET', '/api/partner-accesses', $reader)->getStatusCode());

        [$client, $headers] = $this->admin();
        $client->request('GET', '/api/partner-accesses', $headers);
        self::assertResponseIsSuccessful();
    }

    /**
     * Un administrateur affecté à B SEULEMENT. L'administratrice des fixtures est sur A et B : avec
     * elle, « ne voit pas A » mesurerait le choix d'en-tête, pas le cloisonnement.
     *
     * @return array{0: \ApiPlatform\Symfony\Bundle\Test\Client, 1: array<string, mixed>}
     */
    private function exploitantOfBOnly(): array
    {
        $em = $this->em();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = (new Utilisateur())->setEmail(self::B_ONLY_EMAIL)->setNom('Exploitant B')->setActif(true);
        $user->setMotDePasse($hasher->hashPassword($user, self::B_ONLY_PASSWORD));
        $em->persist($user);

        $role = $em->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe']);
        $b = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertInstanceOf(Role::class, $role);
        self::assertInstanceOf(Etablissement::class, $b);
        $em->persist((new Affectation())->setUtilisateur($user)->setRole($role)->setEtablissement($b));
        $em->flush();

        $client = static::createClient();

        return [$client, [
            'auth_bearer' => $this->jeton($client, self::B_ONLY_EMAIL, self::B_ONLY_PASSWORD),
            'headers' => ['X-Etablissement' => (string) $b->getId()],
        ]];
    }
}
