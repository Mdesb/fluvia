<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit;

use App\Crm\Entity\Client;
use App\Crm\Entity\Consentement;
use App\Crm\Enum\TypeClient;
use App\DataFixtures\SocleFixtures;
use App\Import\Service\ReverseReferenceChecker;
use App\Organisation\Entity\Etablissement;
use App\Tests\SchemaDuHarnais;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `ReverseReferenceChecker` (plan-import-i1.md §0.8) — scan de métadonnées Doctrine, positif/négatif.
 * `Consentement.client` sert de fixture d'association ManyToOne réelle vers `Client` (même famille que
 * `Beneficiaire`/`DemandeRGPD`, plus simple à construire).
 */
final class ReverseReferenceCheckerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ReverseReferenceChecker $checker;
    private Etablissement $etablissement;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine')->getManager();
        $this->em = $em;
        SchemaDuHarnais::reinitialiser($em);
        $container->get(SocleFixtures::class)->load($em);

        /** @var ReverseReferenceChecker $checker */
        $checker = $container->get(ReverseReferenceChecker::class);
        $this->checker = $checker;

        $etablissement = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etablissement);
        $this->etablissement = $etablissement;
    }

    private function creerClient(string $nom): Client
    {
        $client = new Client();
        $client->setType(TypeClient::Physique);
        $client->setNom($nom);
        $client->setEtablissementCreation($this->etablissement);
        $client->setGroupe($this->etablissement->getRegion()?->getGroupe());
        $this->em->persist($client);

        return $client;
    }

    public function testDetecteUneAssociationDoctrineVersLaCible(): void
    {
        $client = $this->creerClient('Référencé');
        $consentement = (new Consentement())->setClient($client);
        $this->em->persist($consentement);
        $this->em->flush();

        self::assertTrue($this->checker->isReferenced(Client::class, $client->getId()));
    }

    public function testNegatifSiAucuneAssociationNePointeVersLaCible(): void
    {
        $client = $this->creerClient('Non référencé');
        $this->em->flush();

        self::assertFalse($this->checker->isReferenced(Client::class, $client->getId()));
    }
}
