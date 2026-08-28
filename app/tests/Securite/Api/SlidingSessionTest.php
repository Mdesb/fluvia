<?php

declare(strict_types=1);

namespace App\Tests\Securite\Api;

use App\DataFixtures\SocleFixtures;
use App\Securite\Entity\Utilisateur;
use App\Securite\Security\SlidingSessionListener;
use App\Tests\Securite\SecuriteApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

/**
 * L'ACTIVITÉ PROLONGE LA SESSION, L'INACTIVITÉ LA FERME.
 *
 * `token_ttl` vaut 3600 : un caissier était déconnecté au bout d'une heure, **en pleine vente et
 * sans un mot** — rien ne distingue « votre session a expiré » d'une panne. Constaté deux fois
 * pendant une revue d'écrans.
 *
 * ⚠ UN « PING RÉGULIER » N'Y AURAIT RIEN CHANGÉ, et c'est la première chose que ce test fige :
 * l'expiration d'un JWT est fixée à son émission. Appeler une route toutes les cinq minutes rend
 * 200 pendant une heure puis 401 exactement au même instant qu'avant.
 *
 * Ce qui réalise l'intention, c'est de RÉÉMETTRE. Les trois tests couvrent les trois moments où
 * cela peut mal tourner : réémettre trop tôt (bruit et journaux illisibles), ne pas réémettre quand
 * il le faut (la panne reste), et réémettre **sans fin** — le cas dangereux, où un jeton dérobé se
 * renouvelle indéfiniment pour peu qu'on s'en serve.
 */
final class SlidingSessionTest extends SecuriteApiTestCase
{
    public function testUnJetonFraisNEstPasReemis(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('GET', '/api/etablissements', $entete);

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey(
            strtolower(SlidingSessionListener::ENTETE),
            $client->getResponse()->getHeaders(false),
            'réémettre à chaque requête ferait tourner les jetons pour rien et rendrait tout journal d’authentification illisible',
        );
    }

    /**
     * PASSÉ LA MOITIÉ DE SA VIE, LE JETON EST REMPLACÉ.
     *
     * Le jeton est forgé avec un `exp` proche : c'est la seule façon d'éprouver le mécanisme sans
     * attendre trente minutes, et cela mesure bien ce qu'on veut — la DÉCISION du serveur, pas
     * l'écoulement du temps.
     */
    public function testUnJetonAMoitieUseEstReemis(): void
    {
        $client = static::createClient();
        $entete = ['auth_bearer' => $this->jetonExpirantDans(600), 'headers' => [
            'X-Etablissement' => $this->idEtablissement(SocleFixtures::ETAB_A_NOM),
        ]];

        $client->request('GET', '/api/etablissements', $entete);

        self::assertResponseIsSuccessful();
        $entetes = $client->getResponse()->getHeaders(false);
        $frais = $entetes[strtolower(SlidingSessionListener::ENTETE)][0] ?? null;

        self::assertNotNull($frais, 'un jeton à dix minutes de l’expiration doit être remplacé');
        self::assertNotSame($entete['auth_bearer'], $frais, 'le jeton rendu doit être un jeton NEUF');
    }

    /**
     * LA BORNE ABSOLUE — c'est ce test qui rend le mécanisme défendable.
     *
     * Sans plafond, un jeton dérobé se renouvellerait indéfiniment : il suffirait de s'en servir.
     * `sessionDebut` porte l'heure de la PREMIÈRE connexion et voyage de réémission en réémission
     * sans jamais être remis à zéro.
     *
     *   > Une session qui se prolonge sans fin n'est plus une session, c'est un mot de passe.
     */
    public function testUneSessionTropAncienneNEstPlusProlongee(): void
    {
        $client = static::createClient();
        $entete = ['auth_bearer' => $this->jetonExpirantDans(600, time() - (13 * 3600)), 'headers' => [
            'X-Etablissement' => $this->idEtablissement(SocleFixtures::ETAB_A_NOM),
        ]];

        $client->request('GET', '/api/etablissements', $entete);

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey(
            strtolower(SlidingSessionListener::ENTETE),
            $client->getResponse()->getHeaders(false),
            'au-delà de la durée maximale, la session doit s’éteindre — sinon un jeton dérobé se renouvelle sans fin',
        );
    }

    /** Forge un jeton valide dont l'expiration est proche, et dont la session a éventuellement commencé plus tôt. */
    private function jetonExpirantDans(int $secondes, ?int $sessionDebut = null): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $utilisateur = $em->getRepository(Utilisateur::class)->findOneBy(['email' => SocleFixtures::ADMIN_EMAIL]);
        self::assertInstanceOf(Utilisateur::class, $utilisateur);

        /** @var JWTTokenManagerInterface $jwt */
        $jwt = static::getContainer()->get(JWTTokenManagerInterface::class);

        $maintenant = time();

        return $jwt->createFromPayload($utilisateur, [
            'iat' => $maintenant,
            'exp' => $maintenant + $secondes,
            SlidingSessionListener::CLAIM_DEBUT => $sessionDebut ?? $maintenant,
        ]);
    }
}
