<?php

declare(strict_types=1);

namespace App\Tests\Sepa\Api;

use App\Sepa\Entity\LigneRemiseSepa;
use App\Sepa\Entity\MandatSepa;
use App\Tests\Sepa\SepaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `RejetSepa` (plan-sepa.md §2/§4/§6) : saisie/simulation manuelle d'un retour SEPA en attendant le
 * retour bancaire réel (aucun parser pain.002 réel, §9 du plan).
 */
final class RejetSepaTest extends SepaApiTestCase
{
    public function testDeclarerUnRejetManuelSurUneLigneDeRemise(): void
    {
        [$client, $entete] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $mandat = $em->getRepository(MandatSepa::class)->findOneBy(['rum' => 'RUM-DEMO-REGIE-0001']);
        self::assertInstanceOf(MandatSepa::class, $mandat);
        $ligne = $em->getRepository(LigneRemiseSepa::class)->findOneBy(['mandat' => $mandat]);
        self::assertInstanceOf(LigneRemiseSepa::class, $ligne);

        $client->request('POST', '/api/rejet_sepas', $entete + ['json' => [
            'ligne' => '/api/ligne_remise_sepas/' . $ligne->getId(),
            'codeMotif' => 'AM04',
            'libelleMotif' => 'Fonds insuffisants',
            'dateRejet' => '2026-01-15',
        ]]);
        self::assertResponseIsSuccessful();
        $rejet = $client->getResponse()->toArray();

        self::assertSame('AM04', $rejet['codeMotif']);
        self::assertSame($mandat->getRum(), $rejet['mndtId']);
        self::assertSame($ligne->getEndToEndId(), $rejet['endToEndId']);

        $client->request('GET', '/api/rejet_sepas', $entete);
        self::assertResponseIsSuccessful();
        $liste = $client->getResponse()->toArray();
        $membres = $liste['member'] ?? $liste['hydra:member'];
        self::assertCount(1, $membres);
    }

    public function testCodeMotifRequisRenvoie422(): void
    {
        [$client, $entete] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $ligne = $em->getRepository(LigneRemiseSepa::class)->findOneBy([]);
        self::assertInstanceOf(LigneRemiseSepa::class, $ligne);

        $client->request('POST', '/api/rejet_sepas', $entete + ['json' => [
            'ligne' => '/api/ligne_remise_sepas/' . $ligne->getId(),
        ]]);
        self::assertResponseStatusCodeSame(422);
    }
}
