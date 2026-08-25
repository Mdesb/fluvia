<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Compta\Entity\VenteImpayeeRegie;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Non-régression du cloisonnement (D3/D8) de `POST /compta/ventes/{id}/marquer-impayee-regie`
 * (`MarquerImpayeeRegieProcessor`). L'{id} d'URI est fourni par le client : sans confrontation de
 * l'établissement de la vente au périmètre de l'appelant, un agent porteur de `compta.gerer` sur un
 * établissement pouvait marquer « impayée régie » la vente d'un AUTRE établissement — écriture
 * comptable cross-tenant. Échec fermé en 404 (jamais 403 : ne pas confirmer l'existence de la vente).
 */
final class MarquerImpayeeRegieCloisonnementTest extends ComptaApiTestCase
{
    public function testMarquerLaVenteDunAutreEtablissementRenvoie404(): void
    {
        // Vente réelle sur A (créée par l'admin, qui est affecté à A).
        [$clientA, $enteteA] = $this->adminSurA();
        $venteSurA = $this->creerVenteValidee($clientA, $enteteA, quantite: 1);

        // Un utilisateur porteur de `compta.gerer` sur B UNIQUEMENT : il passe la sécurité de la route
        // (il a bien la permission), mais n'a aucun droit sur l'établissement de la vente visée (A).
        [$emailB, $mdpB] = $this->creerGestionnaireComptaSurB();
        $clientB = static::createClient();
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        $enteteB = ['auth_bearer' => $this->jeton($clientB, $emailB, $mdpB), 'headers' => [ContexteEtablissement::HEADER => $idB]];

        $reponse = $clientB->request('POST', '/api/compta/ventes/' . $venteSurA['id'] . '/marquer-impayee-regie', $enteteB + [
            'json' => ['motif' => 'Intrusion cross-etablissement (C-cloisonnement)'],
        ]);

        self::assertSame(404, $reponse->getStatusCode(), (string) $reponse->getContent(false));
        self::assertSame(
            0,
            (int) $this->em()->getRepository(VenteImpayeeRegie::class)->count([]),
            'Aucune vente d\'un autre etablissement ne doit avoir ete marquee.',
        );
    }

    /** Une vente inexistante échoue elle aussi en 404 (le processus ne créait auparavant aucun contrôle d'existence). */
    public function testMarquerUneVenteInexistanteRenvoie404(): void
    {
        [$client, $entete] = $this->adminSurA();

        $reponse = $client->request('POST', '/api/compta/ventes/' . \Symfony\Component\Uid\Uuid::v4() . '/marquer-impayee-regie', $entete + [
            'json' => ['motif' => 'Vente fantome'],
        ]);

        self::assertSame(404, $reponse->getStatusCode(), (string) $reponse->getContent(false));
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }

    /**
     * Crée un utilisateur porteur d'un rôle `compta.gerer`, affecté à l'établissement B uniquement.
     *
     * @return array{0: string, 1: string} email, mot de passe
     */
    private function creerGestionnaireComptaSurB(): array
    {
        $em = $this->em();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $etabB = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertInstanceOf(Etablissement::class, $etabB);
        $permGerer = $em->getRepository(Permission::class)->findOneBy(['module' => 'compta', 'action' => 'gerer']);
        self::assertInstanceOf(Permission::class, $permGerer, 'La permission compta.gerer doit etre semee.');

        $role = (new Role())->setNom('Gestionnaire compta B (test)');
        $role->addPermission($permGerer);
        $em->persist($role);

        $email = 'compta-b-' . uniqid() . '@itcotation.com';
        $mdp = 'aaa';
        $user = (new Utilisateur())->setEmail($email)->setNom('Gestionnaire Compta B')->setActif(true);
        $user->setMotDePasse($hasher->hashPassword($user, $mdp));
        $em->persist($user);

        $em->persist((new Affectation())->setUtilisateur($user)->setRole($role)->setEtablissement($etabB));
        $em->flush();

        return [$email, $mdp];
    }
}
