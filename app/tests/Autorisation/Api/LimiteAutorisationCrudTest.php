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

    /** Défaut 1 (revue de cohérence) : `auteur` transmis à la création est ignoré (lecture seule, hors `limite:write`) — l'API assigne l'appelant réel, jamais l'usurpation transmise. */
    public function testAuteurTransmisALaCreationEstIgnoreEtAppelantReelAssigne(): void
    {
        [$client, $entete] = $this->adminSurA();
        $victime = $this->creerUtilisateur('victime-auteur-post@test.itcotation.com', $this->roleCaissier());

        $client->request('POST', '/api/limite_autorisations', $entete + [
            'json' => [
                'operation' => '/api/operation_sensibles/vente.annuler',
                'role' => '/api/roles/' . $this->roleCaissier()->getId(),
                'etablissement' => '/api/etablissements/' . $this->idEtablissement(SocleFixtures::ETAB_A_NOM),
                'plafondMontant' => '100.00',
                'perimetre' => 'global',
                'escaladeAuDela' => false,
                'auteur' => '/api/utilisateurs/' . $victime->getId(),
            ],
        ]);
        self::assertResponseStatusCodeSame(201);

        $limite = $this->em()->getRepository(LimiteAutorisation::class)->findOneBy(['role' => $this->roleCaissier()]);
        self::assertInstanceOf(LimiteAutorisation::class, $limite);
        self::assertSame(SocleFixtures::ADMIN_EMAIL, $limite->getAuteur()?->getEmail(), 'auteur doit être l’appelant réel, jamais la valeur transmise dans le corps.');
        self::assertNotSame((string) $victime->getId(), (string) $limite->getAuteur()?->getId());
    }

    /** Défaut 1 (revue de cohérence) : `auteur` transmis au PATCH est ignoré — l'API réassigne l'appelant réel du PATCH, inconditionnellement. */
    public function testAuteurTransmisAuPatchEstIgnoreEtAppelantReelReassigne(): void
    {
        [$client, $entete] = $this->adminSurA();
        $victime = $this->creerUtilisateur('victime-auteur-patch@test.itcotation.com', $this->roleCaissier());

        $creation = $client->request('POST', '/api/limite_autorisations', $entete + [
            'json' => [
                'operation' => '/api/operation_sensibles/vente.annuler',
                'role' => '/api/roles/' . $this->roleCaissier()->getId(),
                'etablissement' => '/api/etablissements/' . $this->idEtablissement(SocleFixtures::ETAB_A_NOM),
                'plafondMontant' => '100.00',
                'perimetre' => 'global',
                'escaladeAuDela' => false,
            ],
        ])->toArray();
        self::assertResponseStatusCodeSame(201);

        $entetePatch = ['headers' => $entete['headers'] + ['Content-Type' => 'application/merge-patch+json']] + $entete;
        $client->request('PATCH', '/api/limite_autorisations/' . $creation['id'], $entetePatch + [
            'json' => ['plafondMontant' => '150.00', 'auteur' => '/api/utilisateurs/' . $victime->getId()],
        ]);
        self::assertResponseStatusCodeSame(200);

        $limite = $this->em()->getRepository(LimiteAutorisation::class)->find($creation['id']);
        self::assertInstanceOf(LimiteAutorisation::class, $limite);
        self::assertSame('150.00', $limite->getPlafondMontant());
        self::assertSame(SocleFixtures::ADMIN_EMAIL, $limite->getAuteur()?->getEmail(), 'auteur doit rester l’appelant réel du PATCH, jamais la valeur transmise.');
    }

    /** Défaut 2 (revue de cohérence) : une écriture sur LimiteAutorisation est tracée (AuditWriteSubscriber). */
    public function testEcritureLimiteAutorisationProduitUneEntreeAudit(): void
    {
        [$client, $entete] = $this->adminSurA();

        $creation = $client->request('POST', '/api/limite_autorisations', $entete + [
            'json' => [
                'operation' => '/api/operation_sensibles/vente.annuler',
                'role' => '/api/roles/' . $this->roleCaissier()->getId(),
                'etablissement' => '/api/etablissements/' . $this->idEtablissement(SocleFixtures::ETAB_A_NOM),
                'plafondMontant' => '100.00',
                'perimetre' => 'global',
                'escaladeAuDela' => false,
            ],
        ])->toArray();
        self::assertResponseStatusCodeSame(201);

        $entreeCreation = $this->em()->getRepository(\App\Audit\Entity\EntreeAudit::class)->findOneBy([
            'action' => 'creation',
            'cibleType' => LimiteAutorisation::class,
            'cibleId' => $creation['id'],
        ]);
        self::assertInstanceOf(\App\Audit\Entity\EntreeAudit::class, $entreeCreation, 'La création d’une LimiteAutorisation doit produire une EntreeAudit.');

        $entetePatch = ['headers' => $entete['headers'] + ['Content-Type' => 'application/merge-patch+json']] + $entete;
        $client->request('PATCH', '/api/limite_autorisations/' . $creation['id'], $entetePatch + [
            'json' => ['plafondMontant' => '200.00'],
        ]);
        self::assertResponseStatusCodeSame(200);

        $entreeModification = $this->em()->getRepository(\App\Audit\Entity\EntreeAudit::class)->findOneBy([
            'action' => 'modification',
            'cibleType' => LimiteAutorisation::class,
            'cibleId' => $creation['id'],
        ]);
        self::assertInstanceOf(\App\Audit\Entity\EntreeAudit::class, $entreeModification, 'La modification d’une LimiteAutorisation doit produire une EntreeAudit.');

        $client->request('DELETE', '/api/limite_autorisations/' . $creation['id'], $entete);
        self::assertResponseStatusCodeSame(204);

        $entreeSuppression = $this->em()->getRepository(\App\Audit\Entity\EntreeAudit::class)->findOneBy([
            'action' => 'suppression',
            'cibleType' => LimiteAutorisation::class,
            'cibleId' => $creation['id'],
        ]);
        self::assertInstanceOf(\App\Audit\Entity\EntreeAudit::class, $entreeSuppression, 'La suppression d’une LimiteAutorisation doit produire une EntreeAudit.');
    }

    /**
     * Défaut 2 (revue de cohérence) : une écriture sur OperationSensible (catalogue) est tracée.
     * NB : `OperationSensible` a `code` pour PK (pas de `getId()`, dérogation assumée §0 n°1 du plan
     * de l'entité) — `AuditWriteSubscriber::cibleId()` (mécanisme générique, hors périmètre de ce
     * correctif) ne capture donc pas `cibleId` pour cette entité ; on vérifie le comptage plutôt
     * qu'un `cibleId` précis.
     */
    public function testEcritureOperationSensibleProduitUneEntreeAudit(): void
    {
        [$client, $entete] = $this->adminSurA();

        $avant = $this->em()->getRepository(\App\Audit\Entity\EntreeAudit::class)->count([
            'action' => 'creation',
            'cibleType' => OperationSensible::class,
        ]);

        $client->request('POST', '/api/operation_sensibles', $entete + [
            'json' => [
                'code' => 'test.audit_operation',
                'libelle' => 'Test — audit opération sensible',
                'moduleAction' => 'test.audit_operation',
                'active' => true,
            ],
        ]);
        self::assertResponseStatusCodeSame(201);

        $apres = $this->em()->getRepository(\App\Audit\Entity\EntreeAudit::class)->count([
            'action' => 'creation',
            'cibleType' => OperationSensible::class,
        ]);
        self::assertGreaterThan($avant, $apres, 'La création d’une OperationSensible doit produire une EntreeAudit.');
    }

    /**
     * Défaut 3 (revue de cohérence, vérifié empiriquement — RÉEL, pas un faux positif comme
     * `App\OptionProduit`) : sans `src/Autorisation/Entity` dans `api_platform.yaml` `mapping.paths`,
     * l'export OpenAPI ne liste STRICTEMENT AUCUN paramètre pour aucune ressource `App\Autorisation`
     * (ni `role`/`operation`/`etablissement`, ni même `page` — la pagination standard elle-même
     * disparaît), et une requête filtrée (`?operation=...`) renvoie la collection complète non
     * restreinte au lieu d'être filtrée. Après ajout de la ligne au mapping, le filtre restreint
     * effectivement la collection (preuve fonctionnelle ci-dessous, indépendante de la doc OpenAPI).
     *
     * NB : le filtre est vérifié ici sur `operation` (association vers `OperationSensible`, dont la
     * PK `code` est une chaîne, RG-AUTZ-01) plutôt que sur `role`/`etablissement` (UUID) : ces
     * derniers se heurtent à une limitation distincte, préexistante et hors périmètre de ce correctif
     * — `ApiPlatform\Doctrine\Orm\Filter\SearchFilter::filterProperty()` résout la valeur d'une
     * association via `PropertyAccessor::getValue($item, $associationFieldIdentifier)` (objet
     * `Symfony\Component\Uid\Uuid` pour une PK UUID) puis `setParameter()` SANS préciser le type
     * Doctrine ; `Doctrine\ORM\Query\ParameterTypeInferer::inferType()` ne reconnaît pas `Uuid` et
     * retombe sur `ParameterType::STRING`, qui ne correspond jamais à la colonne `BINARY(16)` du type
     * `uuid` — le filtre associatif UUID ne matche donc jamais rien. Ce comportement est reproductible
     * à l'identique sur `role`/`etablissement` ici ET vraisemblablement sur toute association UUID
     * filtrée par `SearchFilter` ailleurs dans le dépôt (bug de plateforme transverse, à signaler
     * séparément — hors périmètre de ce lot Autorisation).
     */
    public function testFiltreOperationRestreintLaCollection(): void
    {
        [$client, $entete] = $this->adminSurA();
        $etabA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);

        $reponseAnnulation = $client->request('POST', '/api/limite_autorisations', $entete + [
            'json' => [
                'operation' => '/api/operation_sensibles/vente.annuler',
                'role' => '/api/roles/' . $this->roleCaissier()->getId(),
                'etablissement' => '/api/etablissements/' . $etabA,
                'plafondMontant' => '100.00',
                'perimetre' => 'global',
                'escaladeAuDela' => false,
            ],
        ])->toArray();
        self::assertResponseStatusCodeSame(201);

        $reponseRemboursement = $client->request('POST', '/api/limite_autorisations', $entete + [
            'json' => [
                'operation' => '/api/operation_sensibles/vente.rembourser',
                'role' => '/api/roles/' . $this->roleSuperviseur()->getId(),
                'etablissement' => '/api/etablissements/' . $etabA,
                'plafondMontant' => '200.00',
                'perimetre' => 'global',
                'escaladeAuDela' => false,
            ],
        ])->toArray();
        self::assertResponseStatusCodeSame(201);

        $collection = $client->request('GET', '/api/limite_autorisations?operation=' . urlencode('/api/operation_sensibles/vente.annuler'), $entete)->toArray();
        $membres = $collection['member'] ?? $collection['hydra:member'] ?? [];
        $ids = array_column($membres, 'id');

        self::assertContains($reponseAnnulation['id'], $ids, 'Le filtre operation= doit conserver la LimiteAutorisation de l’opération demandée.');
        self::assertNotContains($reponseRemboursement['id'], $ids, 'Le filtre operation= doit exclure les LimiteAutorisation des autres opérations (preuve que #[ApiFilter] est bien pris en compte).');
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
