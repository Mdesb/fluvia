<?php

declare(strict_types=1);

namespace App\Tests\Crm\Api;

use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\Crm\Entity\Famille;
use App\Crm\Enum\StatutClient;
use App\Crm\Enum\TypeClient;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Tests\Crm\CrmApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * AUDIT DU 06/09, CONSTAT 5 — les gestes CRM qui prenaient un client du corps sans le confronter au groupe.
 *
 * `CustomerScope` protège les LECTURES : un client hors de son groupe n'apparaît dans aucune liste.
 * Mais une fusion ou un rattachement à une famille reçoivent leurs clients par identifiant, dans le
 * corps, et `find()` n'a jamais entendu parler de `CustomerScope`. Un administrateur du groupe A
 * pouvait absorber un client du Groupe Second Loisirs, ou le rattacher à une famille de A.
 *
 * Les deux refus sont 404, et le témoin positif est le rattachement d'un client de SON groupe : sans
 * lui, un processeur qui refuserait tout passerait les deux premiers tests.
 */
final class CustomerReachabilityTest extends CrmApiTestCase
{
    public function testFusionnerUnClientDUnAutreGroupeVaut404(): void
    {
        [$client, $entete, ] = $this->adminSurA();
        $maitre = $this->entite(Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL]);
        $etranger = $this->clientDuGroupeB();

        $client->request('POST', '/api/crm/fusions', $entete + [
            'json' => ['maitre' => '/api/clients/' . $maitre->getId(), 'sources' => ['/api/clients/' . $etranger->getId()], 'motif' => 'preuve constat 5'],
        ]);

        self::assertResponseStatusCodeSame(404);
        $this->em()->clear();
        $relu = $this->em()->getRepository(Client::class)->find($etranger->getId());
        self::assertNotNull($relu);
        self::assertNull($relu->getFusionneDans(), 'le client étranger ne doit pas avoir été absorbé');
    }

    public function testRattacherUnClientDUnAutreGroupeAUneFamilleVaut404(): void
    {
        [$client, $entete, ] = $this->adminSurA();
        $famille = $this->entite(Famille::class, ['libelle' => CrmFixtures::FAMILLE_LIBELLE]);
        $etranger = $this->clientDuGroupeB();

        $client->request('POST', '/api/familles/' . $famille->getId() . '/beneficiaires', $entete + [
            'json' => ['client' => '/api/clients/' . $etranger->getId(), 'role' => 'beneficiaire'],
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->em()->getRepository(Beneficiaire::class)->findBy(['client' => $etranger]));
    }

    /** Ce que la règle ÉPARGNE : un client de son propre groupe se rattache toujours. */
    public function testRattacherUnClientDeSonGroupeResteOuvert(): void
    {
        [$client, $entete, ] = $this->adminSurA();
        $famille = $this->entite(Famille::class, ['libelle' => CrmFixtures::FAMILLE_LIBELLE]);
        $groupeA = $famille->getGroupe();
        self::assertInstanceOf(Groupe::class, $groupeA);
        $duGroupe = $this->client('cousin.dupont@example.test', $groupeA, $this->entite(Etablissement::class, ['nom' => \App\DataFixtures\SocleFixtures::ETAB_A_NOM]));

        $client->request('POST', '/api/familles/' . $famille->getId() . '/beneficiaires', $entete + [
            'json' => ['client' => '/api/clients/' . $duGroupe->getId(), 'role' => 'beneficiaire'],
        ]);

        self::assertResponseIsSuccessful();
    }

    private function clientDuGroupeB(): Client
    {
        $groupeB = $this->entite(Groupe::class, ['nom' => CrmFixtures::GROUPE_B_NOM]);
        $etabC = $this->entite(Etablissement::class, ['nom' => CrmFixtures::ETAB_C_NOM]);

        return $this->client('client.groupe.b@example.test', $groupeB, $etabC);
    }

    private function client(string $email, Groupe $groupe, Etablissement $etablissementCreation): Client
    {
        $client = (new Client())
            ->setType(TypeClient::Physique)
            ->setNom('Preuve')
            ->setPrenom('Constat cinq')
            ->setEmail($email)
            ->setDateNaissance(new \DateTimeImmutable('1980-01-01'))
            ->setStatut(StatutClient::Actif)
            ->setEtablissementCreation($etablissementCreation)
            ->setGroupe($groupe);
        $this->em()->persist($client);
        $this->em()->flush();

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
