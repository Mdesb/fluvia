<?php

declare(strict_types=1);

namespace App\Tests\I18n\Api;

use App\DataFixtures\SocleFixtures;
use App\Securite\Entity\Utilisateur;
use App\Tests\SocleApiTestCase;

/**
 * La langue de l'établissement et la préférence de l'utilisateur, par l'API qu'emploie le frontal :
 * `/api/etablissements` pour la première, `/me` pour la seconde.
 */
final class LocaleApiTest extends SocleApiTestCase
{
    public function testEstablishmentSpeaksFrenchByDefaultAndCanSwitchToSpanish(): void
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $options = ['auth_bearer' => $token, 'headers' => ['X-Etablissement' => $idA]];

        $lu = $client->request('GET', '/api/etablissements/' . $idA, $options)->toArray();
        self::assertSame('fr', $lu['locale']);

        $client->request('PATCH', '/api/etablissements/' . $idA, [
            'auth_bearer' => $token,
            'headers' => ['X-Etablissement' => $idA, 'Content-Type' => 'application/merge-patch+json'],
            'json' => ['locale' => 'es'],
        ]);
        self::assertResponseStatusCodeSame(200);

        $relu = $client->request('GET', '/api/etablissements/' . $idA, $options)->toArray();
        self::assertSame('es', $relu['locale'], 'la langue écrite doit être celle relue');
    }

    /** `ca` est annoncé mais pas ouvert : sans catalogue frontal, aucun écran ne le parlerait. */
    public function testLanguageWithoutCatalogueIsRefused(): void
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);

        foreach (['ca', 'xx'] as $langue) {
            $client->request('PATCH', '/api/etablissements/' . $idA, [
                'auth_bearer' => $token,
                'headers' => ['X-Etablissement' => $idA, 'Content-Type' => 'application/merge-patch+json'],
                'json' => ['locale' => $langue],
            ]);
            self::assertResponseStatusCodeSame(422, sprintf('« %s » ne doit pas être accepté', $langue));
        }

        $lu = $client->request('GET', '/api/etablissements/' . $idA, [
            'auth_bearer' => $token,
            'headers' => ['X-Etablissement' => $idA],
        ])->toArray();
        self::assertSame('fr', $lu['locale'], 'un refus ne doit rien avoir écrit');
    }

    public function testMeCarriesTheUserPreferenceNullUntilChosen(): void
    {
        $client = static::createClient();
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $lecteur = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);

        $me = $client->request('GET', '/me', ['auth_bearer' => $lecteur, 'headers' => ['X-Etablissement' => $idA]])->toArray();
        self::assertArrayHasKey('locale', $me);
        self::assertNull($me['locale'], 'sans choix, la personne suit la langue de l’établissement');

        $admin = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);
        $patch = fn (string $locale) => $client->request('PATCH', '/api/utilisateurs/' . $this->idUser(SocleFixtures::LECTEUR_EMAIL), [
            'auth_bearer' => $admin,
            'headers' => ['X-Etablissement' => $idA, 'Content-Type' => 'application/merge-patch+json'],
            'json' => ['locale' => $locale],
        ]);
        $patch('xx');
        self::assertResponseStatusCodeSame(422);
        $patch('es');
        self::assertResponseStatusCodeSame(200);

        $relu = $client->request('GET', '/me', ['auth_bearer' => $lecteur, 'headers' => ['X-Etablissement' => $idA]])->toArray();
        self::assertSame('es', $relu['locale']);
    }

    private function idUser(string $email): string
    {
        $user = static::getContainer()->get('doctrine')->getManager()
            ->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);

        return (string) $user->getId();
    }
}
