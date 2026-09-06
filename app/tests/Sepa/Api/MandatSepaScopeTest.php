<?php

declare(strict_types=1);

namespace App\Tests\Sepa\Api;

use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Client;
use App\Crm\Enum\StatutClient;
use App\Crm\Enum\TypeClient;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Sepa\Entity\MandatSepa;
use App\Tests\Sepa\SepaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * AUDIT DU 06/09, CONSTAT 5 — le mandat SEPA créé chez un autre tenant.
 *
 * `CreerMandatSepaProcessor` résolvait `client` et `etablissement` depuis le corps par `find()`, sans
 * les confronter à personne. Un administrateur de A pouvait signer un mandat rattaché à l'établissement
 * d'un tenant qu'il n'atteint pas, ou sur un client d'un groupe qui n'est pas le sien. Les deux
 * refusent désormais en 404 — ne pas confirmer l'existence de ce qu'on a deviné.
 *
 * `MandatSepaTest` reste le témoin positif (A sur A, 201) : sans lui, un processeur qui refuserait tout
 * passerait les deux tests ci-dessous.
 */
final class MandatSepaScopeTest extends SepaApiTestCase
{
    public function testUnEtablissementHorsPerimetreVaut404(): void
    {
        [$client, $entete, ] = $this->adminSurA();
        $payeur = $this->entite(Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL]);
        $etranger = $this->etablissementEtranger();

        $client->request('POST', '/api/sepa/mandats', $entete + ['json' => $this->corps($payeur, $etranger)]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->em()->getRepository(MandatSepa::class)->findBy(['etablissement' => $etranger]), 'aucun mandat ne doit exister chez le tenant étranger');
    }

    public function testUnClientDUnAutreGroupeVaut404(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();
        $etranger = $this->etablissementEtranger();
        $clientEtranger = (new Client())
            ->setType(TypeClient::Physique)
            ->setNom('Étranger')
            ->setPrenom('Groupe')
            ->setEmail('client.groupe.etranger@example.test')
            ->setDateNaissance(new \DateTimeImmutable('1980-01-01'))
            ->setStatut(StatutClient::Actif)
            ->setEtablissementCreation($etranger)
            ->setGroupe($etranger->getRegion()?->getGroupe());
        $this->em()->persist($clientEtranger);
        $this->em()->flush();

        $etabA = $this->entite(Etablissement::class, ['nom' => \App\DataFixtures\SocleFixtures::ETAB_A_NOM]);
        self::assertSame($idA, (string) $etabA->getId());
        $client->request('POST', '/api/sepa/mandats', $entete + ['json' => $this->corps($clientEtranger, $etabA)]);

        self::assertResponseStatusCodeSame(404);
    }

    /** @return array<string, string> */
    private function corps(Client $payeur, Etablissement $etablissement): array
    {
        return [
            'client' => '/api/clients/' . $payeur->getId(),
            'etablissement' => '/api/etablissements/' . $etablissement->getId(),
            'iban' => 'FR7630006000099876543210987',
            'bicDebiteur' => 'AGRIFRPPXXX',
            'debiteurNom' => 'Preuve constat 5',
        ];
    }

    /** Un groupe, une région, un établissement où PERSONNE des fixtures n'est affecté. */
    private function etablissementEtranger(): Etablissement
    {
        $em = $this->em();
        $groupe = (new Groupe())->setNom('Groupe étranger (constat 5)');
        $region = (new Region())->setNom('Région étrangère')->setGroupe($groupe);
        $etablissement = (new Etablissement())->setNom('Tenant étranger (constat 5)')->setRegion($region)->setActif(true);
        $em->persist($groupe);
        $em->persist($region);
        $em->persist($etablissement);
        $em->flush();

        return $etablissement;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
