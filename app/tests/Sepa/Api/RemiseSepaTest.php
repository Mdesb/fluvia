<?php

declare(strict_types=1);

namespace App\Tests\Sepa\Api;

use App\Sepa\Entity\LigneRemiseSepa;
use App\Sepa\Entity\MandatSepa;
use App\Sepa\Entity\RemiseSepa;
use App\Tests\Sepa\SepaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `RemiseSepa` (plan-sepa.md §2/§6/§8) : la remise **régie** de démonstration (fixtures, générée via
 * `GenerationRemiseHandler`) prouve la réutilisation bi-régime — `UltmtCdtr` présent, `Cdtr` =
 * collectivité, `NbOfTxs`/`CtrlSum` exacts. Téléchargement du pain.008 (`GET
 * /sepa/remises/{id}/pain008`). `POST /sepa/remises/generer` gère proprement le cas « aucune échéance
 * due » (établissement sans verticale SEPA branchée dans ce jeu de test).
 */
final class RemiseSepaTest extends SepaApiTestCase
{
    public function testRemiseRegieDeDemoContientUltmtCdtrEtCdtrEstLaCollectivite(): void
    {
        [$client, $entete] = $this->adminSurA();

        $remise = $this->remiseDemoRegie();
        self::assertSame('transmise', $remise->getStatut()->value);
        self::assertSame(1, $remise->getNbTxs());
        self::assertSame(6300, $remise->getCtrlSumCentimes());
        self::assertSame('FRST', $remise->getSeqTp()?->value, 'Mandat jamais collecté avant cette remise.');

        $client->request('GET', '/api/remise_sepas/' . $remise->getId(), $entete);
        self::assertResponseIsSuccessful();
        $corps = $client->getResponse()->toArray();
        self::assertSame(1, $corps['nbTxs']);
        self::assertSame('transmise', $corps['statut']);
    }

    public function testTelechargementPain008DeLaRemiseRegieContientUltmtCdtrEtAmdmntInd(): void
    {
        [$client, $entete] = $this->adminSurA();
        $remise = $this->remiseDemoRegie();

        $client->request('GET', '/sepa/remises/' . $remise->getId() . '/pain008', $entete);
        self::assertResponseIsSuccessful();
        $headers = $client->getResponse()->getHeaders();
        self::assertStringContainsString('application/xml', $headers['content-type'][0] ?? '');

        $xml = $client->getResponse()->getContent();
        self::assertStringContainsString('<UltmtCdtr>', $xml);
        self::assertStringContainsString('COLLECTIVITE DEMO / VILLE-MODELE', $xml);
        self::assertStringContainsString('<AmdmntInd>false</AmdmntInd>', $xml);
        self::assertStringContainsString('urn:iso:std:iso:20022:tech:xsd:pain.008.001.02', $xml);

        // IBAN réel jamais présent dans le fichier téléchargé (placeholder uniquement, garde §4 spec).
        self::assertStringNotContainsString('FR7630006000011234567890189', $xml);
    }

    public function testTelechargementPain008RefuseHorsPerimetreEtablissement(): void
    {
        [$clientB, $enteteB] = $this->adminSurB();
        $remise = $this->remiseDemoRegie();

        // La remise appartient à l'établissement A (régie) : accès refusé depuis le contexte B.
        $clientB->request('GET', '/sepa/remises/' . $remise->getId() . '/pain008', $enteteB);
        self::assertResponseStatusCodeSame(403);
    }

    public function testGenererRemiseSansEcheanceDueRenvoie422(): void
    {
        [$client, $entete] = $this->adminSurB();

        // Établissement B (privé) : aucune verticale SEPA n'a d'échéances dues dans ce jeu de test
        // (Sport non chargé par `SepaApiTestCase`) — le point d'entrée générique gère le cas proprement.
        $client->request('POST', '/api/sepa/remises/generer', $entete + ['json' => []]);
        self::assertResponseStatusCodeSame(422);
    }

    private function remiseDemoRegie(): RemiseSepa
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $mandat = $em->getRepository(MandatSepa::class)->findOneBy(['rum' => 'RUM-DEMO-REGIE-0001']);
        self::assertInstanceOf(MandatSepa::class, $mandat);
        $ligne = $em->getRepository(LigneRemiseSepa::class)->findOneBy(['mandat' => $mandat]);
        self::assertInstanceOf(LigneRemiseSepa::class, $ligne);
        $remise = $ligne->getRemise();
        self::assertInstanceOf(RemiseSepa::class, $remise);

        return $remise;
    }
}
