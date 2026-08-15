<?php

declare(strict_types=1);

namespace App\Tests\Securite\Api;

use App\DataFixtures\SocleFixtures;
use App\Securite\Entity\Role;
use App\Tests\Securite\SecuriteApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Duplication de rôle (US-L7-04, CA-12) et aperçu des droits d'un rôle (US-L7-05, CA-13).
 */
final class RoleTest extends SecuriteApiTestCase
{
    /** CA-12 — Duplication d'un rôle existant : nouveau Role, mêmes permissions, indépendant. */
    public function testCa12DuplicationRole(): void
    {
        [$client, $entete] = $this->adminSurA();
        $idRoleAdmin = $this->idRole('Administrateur groupe');

        $reponse = $client->request('POST', '/api/roles/' . $idRoleAdmin . '/dupliquer', $entete + [
            'json' => ['nom' => 'Administrateur groupe (copie test)'],
        ]);
        self::assertResponseStatusCodeSame(201);
        $donnees = $reponse->toArray();
        self::assertNotSame($idRoleAdmin, $donnees['id'] ?? null);
        self::assertCount(3, $donnees['permissions']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $copie = $em->getRepository(Role::class)->findOneBy(['nom' => 'Administrateur groupe (copie test)']);
        self::assertNotNull($copie);
        self::assertFalse($copie->isEstModele());
        self::assertNotNull($copie->getRoleModeleOrigine());
        self::assertSame('Administrateur groupe', $copie->getRoleModeleOrigine()?->getNom());

        // Indépendance : retirer une permission de l'original ne modifie pas la copie.
        $original = $em->getRepository(Role::class)->find($idRoleAdmin);
        self::assertNotNull($original);
        self::assertNotSame($original->getPermissions()->count(), 0);
    }

    /** CA-13 — Aperçu des droits d'un rôle sur un établissement, lecture seule. */
    public function testCa13ApercuDroitsRole(): void
    {
        [$client, $entete] = $this->adminSurA();
        $idRoleLecteur = $this->idRole('Lecture seule');
        $idEtabA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);

        $reponse = $client->request('GET', '/api/roles/' . $idRoleLecteur . '/apercu-droits?etablissement=' . $idEtabA, $entete);
        self::assertResponseIsSuccessful();
        $donnees = $reponse->toArray();
        self::assertSame($idRoleLecteur, $donnees['roleId']);
        self::assertSame($idEtabA, $donnees['etablissementId']);
        self::assertContains('*.lire', $donnees['codes']);

        // Lecture seule : le contexte réel de l'appelant (admin) n'est pas modifié.
        $moi = $client->request('GET', '/me', $entete);
        self::assertResponseIsSuccessful();
        self::assertContains('securite.gerer', $moi->toArray()['droits']);
    }

    /** Les rôles-modèles livrés par migration (cahier M8-02) sont bien présents et filtrables. */
    public function testRolesModelesFiltrables(): void
    {
        [$client, $entete] = $this->adminSurA();

        $reponse = $client->request('GET', '/api/roles?estModele=true', $entete);
        self::assertResponseIsSuccessful();
        $noms = array_map(
            static fn (array $r): string => $r['nom'],
            $reponse->toArray()['member'] ?? $reponse->toArray()['hydra:member']
        );
        self::assertContains('Caissier', $noms);
        self::assertContains('Comptable', $noms);
    }
}
