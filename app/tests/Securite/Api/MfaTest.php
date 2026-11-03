<?php

declare(strict_types=1);

namespace App\Tests\Securite\Api;

use App\DataFixtures\SocleFixtures;
use App\Securite\Service\GenerateurTotp;
use App\Tests\Securite\SecuriteApiTestCase;

/**
 * MFA TOTP (RG-M8-06, US-L7-03) : activation en 2 temps, connexion à 2 étapes, garde rôle à
 * privilèges, réinitialisation admin, codes de récupération.
 */
final class MfaTest extends SecuriteApiTestCase
{
    /** CA-5 — Activation MFA (2 temps) : secret + codes affichés une fois ; connexion sans 2ᵉ facteur refusée. */
    public function testCa5ActivationMfaEtConnexionDeuxEtapes(): void
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);
        $idLecteur = $this->idUtilisateur(SocleFixtures::LECTEUR_EMAIL);

        $activation = $client->request('POST', '/api/utilisateurs/' . $idLecteur . '/mfa/activer', [
            'auth_bearer' => $token,
        ]);
        self::assertResponseIsSuccessful();
        $donnees = $activation->toArray();
        self::assertArrayHasKey('secret', $donnees);
        self::assertArrayHasKey('uriProvisionnement', $donnees);
        self::assertCount(8, $donnees['codesRecuperation']);

        $secret = $donnees['secret'];

        /** @var GenerateurTotp $totp */
        $totp = static::getContainer()->get(GenerateurTotp::class);
        $code = $totp->codeActuel($secret);

        $client->request('POST', '/api/utilisateurs/' . $idLecteur . '/mfa/confirmer', [
            'auth_bearer' => $token,
            'json' => ['code' => $code],
        ]);
        self::assertResponseIsSuccessful();

        // Connexion : email+mdp seuls ⇒ pas de jeton complet, un jeton pré-auth est renvoyé.
        $connexion = $client->request('POST', '/auth', [
            'json' => ['email' => SocleFixtures::LECTEUR_EMAIL, 'motDePasse' => SocleFixtures::LECTEUR_MDP],
        ]);
        self::assertResponseIsSuccessful();
        $reponseConnexion = $connexion->toArray();
        self::assertTrue($reponseConnexion['mfaRequis'] ?? false);
        $jetonPreAuth = $reponseConnexion['jetonPreAuth'];

        // Le jeton pré-auth ne donne PAS accès aux routes protégées classiques.
        $client->request('GET', '/me', ['auth_bearer' => $jetonPreAuth]);
        self::assertResponseStatusCodeSame(401);

        // Code invalide au 2ᵉ facteur ⇒ refus (CA-5).
        $refus = $client->request('POST', '/auth/mfa-verifier', [
            'auth_bearer' => $jetonPreAuth,
            'json' => ['code' => '000000'],
        ]);
        self::assertResponseStatusCodeSame(401);
        // Le refus porte un code que l'écran traduit (`error.auth.mfa_invalid_code`), et garde son
        // message français pour qui ne le connaît pas.
        $corpsRefus = $refus->toArray(false);
        self::assertSame('auth.mfa_invalid_code', $corpsRefus['code']);
        self::assertSame('Code invalide.', $corpsRefus['message']);

        // Code TOTP valide ⇒ jeton complet.
        $codeValide = $totp->codeActuel($secret);
        $verification = $client->request('POST', '/auth/mfa-verifier', [
            'auth_bearer' => $jetonPreAuth,
            'json' => ['code' => $codeValide],
        ]);
        self::assertResponseIsSuccessful();
        $jetonComplet = $verification->toArray()['token'];

        $client->request('GET', '/me', ['auth_bearer' => $jetonComplet]);
        self::assertResponseIsSuccessful();
    }

    /**
     * CA-4 — LA GARDE MFA SUR L'AFFECTATION EST SUSPENDUE, ET CE TEST DIT DANS QUEL SENS.
     *
     * Il affirmait l'inverse : un rôle à privilèges sans MFA rendait 422. La règle était bonne et
     * impossible à satisfaire — aucun écran n'active le MFA, donc **on ne pouvait nommer aucun
     * administrateur, chez aucun client**. Mesuré le 31/08 par allaccess-b8 : 6 rôles à privilèges,
     * 0 utilisateur avec MFA actif.
     *
     * **Décision de Maxime du 31/08** entre trois voies : lever maintenant, construire le parcours
     * MFA ensuite. Voir `AffectationProcessor::MFA_EXIGE_POUR_ROLE_A_PRIVILEGES`, qui porte la
     * règle, sa date et sa condition de rétablissement.
     *
     * ⚠ **CE TEST EST CELUI QU'IL FAUDRA RETOURNER LE JOUR DU RÉTABLISSEMENT** : remettre `true` à
     * la constante et rendre à la première assertion son 422. Il est écrit pour que ce soit une
     * ligne, pas une enquête.
     *
     * ⚠ **ET IL VÉRIFIE QU'ON A LEVÉ LA PRÉCONDITION, PAS LA GARDE ENTIÈRE.** La seconde moitié —
     * avec MFA actif, l'affectation passe — est conservée telle quelle : un correctif qui aurait
     * supprimé tout le bloc serait invisible à la première assertion seule.
     */
    public function testCa4GardeMfaSuspendueEnAttenteDUnEcran(): void
    {
        [$client, $entete] = $this->adminSurA();
        $idLecteur = $this->idUtilisateur(SocleFixtures::LECTEUR_EMAIL);
        $idRoleAdmin = $this->idRole('Administrateur groupe');
        $idEtabA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);

        $client->request('POST', '/api/affectations', $entete + [
            'json' => [
                'utilisateur' => '/api/utilisateurs/' . $idLecteur,
                'role' => '/api/roles/' . $idRoleAdmin,
                'etablissement' => '/api/etablissements/' . $idEtabA,
            ],
        ]);
        self::assertResponseStatusCodeSame(
            201,
            'Un rôle à privilèges reste refusé faute de MFA : la garde est censée être suspendue, '
            .'et sans cela on ne peut nommer aucun administrateur chez aucun client.',
        );

        // ⚠ ON DÉFAIT L'AFFECTATION POUR QUE LA SUITE MESURE CE QU'ELLE PRÉTEND. Sans cela, la
        // seconde tentative porterait sur un couple déjà affecté, et son succès ne dirait rien du
        // MFA — `Affectation` porte d'ailleurs une contrainte d'unicité sur (utilisateur, rôle,
        // établissement).
        $idAffectation = $client->getResponse()->toArray()['id'];
        $client->request('DELETE', '/api/affectations/' . $idAffectation, $entete);
        self::assertResponseStatusCodeSame(204, 'témoin : l’affectation doit pouvoir être défaite avant la seconde mesure.');

        // Active le MFA du lecteur (self-service, jeton propre).
        $tokenLecteur = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);
        $activation = $client->request('POST', '/api/utilisateurs/' . $idLecteur . '/mfa/activer', ['auth_bearer' => $tokenLecteur]);
        $secret = $activation->toArray()['secret'];
        /** @var GenerateurTotp $totp */
        $totp = static::getContainer()->get(GenerateurTotp::class);
        $client->request('POST', '/api/utilisateurs/' . $idLecteur . '/mfa/confirmer', [
            'auth_bearer' => $tokenLecteur,
            'json' => ['code' => $totp->codeActuel($secret)],
        ]);
        self::assertResponseIsSuccessful();

        // Nouvelle tentative d'affectation : autorisée désormais.
        $client->request('POST', '/api/affectations', $entete + [
            'json' => [
                'utilisateur' => '/api/utilisateurs/' . $idLecteur,
                'role' => '/api/roles/' . $idRoleAdmin,
                'etablissement' => '/api/etablissements/' . $idEtabA,
            ],
        ]);
        self::assertResponseStatusCodeSame(201);
    }

    /** Cas limite spec §7 — codes de récupération épuisés + appareil perdu ⇒ réinitialisation admin (tracée avant/après). */
    public function testReinitialisationMfaParAdministrateur(): void
    {
        // Un seul client tout au long du test (cf. CA-3 : les helpers d'assertion statiques
        // portent sur le dernier client CRÉÉ, pas le dernier utilisé).
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);
        $idLecteur = $this->idUtilisateur(SocleFixtures::LECTEUR_EMAIL);

        $activation = $client->request('POST', '/api/utilisateurs/' . $idLecteur . '/mfa/activer', ['auth_bearer' => $token]);
        $secret = $activation->toArray()['secret'];
        /** @var GenerateurTotp $totp */
        $totp = static::getContainer()->get(GenerateurTotp::class);
        $client->request('POST', '/api/utilisateurs/' . $idLecteur . '/mfa/confirmer', [
            'auth_bearer' => $token,
            'json' => ['code' => $totp->codeActuel($secret)],
        ]);
        self::assertResponseIsSuccessful();

        $tokenAdmin = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);
        $idEtabA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $entete = ['auth_bearer' => $tokenAdmin, 'headers' => [\App\Securite\Service\ContexteEtablissement::HEADER => $idEtabA]];
        $client->request('POST', '/api/utilisateurs/' . $idLecteur . '/mfa/reinitialiser', $entete);
        self::assertResponseIsSuccessful();

        // Traçabilité avant/après (RG-M8-05, CA-14).
        $audits = $client->request('GET', '/api/entree_audits', $entete + [
            'query' => ['action' => 'mfa.reinitialise'],
        ]);
        self::assertResponseIsSuccessful();
        $membres = $audits->toArray()['member'] ?? $audits->toArray()['hydra:member'];
        self::assertNotEmpty($membres);
        self::assertTrue($membres[0]['valeurAvant']['mfaActif'] ?? null);
        self::assertFalse($membres[0]['valeurApres']['mfaActif'] ?? true);
    }
}
