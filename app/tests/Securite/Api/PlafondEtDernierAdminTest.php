<?php

declare(strict_types=1);

namespace App\Tests\Securite\Api;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\DataFixtures\L7Fixtures;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Securite\SecuriteApiTestCase;

/**
 * RG-M8-09 (plafond d'attribution, CA-10) et RG-M8-07 (dernier administrateur, CA-11).
 */
final class PlafondEtDernierAdminTest extends SecuriteApiTestCase
{
    /** CA-10 — Un administrateur d'établissement sans la permission X ne peut affecter un rôle qui la contient (403). */
    public function testCa10PlafondAttributionRefuse(): void
    {
        $client = static::createClient();
        $token = $this->jeton($client, L7Fixtures::RESP_A_EMAIL, L7Fixtures::RESP_A_MDP);
        $idEtabA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idEtabA]];

        $idLecteur = $this->idUtilisateur(SocleFixtures::LECTEUR_EMAIL);
        $idRoleTropPuissant = $this->idRole(L7Fixtures::ROLE_TROP_PUISSANT_NOM);

        $client->request('POST', '/api/affectations', $entete + [
            'json' => [
                'utilisateur' => '/api/utilisateurs/' . $idLecteur,
                'role' => '/api/roles/' . $idRoleTropPuissant,
                'etablissement' => '/api/etablissements/' . $idEtabA,
            ],
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    /** CA-10 (contrôle) — Un administrateur peut affecter un rôle dont il possède déjà tous les droits. */
    public function testPlafondAttributionAutorisePourSousEnsemble(): void
    {
        $client = static::createClient();
        $token = $this->jeton($client, L7Fixtures::RESP_A_EMAIL, L7Fixtures::RESP_A_MDP);
        $idEtabA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $entete = ['auth_bearer' => $token, 'headers' => [ContexteEtablissement::HEADER => $idEtabA]];

        $idLecteur = $this->idUtilisateur(SocleFixtures::LECTEUR_EMAIL);
        // Rôle NON à privilèges (pas de permission `securite.*`) : ne déclenche pas la garde MFA
        // (CA-4), isole le test sur le seul plafond RG-M8-09 (`organisation.gerer` ⊆ droits de respA).
        $idRoleSousEnsemble = $this->idRole(L7Fixtures::ROLE_DELEGATION_NOM);

        $client->request('POST', '/api/affectations', $entete + [
            'json' => [
                'utilisateur' => '/api/utilisateurs/' . $idLecteur,
                'role' => '/api/roles/' . $idRoleSousEnsemble,
                'etablissement' => '/api/etablissements/' . $idEtabA,
            ],
        ]);
        self::assertResponseStatusCodeSame(201);
    }

    /** CA-11 — Suppression de la dernière affectation « administrateur » d'un établissement ⇒ 422. */
    public function testCa11SuppressionDerniereAffectationAdministrateurRefusee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $etabB = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM]);
        $admin = $this->entite(Utilisateur::class, ['email' => SocleFixtures::ADMIN_EMAIL]);
        $roleAdmin = $this->entite(Role::class, ['nom' => 'Administrateur groupe']);

        $affectation = $this->entite(Affectation::class, [
            'utilisateur' => $admin, 'role' => $roleAdmin, 'etablissement' => $etabB,
        ]);

        $client->request('DELETE', '/api/affectations/' . $affectation->getId(), $entete);
        self::assertResponseStatusCodeSame(422);
    }

    /** CA-11 (contrôle) — Suppression d'une affectation admin qui N'EST PAS la dernière est autorisée. */
    public function testSuppressionAffectationAdministrateurNonDerniereAutorisee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $etabA = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);
        $admin2 = $this->entite(Utilisateur::class, ['email' => L7Fixtures::ADMIN2_EMAIL]);
        $roleAdmin = $this->entite(Role::class, ['nom' => 'Administrateur groupe']);

        $affectation = $this->entite(Affectation::class, [
            'utilisateur' => $admin2, 'role' => $roleAdmin, 'etablissement' => $etabA,
        ]);

        // etabA a 2 administrateurs (admin socle + admin2) : retirer admin2 est autorisé.
        $client->request('DELETE', '/api/affectations/' . $affectation->getId(), $entete);
        self::assertResponseStatusCodeSame(204);
    }
}
