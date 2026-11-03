<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Tests\Boutique\BoutiqueApiTestCase;

/**
 * LE RÔLE « CLIENT FINAL » EXISTAIT, ET IL ÉTAIT VIDE.
 *
 * `CreationCompteHandler::roleClientFinal()` se disait **idempotente**. Elle ne l'était que sur le
 * **contenant** : `findOneBy` trouvait le rôle, la méthode le renvoyait tel quel, et les douze
 * permissions n'étaient posées **qu'à la création**. Un rôle présent mais vide n'était donc jamais
 * réparé — et **aucune ré-exécution ne pouvait le réparer**, puisque chaque création de compte
 * repassait par le même retour anticipé.
 *
 * ⚠ **Constaté en préproduction le 07/09, pas imaginé** : rôle « Client final » à **zéro
 * permission**, deux comptes clients le portant. `CompteClient` exige `boutique.lire_soi` sur ses
 * commandes et ses billets — l'espace client rendait donc « Access Denied » à un client dont les
 * identifiants étaient pourtant bons. Le message ne dit rien de la cause, et le compte a l'air sain.
 *
 * ⚠ **Le mot « idempotent » est ce qui a coûté le plus cher.** Il décrivait une intention, pas le
 * code : un docblock qui annonce une garantie que la méthode n'offre pas est pire qu'un docblock
 * absent, parce qu'il éteint la question chez le lecteur suivant.
 */
final class RoleClientFinalIdempotentTest extends BoutiqueApiTestCase
{
    private const NOM_ROLE = 'Client final';

    /**
     * **Le test qui compte**, et il porte sur l'état exact de la préprod : rôle **présent et vide**.
     *
     * Un test qui se contenterait de créer un compte sur une base neuve serait vert avec l'ancien
     * code — le rôle n'existant pas encore, la branche de création posait bien les permissions.
     * C'est pour ça que le rôle est vidé **avant** l'appel : c'est le seul état qui distingue les
     * deux versions.
     */
    public function testUnRoleClientFinalPresentMaisVideEstReconcilie(): void
    {
        $role = $this->roleVide();

        // Témoin de précondition : sans lui, un rôle déjà pourvu rendrait ce test vert sans
        // rien mesurer.
        self::assertCount(0, $role->getPermissions(), 'Précondition : le rôle doit être vide.');

        $this->creerUnCompteClient('vide@essai.test');

        $relu = $this->relireRole();
        $actions = $this->actionsDe($relu);

        self::assertContains(
            'boutique.lire_soi',
            $actions,
            "Sans `boutique.lire_soi`, l'espace client rend « Access Denied » sur ses commandes et ses billets.",
        );
        self::assertContains('boutique.acheter_soi', $actions);
        self::assertContains('crm.lire_soi', $actions);
        self::assertContains('reservation.reserver_soi', $actions);
        self::assertCount(11, $actions, 'Les onze permissions du bundle doivent être posées.');
    }

    /**
     * **ON AJOUTE, ON NE RETIRE PAS.**
     *
     * La liste du handler est un **plancher**, pas une définition exhaustive : un exploitant peut
     * avoir enrichi le rôle à la main. Une réconciliation qui alignerait strictement amputerait ce
     * rôle à la première création de compte — un retrait de droits que personne n'a demandé, et que
     * rien n'annoncerait.
     */
    public function testLaReconciliationNAmputePasUnRoleEnrichi(): void
    {
        $role = $this->roleVide();
        $enPlus = $this->permission('reporting', 'lire');
        $role->addPermission($enPlus);
        $this->em()->flush();

        $this->creerUnCompteClient('enrichi@essai.test');

        $actions = $this->actionsDe($this->relireRole());

        self::assertContains('reporting.lire', $actions, 'Une permission ajoutée à la main a été retirée.');
        self::assertContains('boutique.lire_soi', $actions);
        self::assertCount(12, $actions, 'Les onze du bundle, plus celle ajoutée à la main.');
    }

    /** Le rôle dans l'état où la préproduction l'a montré : présent, sans aucune permission. */
    private function roleVide(): Role
    {
        $em = $this->em();
        $role = $em->getRepository(Role::class)->findOneBy(['nom' => self::NOM_ROLE]);

        if (!$role instanceof Role) {
            $role = (new Role())->setNom(self::NOM_ROLE)->setEstModele(false);
            $em->persist($role);
        }

        foreach ($role->getPermissions()->toArray() as $permission) {
            $role->removePermission($permission);
        }
        $em->flush();

        return $role;
    }

    private function relireRole(): Role
    {
        $em = $this->em();
        $em->clear();

        $role = $em->getRepository(Role::class)->findOneBy(['nom' => self::NOM_ROLE]);
        self::assertInstanceOf(Role::class, $role, 'Le rôle a disparu.');

        return $role;
    }

    private function permission(string $module, string $action): Permission
    {
        $em = $this->em();
        $existante = $em->getRepository(Permission::class)->findOneBy(['module' => $module, 'action' => $action]);
        if ($existante instanceof Permission) {
            return $existante;
        }

        $permission = (new Permission())->setModule($module)->setAction($action);
        $em->persist($permission);

        return $permission;
    }

    /** @return list<string> `module.action`, trié — comparable d'un appel à l'autre. */
    private function actionsDe(Role $role): array
    {
        $actions = array_map(
            static fn (Permission $p): string => $p->getModule() . '.' . $p->getAction(),
            $role->getPermissions()->toArray(),
        );
        $actions = array_values(array_unique($actions));
        sort($actions);

        return $actions;
    }

    private function creerUnCompteClient(string $email): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/boutique/comptes', [
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/ld+json'],
            'json' => [
                'vitrine' => '/api/boutique/vitrines/' . $this->idVitrineA(),
                'email' => $email,
                'motDePasse' => 'MotDePasse!2026',
                'nom' => 'Essai',
                'prenom' => 'Compte',
                'dateNaissance' => '1990-01-01',
            ],
        ]);

        self::assertResponseIsSuccessful('La création de compte doit aboutir : sans elle, le reste ne mesure rien.');
    }
}
