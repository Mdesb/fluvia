<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use App\Sport\Entity\AbonnementFitness;
use App\Sport\Entity\Resiliation;
use App\Sport\Enum\StatutAbonnementFitness;
use App\Sport\Service\DemanderResiliationHandler;
use App\Tests\Sport\SportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Réengagement d'un résilié (US-SPORT-04, décision actée, CA-4) : nouvel abonnement, nouvel
 * engagement, nouvel échéancier, **nouveau mandat SEPA obligatoire** même si l'ancien mandat n'a pas
 * été révoqué au moment du test (contrôle explicite de la décision « toujours un nouveau mandat »).
 */
final class ReengagementTest extends SportApiTestCase
{
    public function testCa4ReengagementCreeNouvelAbonnementAvecNouveauMandat(): void
    {
        [$client, $entete] = $this->adminSurA();
        $abonnement = $this->abonnementDemo();
        $ancienId = (string) $abonnement->getId();
        $ancienMandatId = (string) $abonnement->getMandatSepa()->getId();

        // Résilie l'abonnement (hors engagement pour aller directement à `effective`).
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var DemanderResiliationHandler $handler */
        $handler = static::getContainer()->get(DemanderResiliationHandler::class);
        $dateDemande = $abonnement->getDateFinEngagement()->modify('+1 day');
        $resiliation = $handler->demander($abonnement, $dateDemande, 'Test', false, null);
        self::assertSame('en_preavis', $resiliation->getStatut()->value);
        $handler->executerEffet($resiliation);
        $em->clear();

        $abonnementResilie = $em->getRepository(AbonnementFitness::class)->find($ancienId);
        self::assertSame('resilie', $abonnementResilie->getStatut()->value);
        // L'ancien mandat n'a PAS été révoqué avant la date d'effet mais l'EST maintenant (effective).
        self::assertSame('revoque', $abonnementResilie->getMandatSepa()->getStatut()->value);

        $client->request('POST', '/api/sport/abonnements/' . $ancienId . '/reengager', $entete + [
            'json' => [
                // montant retire : le prix est resolu depuis la grille tarifaire (arbitrage 01/09).
                'iban' => 'FR7630006000011234567890200',
                'titulaireMandat' => 'Marie Dupont',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $reengagement = $client->getResponse()->toArray();

        $nouvelAbonnementId = \is_array($reengagement['nouvelAbonnement']) ? $reengagement['nouvelAbonnement']['id'] : basename((string) $reengagement['nouvelAbonnement']);
        $nouveauMandatId = \is_array($reengagement['nouveauMandat']) ? $reengagement['nouveauMandat']['id'] : basename((string) $reengagement['nouveauMandat']);

        self::assertNotSame($ancienId, $nouvelAbonnementId);
        self::assertNotSame($ancienMandatId, $nouveauMandatId, '**Toujours** un nouveau mandat SEPA (décision actée).');

        $em->clear();
        $nouvel = $em->getRepository(AbonnementFitness::class)->find($nouvelAbonnementId);
        self::assertSame('actif', $nouvel->getStatut()->value);
        self::assertSame('actif', $nouvel->getMandatSepa()->getStatut()->value);
        self::assertNotSame($ancienMandatId, (string) $nouvel->getMandatSepa()->getId());
    }

    public function testReengagementRefuseSiAbonnementNonResilie(): void
    {
        [$client, $entete] = $this->adminSurA();
        $abonnementId = $this->idAbonnementDemo();

        $client->request('POST', '/api/sport/abonnements/' . $abonnementId . '/reengager', $entete + [
            'json' => ['montantCentimes' => 3990, 'iban' => 'FR7630006000011234567890200', 'titulaireMandat' => 'Test'],
        ]);
        self::assertResponseStatusCodeSame(422, 'Seul un abonnement résilié peut être réengagé.');
    }
}
