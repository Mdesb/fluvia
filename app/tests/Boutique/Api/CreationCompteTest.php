<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Crm\Entity\Client;
use App\Tests\Boutique\BoutiqueApiTestCase;

/**
 * Création de compte client final — contrôle anti-homonyme via la date de naissance, jamais de
 * fusion automatique (US-L8-04, RG-M3-10, CA-5).
 */
final class CreationCompteTest extends BoutiqueApiTestCase
{
    public function testCa5MemeNomPrenomDateNaissanceDifferenteAucuneFusion(): void
    {
        $vitrine = $this->idVitrineA();
        $client = static::createClient();

        $client->request('POST', '/api/boutique/comptes', [
            'json' => [
                'vitrine' => $vitrine, 'email' => 'jean.dupont.1990@example.test', 'motDePasse' => 'MotDePasse#1',
                'nom' => 'Homonyme', 'prenom' => 'Alex', 'dateNaissance' => '1990-01-01',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/boutique/comptes', [
            'json' => [
                'vitrine' => $vitrine, 'email' => 'jean.dupont.1985@example.test', 'motDePasse' => 'MotDePasse#2',
                'nom' => 'Homonyme', 'prenom' => 'Alex', 'dateNaissance' => '1985-05-05',
            ],
        ]);
        self::assertResponseIsSuccessful();

        $fiches = $this->em()->getRepository(Client::class)->findBy(['nom' => 'Homonyme', 'prenom' => 'Alex']);
        self::assertCount(2, $fiches, 'CA-5 : deux fiches Client distinctes, jamais fusionnées.');
        self::assertNull($fiches[0]->getFusionneDans());
        self::assertNull($fiches[1]->getFusionneDans());
    }

    public function testEmailDejaUtiliseRefuseLaCreation(): void
    {
        $vitrine = $this->idVitrineA();
        $client = static::createClient();
        $donnees = [
            'vitrine' => $vitrine, 'email' => 'doublon@example.test', 'motDePasse' => 'MotDePasse#1',
            'nom' => 'Nom', 'prenom' => 'Prenom', 'dateNaissance' => '1990-01-01',
        ];
        $client->request('POST', '/api/boutique/comptes', ['json' => $donnees]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/boutique/comptes', ['json' => $donnees]);
        self::assertResponseStatusCodeSame(409);
    }

    /**
     * AUDIT DU 06/09, CONSTAT 7. Cette porte acceptait « aaa » — vérifié en préproduction, HTTP 201, puis
     * `/auth` rendait un jeton valide avec. Douze caractères au minimum, désormais, et le même seuil que
     * l'activation et la réinitialisation (`PasswordPolicy`).
     */
    public function testUnMotDePasseTropCourtEstRefuse(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/boutique/comptes', [
            'json' => [
                'vitrine' => $this->idVitrineA(), 'email' => 'mdp.court@example.test', 'motDePasse' => 'aaa',
                'nom' => 'Court', 'prenom' => 'Mot', 'dateNaissance' => '1990-01-01',
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->em()->getRepository(Client::class)->findBy(['email' => 'mdp.court@example.test']), 'aucune fiche ne doit exister');
    }
}
