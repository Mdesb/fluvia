<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Acces\AccesApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Non-régression du cloisonnement (D3/D8) des deux endpoints `input: false` qui résolvent une entité
 * depuis le corps par un `find()` direct, hors des extensions :
 *   - POST /acces/passages/non-nominatif → `PassageNonNominatifProcessor` (équipement) ;
 *   - POST /acces/synchro                → `SynchroProcessor` (contrôleur).
 * Un agent habilité sur B ne doit pouvoir ni comptabiliser un passage sur un équipement de A, ni
 * remonter un lot hors-ligne sur un contrôleur de A → 404 (anti-oracle).
 */
final class NonNominatifSynchroCloisonnementTest extends AccesApiTestCase
{
    public function testComptageNonNominatifSurEquipementDunAutreEtablissementRenvoie404(): void
    {
        $idEquipementA = $this->idEquipement();
        [$client, $entete] = $this->agentAccesSurB();

        $reponse = $client->request('POST', '/api/acces/passages/non-nominatif', $entete + [
            'json' => ['equipement' => '/api/equipements/' . $idEquipementA, 'motif' => 'Comptage test'],
        ]);

        self::assertSame(404, $reponse->getStatusCode(), (string) $reponse->getContent(false));
    }

    public function testSynchroSurControleurDunAutreEtablissementRenvoie404(): void
    {
        $idControleurA = $this->idControleur();
        [$client, $entete] = $this->agentAccesSurB();

        $reponse = $client->request('POST', '/api/acces/synchro', $entete + [
            'json' => ['controleur' => '/api/controleurs/' . $idControleurA, 'lot' => []],
        ]);

        self::assertSame(404, $reponse->getStatusCode(), (string) $reponse->getContent(false));
    }

    /**
     * @return array{0: \ApiPlatform\Symfony\Bundle\Test\Client, 1: array<string, mixed>}
     *         client + entête d'un agent portant acces.superviser/controler/ingestion sur B SEUL.
     */
    private function agentAccesSurB(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $etabB = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertInstanceOf(Etablissement::class, $etabB);

        $role = (new Role())->setNom('Acces agent B (test) ' . uniqid());
        foreach (['superviser', 'controler', 'ingestion'] as $action) {
            $role->addPermission($this->permissionAcces($em, $action));
        }
        $em->persist($role);

        $email = 'acces-b-' . uniqid() . '@itcotation.com';
        $motDePasse = 'aaa';
        $user = (new Utilisateur())->setEmail($email)->setNom('Agent Acces B')->setActif(true);
        $user->setMotDePasse($hasher->hashPassword($user, $motDePasse));
        $em->persist($user);
        $em->persist((new Affectation())->setUtilisateur($user)->setRole($role)->setEtablissement($etabB));
        $em->flush();

        $client = static::createClient();
        $entete = [
            'auth_bearer' => $this->jeton($client, $email, $motDePasse),
            'headers' => [ContexteEtablissement::HEADER => (string) $etabB->getId()],
        ];

        return [$client, $entete];
    }

    private function permissionAcces(EntityManagerInterface $em, string $action): Permission
    {
        $permission = $em->getRepository(Permission::class)->findOneBy(['module' => 'acces', 'action' => $action]);
        if (!$permission instanceof Permission) {
            $permission = (new Permission())->setModule('acces')->setAction($action);
            $em->persist($permission);
        }

        return $permission;
    }
}
