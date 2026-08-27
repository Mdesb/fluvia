<?php

declare(strict_types=1);

namespace App\Tests\Marketing\Api;

use App\Marketing\Entity\Campaign;
use App\Marketing\Entity\Segment;
use App\Marketing\Entity\SegmentCriteria;
use App\Tests\Marketing\MarketingApiTestCase;

/**
 * LES LISTES — le cloisonnement s'y perd sans bruit.
 *
 * Les opérations d'item sont contrôlées une par une (404 hors périmètre). Les COLLECTIONS, elles,
 * ne rendent pas d'erreur quand elles fuient : elles rendent des lignes en trop, et une liste trop
 * longue a exactement la même allure qu'une liste juste.
 *
 * > **Une fuite de cloisonnement ne produit pas d'erreur, elle produit des lignes en trop.**
 */
final class CloisonnementListesTest extends MarketingApiTestCase
{
    public function testUnAgentDUnAutreGroupeNeVoitPasLesSegmentsDuGroupeA(): void
    {
        $em = $this->em();
        $em->persist(
            (new Segment())
                ->setEstablishment($this->etablissementA())
                ->setLabel('Abonnés sur le départ')
                ->setCriteria([SegmentCriteria::TYPE => 'physique'])
        );
        $em->flush();

        [$clientB, $enteteB] = $this->agentSurGroupeB();
        $reponse = $clientB->request('GET', '/api/segments', $enteteB);

        self::assertStringNotContainsString(
            'Abonnés sur le départ',
            $reponse->getContent(false),
            'Le nom d’un segment dit ce qu’un exploitant pense de ses clients.',
        );
    }

    public function testUnAgentDUnAutreGroupeNeVoitPasLesCampagnesDuGroupeA(): void
    {
        $em = $this->em();
        $segment = (new Segment())
            ->setEstablishment($this->etablissementA())
            ->setLabel('Cible A')
            ->setCriteria([SegmentCriteria::TYPE => 'physique']);
        $em->persist($segment);
        $em->persist(
            (new Campaign())
                ->setEstablishment($this->etablissementA())
                ->setSegment($segment)
                ->setLabel('Offre secrète de rentrée')
                ->setSubject('Notre meilleure offre')
                ->setBody('Revenez nous voir.')
        );
        $em->flush();

        [$clientB, $enteteB] = $this->agentSurGroupeB();
        $reponse = $clientB->request('GET', '/api/campaigns', $enteteB);

        self::assertStringNotContainsString(
            'Offre secrète de rentrée',
            $reponse->getContent(false),
            'Le nom et le texte d’une campagne disent ce qu’un concurrent prépare.',
        );
    }
}
