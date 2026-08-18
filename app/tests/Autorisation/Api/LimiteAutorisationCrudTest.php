<?php

declare(strict_types=1);

namespace App\Tests\Autorisation\Api;

use App\Autorisation\Entity\LimiteAutorisation;
use App\Autorisation\Entity\OperationSensible;
use App\Autorisation\Enum\PerimetreAutorisation;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Tests\Autorisation\AutorisationApiTestCase;

/**
 * `LimiteAutorisationProcessor` — cible exclusive role XOR utilisateur (RG-AUTZ-02), garde doublon
 * (opération, cible, établissement), garde établissement soumis réellement géré par l'auteur
 * (§4.1 plan), cloisonnement multi-établissement en lecture (RG-SOCLE-05).
 */
final class LimiteAutorisationCrudTest extends AutorisationApiTestCase
{
    public function testCibleExclusiveRefuseSiRoleEtUtilisateur(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->request('POST', '/api/limite_autorisations', $entete + [
            'json' => [
                'operation' => '/api/operation_sensibles/vente.annuler',
                'role' => '/api/roles/' . $this->roleCaissier()->getId(),
                'utilisateur' => '/api/utilisateurs/' . $this->idAdmin(),
                'etablissement' => '/api/etablissements/' . $this->idEtablissement(SocleFixtures::ETAB_A_NOM),
                'plafondMontant' => '100.00',
                'perimetre' => 'global',
                'escaladeAuDela' => false,
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testCibleExclusiveRefuseSiAucuneCible(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->request('POST', '/api/limite_autorisations', $entete + [
            'json' => [
                'operation' => '/api/operation_sensibles/vente.annuler',
                'etablissement' => '/api/etablissements/' . $this->idEtablissement(SocleFixtures::ETAB_A_NOM),
                'plafondMontant' => '100.00',
                'perimetre' => 'global',
                'escaladeAuDela' => false,
            ],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testDoublonMemeOperationCibleEtablissementRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $corps = [
            'operation' => '/api/operation_sensibles/vente.annuler',
            'role' => '/api/roles/' . $this->roleCaissier()->getId(),
            'etablissement' => '/api/etablissements/' . $this->idEtablissement(SocleFixtures::ETAB_A_NOM),
            'plafondMontant' => '100.00',
            'perimetre' => 'global',
            'escaladeAuDela' => false,
        ];
        $client->request('POST', '/api/limite_autorisations', $entete + ['json' => $corps]);
        self::assertResponseStatusCodeSame(201);

        $client->request('POST', '/api/limite_autorisations', $entete + ['json' => $corps]);
        self::assertResponseStatusCodeSame(422);

        self::assertSame(1, $this->em()->getRepository(LimiteAutorisation::class)->count([]));
    }

    /** Un titulaire de autorisation.gerer sur A UNIQUEMENT ne peut pas configurer de limite sur B (§4.1 plan, garde établissement soumis). */
    public function testEtablissementSoumisNonGereParAuteurRefuse(): void
    {
        $roleGererA = $this->roleGererAutorisationSurA();
        [$client, $entete] = $this->connecte('gestionnaire-a@test.itcotation.com', role: $roleGererA);

        // Visibilité (cloisonnement socle, PerimetreEtablissementExtension) : une simple affectation
        // sans autorisation.gerer sur B rend l'établissement B référençable par IRI, mais insuffisante
        // pour y configurer une limite (c'est justement ce que ce test vérifie).
        $etabB = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM]);
        $utilisateur = $this->em()->getRepository(Utilisateur::class)->findOneBy(['email' => 'gestionnaire-a@test.itcotation.com']);
        self::assertInstanceOf(Utilisateur::class, $utilisateur);
        $this->em()->persist((new Affectation())->setUtilisateur($utilisateur)->setRole($this->roleCaissier())->setEtablissement($etabB));
        $this->em()->flush();

        $idEtabB = (string) $etabB->getId();
        $client->request('POST', '/api/limite_autorisations', $entete + [
            'json' => [
                'operation' => '/api/operation_sensibles/vente.annuler',
                'role' => '/api/roles/' . $this->roleCaissier()->getId(),
                'etablissement' => '/api/etablissements/' . $idEtabB,
                'plafondMontant' => '100.00',
                'perimetre' => 'global',
                'escaladeAuDela' => false,
            ],
        ]);
        self::assertResponseStatusCodeSame(403);

        self::assertSame(0, $this->em()->getRepository(LimiteAutorisation::class)->count([]));
    }

    /** Cloisonnement (RG-SOCLE-05) : un utilisateur sans affectation sur l'établissement B ne voit pas une LimiteAutorisation qui y est configurée. */
    public function testCloisonnementEtablissementEnLecture(): void
    {
        $em = $this->em();
        $etabB = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM]);
        $operation = $em->getRepository(OperationSensible::class)->find('vente.annuler');
        self::assertInstanceOf(OperationSensible::class, $operation);
        $admin = $this->entite(Utilisateur::class, ['email' => SocleFixtures::ADMIN_EMAIL]);

        $limiteB = (new LimiteAutorisation())
            ->setOperation($operation)
            ->setRole($this->roleCaissier())
            ->setEtablissement($etabB)
            ->setPlafondMontant('100.00')
            ->setPerimetre(PerimetreAutorisation::Global)
            ->setEscaladeAuDela(false)
            ->setAuteur($admin);
        $em->persist($limiteB);
        $em->flush();

        // Caissier affecté uniquement sur A + autorisation.lire (mais pas d'affectation sur B).
        $roleLireA = $this->roleLireAutorisationSurA();
        [$client, $entete] = $this->connecte('lecteur-autz-a@test.itcotation.com', role: $roleLireA);

        $collection = $client->request('GET', '/api/limite_autorisations', $entete)->toArray();
        $ids = array_column($collection['member'] ?? $collection['hydra:member'] ?? [], 'id');
        self::assertNotContains((string) $limiteB->getId(), $ids);

        $client->request('GET', '/api/limite_autorisations/' . $limiteB->getId(), $entete);
        self::assertResponseStatusCodeSame(404);
    }

    private function roleGererAutorisationSurA(): Role
    {
        $em = $this->em();
        $role = $em->getRepository(Role::class)->findOneBy(['nom' => 'Gestionnaire Autz A Test']);
        if ($role instanceof Role) {
            return $role;
        }

        $permission = $em->getRepository(Permission::class)->findOneBy(['module' => 'autorisation', 'action' => 'gerer']);
        $role = (new Role())->setNom('Gestionnaire Autz A Test');
        if ($permission instanceof Permission) {
            $role->addPermission($permission);
        }
        $em->persist($role);
        $em->flush();

        return $role;
    }

    private function roleLireAutorisationSurA(): Role
    {
        $em = $this->em();
        $role = $em->getRepository(Role::class)->findOneBy(['nom' => 'Lecteur Autz A Test']);
        if ($role instanceof Role) {
            return $role;
        }

        $permission = $em->getRepository(Permission::class)->findOneBy(['module' => 'autorisation', 'action' => 'lire']);
        $role = (new Role())->setNom('Lecteur Autz A Test');
        if ($permission instanceof Permission) {
            $role->addPermission($permission);
        }
        $em->persist($role);
        $em->flush();

        return $role;
    }
}
