<?php

declare(strict_types=1);

namespace App\Tests\Facturation\Api;

use App\Crm\Entity\Client;
use App\Crm\Enum\TypeClient;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Role;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Facturation\FacturationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * US-FACT-07 (CA-10) : le client connecté à son espace ne voit et ne peut télécharger que ses propres
 * factures, jamais celles d'un autre client.
 */
final class EspaceClientFactureApiTest extends FacturationApiTestCase
{
    public function testCa10ClientVoitSesFacturesUniquement(): void
    {
        [$adminClient, $entete] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $etabA = $em->getRepository(Etablissement::class)->find(\Symfony\Component\Uid\Uuid::fromString($this->idEtablissement(\App\DataFixtures\SocleFixtures::ETAB_A_NOM)));
        self::assertNotNull($etabA);
        $groupe = $etabA->getRegion()?->getGroupe();
        self::assertNotNull($groupe);

        // La permission `facturation.lire_soi` est déjà seedée par la migration (§4 du plan) : on la
        // réutilise plutôt que d'en créer une seconde (violerait `uniq_permission_module_action`).
        $permSoi = $em->getRepository(Permission::class)->findOneBy(['module' => 'facturation', 'action' => 'lire_soi']);
        self::assertNotNull($permSoi, 'Permission facturation.lire_soi introuvable (migration non jouée ?).');

        $roleSoi = (new Role())->setNom('Client final (test)');
        $roleSoi->addPermission($permSoi);
        $em->persist($roleSoi);

        [$userA, $clientA] = $this->creerClientEtUtilisateur($em, $hasher, $groupe, $etabA, $roleSoi, 'clienta@test.fr');
        [$userB, $clientB] = $this->creerClientEtUtilisateur($em, $hasher, $groupe, $etabA, $roleSoi, 'clientb@test.fr');
        $em->flush();

        // Facture directe pour le client A (destinataire rattaché à sa fiche CRM).
        $brouillon = $adminClient->request('POST', '/api/factures', $entete + [
            'json' => [
                'destinataire' => [
                    'type' => 'particulier',
                    'nom' => 'Client A',
                    'adresse' => ['rue' => '1 rue A', 'cp' => '75000', 'ville' => 'Paris', 'pays' => 'FR'],
                    'clientRef' => (string) $clientA->getId(),
                ],
                'lignes' => [[
                    'designation' => 'Prestation A',
                    'quantite' => 1,
                    'prixUnitaireHT' => '80.00',
                    'tauxTva' => '/api/taux_tvas/' . $this->idTauxTva('Taux normal 20 %'),
                ]],
            ],
        ])->toArray();
        $factureA = $adminClient->request('POST', '/api/factures/' . $brouillon['id'] . '/emettre', $entete)->toArray();

        $tokenA = $this->jeton($adminClient, 'clienta@test.fr', 'client-pass');
        $enteteA = ['auth_bearer' => $tokenA, 'headers' => [ContexteEtablissement::HEADER => (string) $etabA->getId()]];

        $mesFactures = $adminClient->request('GET', '/api/mes-factures', $enteteA)->toArray();
        $membres = $mesFactures['member'] ?? $mesFactures['hydra:member'] ?? [];
        self::assertNotEmpty($membres);
        self::assertSame($factureA['numero'], $membres[0]['numero']);

        $tokenB = $this->jeton($adminClient, 'clientb@test.fr', 'client-pass');
        $enteteB = ['auth_bearer' => $tokenB, 'headers' => [ContexteEtablissement::HEADER => (string) $etabA->getId()]];

        $reponseB = $adminClient->request('GET', '/api/factures/' . $factureA['id'], $enteteB);
        self::assertContains($reponseB->getStatusCode(), [403, 404], 'Le client B ne peut pas voir la facture du client A.');

        $mesFacturesB = $adminClient->request('GET', '/api/mes-factures', $enteteB)->toArray();
        $membresB = $mesFacturesB['member'] ?? $mesFacturesB['hydra:member'] ?? [];
        self::assertEmpty($membresB, 'Le client B ne voit aucune facture du client A.');
    }

    /** @return array{0: Utilisateur, 1: Client} */
    private function creerClientEtUtilisateur(
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        \App\Organisation\Entity\Groupe $groupe,
        Etablissement $etablissement,
        Role $role,
        string $email,
    ): array {
        $client = (new Client())
            ->setGroupe($groupe)
            ->setEtablissementCreation($etablissement)
            ->setType(TypeClient::Physique)
            ->setNom('Client')
            ->setPrenom($email);
        $em->persist($client);

        $utilisateur = (new Utilisateur())->setEmail($email)->setNom('Client final')->setActif(true);
        $utilisateur->setMotDePasse($hasher->hashPassword($utilisateur, 'client-pass'));
        $utilisateur->setClientLie($client->getId());
        $em->persist($utilisateur);

        $em->persist((new Affectation())->setUtilisateur($utilisateur)->setRole($role)->setEtablissement($etablissement));

        return [$utilisateur, $client];
    }
}
