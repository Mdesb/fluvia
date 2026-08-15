<?php

declare(strict_types=1);

namespace App\Tests\Crm\Api;

use App\Audit\Entity\EntreeAudit;
use App\Crm\Entity\Beneficiaire;
use App\Crm\Entity\Client;
use App\Crm\Entity\Consentement;
use App\Crm\Enum\CanalConsentement;
use App\Crm\Enum\EtatConsentement;
use App\Tests\Crm\CrmApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * US-L5-10, RG-M4-10, décision « passage à la majorité » : CA-19/CA-20.
 */
final class MajoriteTest extends CrmApiTestCase
{
    public function testCa19Et20PassageMajoriteRenouvellementEtJournalisation(): void
    {
        [$client, $entete] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $enfant = $this->entite(Client::class, ['prenom' => \App\Crm\DataFixtures\CrmFixtures::ENFANT_PRENOM]);

        // Consentement email accordé par le représentant légal (mineur).
        $consentement = new Consentement(CanalConsentement::Email, EtatConsentement::Accorde);
        $consentement->setClient($enfant);
        $consentement->setSource('guichet');
        $consentement->setRecueilliParRepresentant(true);
        $em->persist($consentement);

        // Le bénéficiaire enfant devient majeur (anniversaire aujourd'hui).
        $enfant->setDateNaissance(new \DateTimeImmutable('-18 years'));
        $em->flush();

        $application = new Application(static::$kernel);
        $application->setAutoExit(false);
        $tester = new CommandTester($application->find('crm:consentement:verifier-majorite'));
        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());

        $em->clear();
        $enfantApres = $this->entite(Client::class, ['prenom' => \App\Crm\DataFixtures\CrmFixtures::ENFANT_PRENOM]);
        // Recherche directe par état (plutôt qu'un tri par dateRecueil) : la ligne « accorde » du test
        // et la ligne « a_renouveler » de la commande peuvent partager la même seconde d'horodatage.
        $renouveler = $em->getRepository(Consentement::class)->findBy(['client' => $enfantApres, 'etat' => EtatConsentement::ARenouveler]);
        self::assertNotEmpty($renouveler, 'CA-19 : consentement passé en a_renouveler.');

        // CA-20 : journalisation avec cause « majorité ».
        $entrees = $em->getRepository(EntreeAudit::class)->findBy(['action' => 'passage_majorite', 'cibleId' => (string) $enfantApres->getId()]);
        self::assertNotEmpty($entrees, 'CA-20 : passage à la majorité journalisé.');

        // Autorisations parentales réévaluées : « entree_seule » retirée du bénéficiaire lui-même.
        $beneficiaire = $em->getRepository(Beneficiaire::class)->findOneBy(['client' => $enfantApres]);
        self::assertNotContains('entree_seule', $beneficiaire?->getAutorisations() ?? [], 'CA-20 : autorisation dérogatoire retirée à la majorité.');
    }
}
