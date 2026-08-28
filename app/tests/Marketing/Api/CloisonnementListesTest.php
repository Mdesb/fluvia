<?php

declare(strict_types=1);

namespace App\Tests\Marketing\Api;

use App\Crm\DataFixtures\CrmFixtures;
use App\Organisation\Entity\Etablissement;
use App\Securite\Service\ContexteEtablissement;
use App\DataFixtures\SocleFixtures;
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

    /**
     * **L'ÉTABLISSEMENT ACTIF, PAS LE PÉRIMÈTRE DU LECTEUR.**
     *
     * Trouvé dans le navigateur le 28/08, et invisible aux tests jusque-là : sur Patinoire B,
     * l'onglet Fidélité affichait le barème de Piscine A — « 1 point par euro » — comme s'il était
     * celui de Patinoire B.
     *
     * Ce n'était pas une fuite : l'administratrice est affectée aux deux. C'était pire à sa
     * manière — **un chiffre juste, au mauvais endroit, que rien ne signale**. Elle aurait posé un
     * palier en croyant configurer Patinoire B.
     *
     * Un segment, une campagne, un barème sont les réglages D'UN établissement. L'axe est donc
     * l'établissement ACTIF, et le test le prend par là : même lecteur, même périmètre, deux
     * en-têtes, deux réponses.
     */
    public function testUnReglageDUnAutreEtablissementDuMemePerimetreNApparaitPas(): void
    {
        $em = $this->em();
        $em->persist(
            (new Segment())
                ->setEstablishment($this->etablissementA())
                ->setLabel('Réglage de Piscine A')
                ->setCriteria([SegmentCriteria::TYPE => 'physique'])
        );
        $em->flush();

        [$client, $entete] = $this->adminSurA();
        $surA = $client->request('GET', '/api/segments', $entete)->getContent(false);
        self::assertStringContainsString(
            'Réglage de Piscine A',
            $surA,
            'Sur son propre établissement, l’administratrice doit voir son réglage.',
        );

        // MÊME utilisateur, MÊME périmètre — seul l'en-tête change.
        //
        // ⚠ `Patinoire B` et non `Musée C` : le second est HORS du périmètre de l'administratrice,
        // la requête y serait refusée, et « ne contient pas » passerait sur un corps d'erreur. Ce
        // test-ci a été vert à vide avant cette correction — vérifié en remettant l'ancien axe.
        $autre = $this->em()->getRepository(Etablissement::class)
            ->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertInstanceOf(Etablissement::class, $autre);

        $enteteAutre = $entete;
        $enteteAutre['headers'][ContexteEtablissement::HEADER] = (string) $autre->getId();

        $reponse = $client->request('GET', '/api/segments', $enteteAutre);
        // Le garde qui compte : un 403 rendrait l'assertion suivante vraie sans rien prouver.
        self::assertLessThan(
            400,
            $reponse->getStatusCode(),
            'L’appel doit aboutir : sinon « ne contient pas » ne mesure rien.',
        );

        $surAutre = $reponse->getContent(false);
        self::assertStringNotContainsString(
            'Réglage de Piscine A',
            $surAutre,
            'Le réglage d’un autre établissement ne doit pas s’afficher comme celui de l’actif.',
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
