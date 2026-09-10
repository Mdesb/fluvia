<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use App\Membership\Entity\Membership;
use App\Membership\Entity\Resiliation;
use App\Tests\Sport\SportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Résiliation d'abonnement (US-SPORT-03, RG-SPORT-06/07, décision actée, CA-3) : bloquée en
 * engagement sans motif légitime ; acceptée avec motif légitime validé, date d'effet = demande +
 * préavis, mandat révoqué **à la date d'effet seulement**.
 */
final class ResiliationTest extends SportApiTestCase
{
    public function testCa3ResiliationBloqueeEnEngagementSansMotifLegitime(): void
    {
        [$client, $entete] = $this->adminSurA();
        $abonnementId = $this->idAbonnementDemo();

        $client->request('POST', '/api/sport/abonnements/' . $abonnementId . '/resiliations', $entete + [
            'json' => ['motif' => 'Je change de club', 'motifLegitime' => false],
        ]);
        self::assertResponseIsSuccessful();
        $resiliation = $client->getResponse()->toArray();
        self::assertSame('refusee', $resiliation['statut'], 'Résiliation en engagement sans motif légitime : bloquée (décision actée).');
    }

    public function testCa3ResiliationAvecMotifLegitimeValideEstAcceptee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $abonnementId = $this->idAbonnementDemo();

        $client->request('POST', '/api/sport/abonnements/' . $abonnementId . '/resiliations', $entete + [
            'json' => ['motif' => 'Déménagement', 'motifLegitime' => true, 'dateDemande' => '2026-09-01'],
        ]);
        self::assertResponseIsSuccessful();
        $resiliation = $client->getResponse()->toArray();
        self::assertSame('refusee', $resiliation['statut'], 'En attente de validation manuelle tant que non validée.');
        $resiliationId = $resiliation['id'];

        $client->request('POST', '/api/sport/resiliations/' . $resiliationId . '/valider-motif-legitime', $entete);
        self::assertResponseIsSuccessful();
        $valide = $client->getResponse()->toArray();
        self::assertSame('en_preavis', $valide['statut']);
        self::assertSame('2026-09-01', substr((string) $valide['dateDemande'], 0, 10));

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $resiliationEntite = $em->getRepository(Resiliation::class)->find($resiliationId);
        self::assertNotNull($resiliationEntite->getValideParUtilisateur());

        $preavis = $resiliationEntite->getPreavisAppliqueJours();
        $dateEffetAttendue = (new \DateTimeImmutable('2026-09-01'))->modify(sprintf('+%d days', $preavis));
        self::assertSame($dateEffetAttendue->format('Y-m-d'), $resiliationEntite->getDateEffet()->format('Y-m-d'));

        // Le mandat n'est PAS révoqué avant la date d'effet (RG-SPORT-06).
        $abonnement = $em->getRepository(Membership::class)->find($abonnementId);
        self::assertSame('actif', $abonnement->getMandatSepa()->getStatut()->value);
    }

    public function testCa3ResiliationHorsEngagementAccepteeDirectement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $abonnementId = $this->idAbonnementDemo();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $abonnement = $em->getRepository(Membership::class)->find($abonnementId);
        // Place la demande après la fin d'engagement (période libre).
        $dateDemande = $abonnement->getDateFinEngagement()->modify('+1 day');

        $client->request('POST', '/api/sport/abonnements/' . $abonnementId . '/resiliations', $entete + [
            'json' => ['motif' => 'Fin de contrat', 'dateDemande' => $dateDemande->format('Y-m-d')],
        ]);
        self::assertResponseIsSuccessful();
        $resiliation = $client->getResponse()->toArray();
        self::assertSame('en_preavis', $resiliation['statut']);
    }
}
