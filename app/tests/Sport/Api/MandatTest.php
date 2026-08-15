<?php

declare(strict_types=1);

namespace App\Tests\Sport\Api;

use App\Sport\Entity\AbonnementFitness;
use App\Sport\Service\DemanderResiliationHandler;
use App\Tests\Sport\SportApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gestion des mandats SEPA (US-SPORT-10, CA-12) : révocation à la date d'effet de la résiliation
 * (pas avant), visible sur l'écran de gestion des mandats. IBAN jamais exposé (garde transverse §4).
 */
final class MandatTest extends SportApiTestCase
{
    public function testCa12MandatRevoqueALaDateDeffetDeLaResiliation(): void
    {
        [$client, $entete] = $this->adminSurA();
        $abonnement = $this->abonnementDemo();
        $mandatId = (string) $abonnement->getMandatSepa()->getId();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var DemanderResiliationHandler $handler */
        $handler = static::getContainer()->get(DemanderResiliationHandler::class);

        $dateDemande = $abonnement->getDateFinEngagement()->modify('+1 day');
        $resiliation = $handler->demander($abonnement, $dateDemande, 'Test', false, null);
        self::assertSame('en_preavis', $resiliation->getStatut()->value);

        // Avant la date d'effet : le mandat reste actif (RG-SPORT-06, pas avant).
        $client->request('GET', '/api/mandat_sepa_fitnesses/' . $mandatId, $entete);
        self::assertSame('actif', $client->getResponse()->toArray()['statut']);

        $handler->executerEffet($resiliation);
        $em->clear();

        $client->request('GET', '/api/mandat_sepa_fitnesses/' . $mandatId, $entete);
        self::assertResponseIsSuccessful();
        self::assertSame('revoque', $client->getResponse()->toArray()['statut'], 'Visible révoqué sur l\'écran de gestion des mandats.');
    }

    public function testIbanJamaisExposeSurLesReponsesMandat(): void
    {
        [$client, $entete] = $this->adminSurA();
        $mandatId = (string) $this->abonnementDemo()->getMandatSepa()->getId();

        $client->request('GET', '/api/mandat_sepa_fitnesses/' . $mandatId, $entete);
        self::assertResponseIsSuccessful();
        $corps = $client->getResponse()->getContent();
        self::assertStringNotContainsString('ibanToken', $corps);
        self::assertStringNotContainsString('FR76', $corps, 'Aucun IBAN en clair (préfixe pays) dans la réponse API.');

        $client->request('GET', '/api/mandat_sepa_fitnesses', $entete);
        self::assertResponseIsSuccessful();
        $corpsCollection = $client->getResponse()->getContent();
        self::assertStringNotContainsString('ibanToken', $corpsCollection);
    }

    public function testIbanJamaisPersisteEnClairEnBase(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $abonnement = $em->getRepository(AbonnementFitness::class)->find($this->idAbonnementDemo());
        $mandat = $abonnement->getMandatSepa();

        self::assertStringNotContainsString('FR76', $mandat->getIbanToken());
        self::assertNotSame('FR7630006000011234567890189', $mandat->getIbanToken());
        // Token non réversible trivialement : longueur d'un HMAC-SHA256 hexadécimal (64 caractères).
        self::assertSame(64, \strlen($mandat->getIbanToken()));
    }
}
