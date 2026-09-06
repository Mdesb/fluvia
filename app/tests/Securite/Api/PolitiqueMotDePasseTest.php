<?php

declare(strict_types=1);

namespace App\Tests\Securite\Api;

use App\Securite\Entity\Utilisateur;
use App\Tests\Securite\SecuriteApiTestCase;

/**
 * AUDIT DU 06/09, CONSTAT 7 — la quatrième porte : un administrateur qui crée un compte exploitant « avec
 * mot de passe » (`motDePasseClair`) pouvait poser « a ». L'activation et la réinitialisation avaient
 * déjà leur seuil ; celle-ci n'en avait aucun. Même règle, même seuil, désormais (`PasswordPolicy`).
 */
final class PolitiqueMotDePasseTest extends SecuriteApiTestCase
{
    public function testUnCompteExploitantAvecUnMotDePasseTropCourtEstRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/utilisateurs', [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => $entete['headers'] + ['Content-Type' => 'application/ld+json'],
            'json' => ['email' => 'mdp.court@itcotation.com', 'nom' => 'Mot de passe court', 'motDePasseClair' => 'aaa'],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertNull(
            static::getContainer()->get('doctrine')->getManager()->getRepository(Utilisateur::class)->findOneBy(['email' => 'mdp.court@itcotation.com']),
            'aucun compte ne doit exister',
        );
    }

    /** Ce que la règle ÉPARGNE : douze caractères passent — sans ce témoin, une règle qui refuserait tout passerait le test précédent. */
    public function testDouzeCaracteresPassent(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/utilisateurs', [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => $entete['headers'] + ['Content-Type' => 'application/ld+json'],
            'json' => ['email' => 'mdp.long@itcotation.com', 'nom' => 'Mot de passe long', 'motDePasseClair' => 'Douze#Caract'],
        ]);

        self::assertResponseStatusCodeSame(201, (string) $client->getResponse()->getContent(false));
    }
}
