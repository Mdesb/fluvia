<?php

declare(strict_types=1);

namespace App\Tests\Crm\Api;

use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Client;
use App\Crm\Entity\ParametrePmvEtablissement;
use App\Crm\Entity\PorteMonnaieVirtuel;
use App\Crm\Enum\StatutPmv;
use App\Crm\Enum\TraitementSoldeResiduel;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Tests\Crm\CrmApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * US-L5-07, CA-12 : traitement du solde résiduel à l'expiration du PMV (commande planifiée).
 */
final class ExpirationPmvTest extends CrmApiTestCase
{
    public function testCa12ExpirationSelonParametreEtablissement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $payeurId = $this->idPayeur();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $payeur = $this->entite(Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL]);
        $pmv = $this->entite(PorteMonnaieVirtuel::class, ['client' => $payeur]);
        $pmv->setDateEcheance(new \DateTimeImmutable('-1 day'));
        $em->flush();

        $etabA = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);
        $parametre = $this->entite(ParametrePmvEtablissement::class, ['etablissement' => $etabA]);
        $parametre->setTraitementSoldeResiduel(TraitementSoldeResiduel::Annule);
        $em->flush();

        $application = new Application(static::$kernel);
        $application->setAutoExit(false);
        $tester = new CommandTester($application->find('crm:rgpd:expirer-pmv'));
        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());

        $em->clear();
        $pmvApres = $this->entite(PorteMonnaieVirtuel::class, ['client' => $payeur]);
        self::assertSame(StatutPmv::Expire, $pmvApres->getStatut());
        self::assertSame('0.00', $pmvApres->getSolde(), 'CA-12 : solde annulé selon paramètre établissement.');

        $mouvements = $client->request('GET', '/api/clients/' . $payeurId . '/pmv/mouvements', $entete)->toArray();
        $expirations = array_filter($mouvements['mouvements'], static fn (array $m): bool => $m['type'] === 'expiration');
        self::assertNotEmpty($expirations, 'CA-12 : MouvementPmv(expiration) daté/motivé/exportable.');
        // CA-12 : le solde annulé reste consultable dans l'historique (mouvement jamais supprimé).
        self::assertSame('-50.00', array_values($expirations)[0]['montant']);
    }
}
