<?php

declare(strict_types=1);

namespace App\Tests\Facturation\Api;

use App\DataFixtures\SocleFixtures;
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
 * US-FACT-01/02, RG-FACT-02 (CA-8) : le rendu porte numéro, émetteur, destinataire, lignes, TVA
 * ventilée, totaux, conditions de règlement, et la mention « acquittée » avec date/moyen/référence.
 */
final class FactureRenduApiTest extends FacturationApiTestCase
{
    /**
     * Cloisonnement (D3/D8) de `GET /factures/{id}/rendu` (`FactureRenduProvider`, provider custom hors
     * extensions). `facturation.lire` doit se recalculer contre l'établissement de la FACTURE : un agent
     * `facturation.lire` sur B ne doit pas pouvoir lire le rendu (montants + PII destinataire) d'une
     * facture de A → 404 (jamais 200).
     */
    public function testRenduDuneFactureDunAutreEtablissementRenvoie404(): void
    {
        // Facture réelle sur A (émise par l'admin, affecté à A).
        [$clientA, $enteteA] = $this->adminSurA();
        $vente = $this->creerVenteValidee($clientA, $enteteA);
        $facture = $clientA->request('POST', '/api/factures/depuis-vente', $enteteA + [
            'json' => ['vente' => '/api/ventes/' . $vente['id']],
        ])->toArray();

        // Un lecteur facturation sur B UNIQUEMENT : il passe la sécurité de route, mais n'a aucun droit
        // sur l'établissement de la facture visée (A).
        [$emailB, $mdpB] = $this->creerLecteurFacturationSurB();
        $clientB = static::createClient();
        $idB = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        $enteteB = ['auth_bearer' => $this->jeton($clientB, $emailB, $mdpB), 'headers' => [ContexteEtablissement::HEADER => $idB]];

        $reponse = $clientB->request('GET', '/api/factures/' . $facture['id'] . '/rendu', $enteteB);
        self::assertSame(404, $reponse->getStatusCode(), (string) $reponse->getContent(false));
    }

    /** @return array{0: string, 1: string} email, mot de passe d'un lecteur facturation.lire affecté à B seul. */
    private function creerLecteurFacturationSurB(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $etabB = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertInstanceOf(Etablissement::class, $etabB);
        $permLire = $em->getRepository(Permission::class)->findOneBy(['module' => 'facturation', 'action' => 'lire']);
        self::assertInstanceOf(Permission::class, $permLire, 'La permission facturation.lire doit etre semee.');

        $role = (new Role())->setNom('Lecteur facturation B (test)');
        $role->addPermission($permLire);
        $em->persist($role);

        $email = 'facturation-b-' . uniqid() . '@itcotation.com';
        $mdp = 'aaa';
        $user = (new Utilisateur())->setEmail($email)->setNom('Lecteur Facturation B')->setActif(true);
        $user->setMotDePasse($hasher->hashPassword($user, $mdp));
        $em->persist($user);

        $em->persist((new Affectation())->setUtilisateur($user)->setRole($role)->setEtablissement($etabB));
        $em->flush();

        return [$email, $mdp];
    }

    public function testCa8RenduPortesLesMentionsLegales(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->creerVenteValidee($client, $entete);
        $facture = $client->request('POST', '/api/factures/depuis-vente', $entete + [
            'json' => ['vente' => '/api/ventes/' . $vente['id']],
        ])->toArray();

        $rendu = $client->request('GET', '/api/factures/' . $facture['id'] . '/rendu', $entete)->toArray();

        self::assertSame($facture['numero'], $rendu['numero']);
        self::assertNotEmpty($rendu['emetteur']);
        self::assertSame('Régie piscine A', $rendu['emetteur']['denomination']);
        self::assertNotNull($rendu['destinataire']);
        self::assertNotEmpty($rendu['lignes']);
        self::assertNotEmpty($rendu['ventilationTva']);
        self::assertArrayHasKey('totalHT', $rendu);
        self::assertArrayHasKey('totalTVA', $rendu);
        self::assertArrayHasKey('totalTTC', $rendu);
        self::assertNotNull($rendu['conditionsReglement']);
        self::assertTrue($rendu['mentionAcquittee']);
        self::assertNotNull($rendu['acquitteeLe']);
        self::assertNotNull($rendu['acquitteeMoyen']);
        self::assertSame($vente['numero'], $rendu['acquitteeReference']);
    }
}
