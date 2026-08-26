<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Vente\VenteApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Non-régression du cloisonnement (D3/D8) de `POST /nf525/verifier-chaine` (`VerifierChaineProcessor`).
 * L'opération est `input: false` : le `pointDeVente` est lu au corps et résolu par un `find()`, hors des
 * extensions Doctrine. Un agent `caisse.lire` sur B ne doit pas pouvoir sonder l'intégrité de la chaîne
 * NF525 (signal de conformité fiscale) d'un point de vente de A → 404 (jamais le rapport de chaîne).
 */
final class VerifierChaineCloisonnementTest extends VenteApiTestCase
{
    public function testVerifierChaineDunPdvDunAutreEtablissementRenvoie404(): void
    {
        // Le PDV fixture (VenteFixtures) est rattaché à l'établissement A.
        $idPdvA = $this->idPointDeVente();

        [$emailB, $mdpB] = $this->creerLecteurCaisseSurB();
        $clientB = static::createClient();
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        $enteteB = ['auth_bearer' => $this->jeton($clientB, $emailB, $mdpB), 'headers' => [ContexteEtablissement::HEADER => $idB]];

        $reponse = $clientB->request('POST', '/api/nf525/verifier-chaine', $enteteB + [
            'json' => ['pointDeVente' => '/api/point_de_ventes/' . $idPdvA],
        ]);

        self::assertSame(404, $reponse->getStatusCode(), (string) $reponse->getContent(false));
    }

    /** Contrôle positif — l'admin (caisse.lire sur A) vérifie bien la chaîne du PDV de A (200/409, jamais 404). */
    public function testVerifierChaineDeSonPdvNestPasRefusee(): void
    {
        [$client, $entete] = $this->adminSurA();

        $reponse = $client->request('POST', '/api/nf525/verifier-chaine', $entete + [
            'json' => ['pointDeVente' => '/api/point_de_ventes/' . $this->idPointDeVente()],
        ]);

        self::assertNotSame(404, $reponse->getStatusCode(), (string) $reponse->getContent(false));
        self::assertContains($reponse->getStatusCode(), [200, 409], (string) $reponse->getContent(false));
    }

    /** @return array{0: string, 1: string} email, mot de passe d'un lecteur caisse.lire affecté à B seul. */
    private function creerLecteurCaisseSurB(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $etabB = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertInstanceOf(Etablissement::class, $etabB);
        $permLire = $em->getRepository(Permission::class)->findOneBy(['module' => 'caisse', 'action' => 'lire']);
        self::assertInstanceOf(Permission::class, $permLire, 'La permission caisse.lire doit etre semee.');

        $role = (new Role())->setNom('Lecteur caisse B (test)');
        $role->addPermission($permLire);
        $em->persist($role);

        $email = 'caisse-b-' . uniqid() . '@itcotation.com';
        $mdp = 'aaa';
        $user = (new Utilisateur())->setEmail($email)->setNom('Lecteur Caisse B')->setActif(true);
        $user->setMotDePasse($hasher->hashPassword($user, $mdp));
        $em->persist($user);

        $em->persist((new Affectation())->setUtilisateur($user)->setRole($role)->setEtablissement($etabB));
        $em->flush();

        return [$email, $mdp];
    }
}
