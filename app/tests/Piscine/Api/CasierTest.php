<?php

declare(strict_types=1);

namespace App\Tests\Piscine\Api;

use App\Organisation\Entity\Etablissement;
use App\Piscine\Entity\BraceletEtanche;
use App\Piscine\Entity\Casier;
use App\Piscine\Entity\RelanceCasier;
use App\Piscine\Enum\EtatCasier;
use App\DataFixtures\SocleFixtures;
use App\Tests\Piscine\PiscineApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Casiers connectés — caution, relance, forçage (US-L6-09, CA-9). Attribution encaisse une caution ;
 * restitution la libère ; délai dépassé sans restitution déclenche une relance (état « en retard ») ;
 * délai de forçage dépassé permet un forçage administratif journalisé.
 */
final class CasierTest extends PiscineApiTestCase
{
    public function testCa9AttributionEncaisseLaCautionEtOccupeLeCasier(): void
    {
        [$client, $entete] = $this->adminSurA();

        $casierId = $this->creerCasierLibre();
        $braceletId = $this->creerBracelet('RFID-CASIER-TEST-01');

        $client->request('POST', '/api/piscine/casiers/' . $casierId . '/attribuer', $entete + [
            'json' => ['bracelet' => '/api/bracelet_etanches/' . $braceletId],
        ]);
        self::assertResponseIsSuccessful();
        $caution = $client->getResponse()->toArray();
        self::assertSame('encaissee', $caution['statut']);
        self::assertSame('10.00', $caution['montant']);

        $client->request('GET', '/api/casiers/' . $casierId, $entete);
        self::assertSame('occupe', $client->getResponse()->toArray()['etat']);
    }

    public function testCa9AttributionRefuseeSiCasierDejaOccupe(): void
    {
        [$client, $entete] = $this->adminSurA();

        $casierOccupe = $this->idCasier(); // casier de démonstration, déjà occupé (fixtures).
        $braceletId = $this->creerBracelet('RFID-CASIER-TEST-02');

        $client->request('POST', '/api/piscine/casiers/' . $casierOccupe . '/attribuer', $entete + [
            'json' => ['bracelet' => '/api/bracelet_etanches/' . $braceletId],
        ]);
        self::assertResponseStatusCodeSame(422, 'Un casier déjà occupé (une caution active) ne peut être ré-attribué.');
    }

    public function testCa9RestitutionLibereLaCautionEtLeCasier(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/piscine/casiers/' . $this->idCasier() . '/liberer', $entete);
        self::assertResponseIsSuccessful();
        self::assertSame('liberee', $client->getResponse()->toArray()['statut']);

        $client->request('GET', '/api/casiers/' . $this->idCasier(), $entete);
        self::assertSame('libre', $client->getResponse()->toArray()['etat']);
    }

    public function testCa9RelanceBasculeEtatEnRetard(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/piscine/casiers/' . $this->idCasier() . '/relancer', $entete);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/casiers/' . $this->idCasier(), $entete);
        self::assertSame('non_rendu', $client->getResponse()->toArray()['etat']);
    }

    public function testCa9ForcageRefuseTantQueLeDelaiNestPasDepasse(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/piscine/casiers/' . $this->idCasier() . '/relancer', $entete);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/piscine/casiers/' . $this->idCasier() . '/forcer', $entete + [
            'json' => ['motif' => 'Casier bloqué en fin de journée'],
        ]);
        self::assertResponseStatusCodeSame(422, 'Le forçage n\'est autorisé qu\'après dépassement du délai paramétré.');
    }

    public function testCa9ForcageApresDelaiEstJournaliseEtLibereLeCasier(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/piscine/casiers/' . $this->idCasier() . '/relancer', $entete);
        self::assertResponseIsSuccessful();

        // Simule le dépassement du délai de forçage (3 j par défaut) en antidatant la relance.
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $casier = $em->getRepository(Casier::class)->find($this->idCasier());
        $relance = $em->getRepository(RelanceCasier::class)->findOneBy(['casier' => $casier]);
        $relance->setDateRelance(new \DateTimeImmutable('-10 days'));
        $em->flush();

        $client->request('POST', '/api/piscine/casiers/' . $this->idCasier() . '/forcer', $entete + [
            'json' => ['motif' => 'Casier bloqué, objets retirés en régie'],
        ]);
        self::assertResponseIsSuccessful();
        $forcage = $client->getResponse()->toArray();
        self::assertNotEmpty($forcage['agent']);
        self::assertSame('Casier bloqué, objets retirés en régie', $forcage['motif']);
        self::assertNotEmpty($forcage['horodatage']);

        $client->request('GET', '/api/casiers/' . $this->idCasier(), $entete);
        self::assertSame('libre', $client->getResponse()->toArray()['etat'], 'Le casier forcé repasse disponible.');

        $client->request('GET', '/api/caution_casiers', $entete + ['query' => ['itemsPerPage' => 50]]);
        $membres = $client->getResponse()->toArray()['member'] ?? $client->getResponse()->toArray()['hydra:member'];
        $cautions = array_filter($membres, static fn (array $c) => str_contains((string) $c['casier'], (string) $casier->getId()));
        self::assertNotEmpty($cautions);
        self::assertSame('retenue', array_values($cautions)[0]['statut'], 'La caution est retenue après un forçage administratif.');
    }

    public function testCa9ForcageRefuseSansMotif(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('POST', '/api/piscine/casiers/' . $this->idCasier() . '/relancer', $entete);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $casier = $em->getRepository(Casier::class)->find($this->idCasier());
        $relance = $em->getRepository(RelanceCasier::class)->findOneBy(['casier' => $casier]);
        $relance->setDateRelance(new \DateTimeImmutable('-10 days'));
        $em->flush();

        $client->request('POST', '/api/piscine/casiers/' . $this->idCasier() . '/forcer', $entete + ['json' => ['motif' => '']]);
        self::assertResponseStatusCodeSame(422, 'RG-SOCLE-07 : le motif est requis pour un forçage journalisé.');
    }

    private function creerCasierLibre(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        $casier = (new Casier())->setNumero(99)->setZone('Vestiaire test')->setEtat(EtatCasier::Libre)->setEtablissement($etab);
        $em->persist($casier);
        $em->flush();

        return (string) $casier->getId();
    }

    private function creerBracelet(string $identifiant): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        $support = (new \App\Acces\Entity\Support())->setIdentifiant($identifiant)->setType(\App\Acces\Enum\TypeSupport::Rfid)->setEtablissement($etab);
        $em->persist($support);
        $bracelet = (new BraceletEtanche())->setSupport($support)->setRoles([BraceletEtanche::ROLE_ACCES, BraceletEtanche::ROLE_CASIER]);
        $em->persist($bracelet);
        $em->flush();

        return (string) $bracelet->getId();
    }
}
