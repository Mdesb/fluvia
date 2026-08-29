<?php

declare(strict_types=1);

namespace App\Tests\Facturation\Api;

use App\Facturation\Entity\Facture;
use App\Facturation\Enum\NatureFacture;
use App\Facturation\Service\DepositInvoiceHandler;
use App\Tests\Facturation\FacturationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * FACTURES D'ACOMPTE — et la déduction sans laquelle on facture deux fois.
 *
 * Demandé par Maxime : « On n'a pas encore parlé des factures d'acompte mais il faut le mettre en
 * place. » Rien n'existait : aucune occurrence d'« acompte » dans tout le dépôt.
 *
 * ⚠ LE PREMIER TEST EST LE SEUL QUI COMPTE VRAIMENT. Un acompte qui ne se déduit pas facture le
 * client une fois à l'acompte et une fois au solde. C'est pour cela que la déduction est un effet de
 * l'émission et non une case à cocher : elle ne doit pas pouvoir être oubliée.
 */
final class DepositInvoiceTest extends FacturationApiTestCase
{
    /**
     * L'ACOMPTE ÉMIS EST DÉDUIT DU SOLDE, ET LE TOTAL RESTE CELUI DE LA COMMANDE.
     *
     * Commande à 120 € TTC, acompte de 30 € HT émis, puis solde émis : le client doit avoir été
     * facturé 120 € au total, pas 156 €.
     */
    public function testUnAcompteEmisEstDeduitDuSolde(): void
    {
        [$client, $entete] = $this->adminSurA();

        $solde = $client->request('POST', '/api/factures', $entete + ['json' => $this->corpsFactureDirecte(100.0)])->toArray();
        $em = $this->em();
        $handler = new DepositInvoiceHandler($em);

        $factureSolde = $em->getRepository(Facture::class)->find($solde['id']);
        self::assertInstanceOf(Facture::class, $factureSolde);
        self::assertSame('100.00', $factureSolde->getTotalHT(), 'témoin : la commande vaut bien 100 € HT');

        $acompte = $handler->creer($factureSolde, '30.00');
        self::assertSame(NatureFacture::Acompte, $acompte->getNature());
        self::assertSame('30.00', $acompte->getTotalHT(), 'l’acompte porte exactement le montant demandé');

        // L'acompte s'émet par le chemin ordinaire : numérotation, écriture, scellement.
        $client->request('POST', '/api/factures/' . $acompte->getId() . '/emettre', $entete);
        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent(false));

        $client->request('POST', '/api/factures/' . $solde['id'] . '/emettre', $entete);
        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent(false));

        $em->clear();
        $apres = $em->getRepository(Facture::class)->find($solde['id']);
        self::assertInstanceOf(Facture::class, $apres);

        self::assertSame('70.00', $apres->getTotalHT(), 'le solde ne réclame que ce qui n’a pas déjà été facturé');

        $acompteRelu = $em->getRepository(Facture::class)->find($acompte->getId());
        self::assertInstanceOf(Facture::class, $acompteRelu);

        $totalFactureAuClient = (float) $acompteRelu->getTotalTTC() + (float) $apres->getTotalTTC();
        self::assertSame(
            120.0,
            $totalFactureAuClient,
            'acompte + solde = la commande. Sans la déduction, le client paierait 156 € pour une commande de 120 €',
        );
    }

    /**
     * UN ACOMPTE EN BROUILLON NE SE DÉDUIT PAS.
     *
     * Un brouillon n'a pas de numéro, le client ne l'a jamais reçu, et rien ne prouve qu'il a payé.
     * Le déduire diminuerait le solde d'une somme que personne n'a versée.
     *
     * ⚠ Ce test est le témoin du premier : sans lui, une déduction qui prendrait TOUS les acomptes,
     * émis ou non, passerait le premier avec les félicitations.
     */
    public function testUnAcompteEnBrouillonNEstPasDeduit(): void
    {
        [$client, $entete] = $this->adminSurA();

        $solde = $client->request('POST', '/api/factures', $entete + ['json' => $this->corpsFactureDirecte(100.0)])->toArray();
        $em = $this->em();

        $factureSolde = $em->getRepository(Facture::class)->find($solde['id']);
        self::assertInstanceOf(Facture::class, $factureSolde);

        (new DepositInvoiceHandler($em))->creer($factureSolde, '30.00');
        // Volontairement NON émis.

        $client->request('POST', '/api/factures/' . $solde['id'] . '/emettre', $entete);
        self::assertResponseIsSuccessful();

        $em->clear();
        $apres = $em->getRepository(Facture::class)->find($solde['id']);
        self::assertInstanceOf(Facture::class, $apres);

        self::assertSame('100.00', $apres->getTotalHT(), 'un brouillon n’est pas un document : rien n’est déduit');
    }

    /**
     * DEUX ACOMPTES NE PEUVENT PAS DÉPASSER LA COMMANDE.
     *
     * ⚠ C'est le contrôle qui protège l'argent. Sans lui, la déduction rendrait le solde NÉGATIF —
     * une facture qui doit de l'argent au client, sans que personne l'ait décidé.
     *
     * Le premier acompte sert de témoin : s'il échouait, le second refus ne prouverait rien.
     */
    public function testDeuxAcomptesNePeuventPasDepasserLaCommande(): void
    {
        [$client, $entete] = $this->adminSurA();

        $solde = $client->request('POST', '/api/factures', $entete + ['json' => $this->corpsFactureDirecte(100.0)])->toArray();
        $em = $this->em();
        $handler = new DepositInvoiceHandler($em);

        $factureSolde = $em->getRepository(Facture::class)->find($solde['id']);
        self::assertInstanceOf(Facture::class, $factureSolde);

        $premier = $handler->creer($factureSolde, '80.00');
        self::assertSame('80.00', $premier->getTotalHT(), 'témoin : le premier acompte passe');

        $this->expectException(UnprocessableEntityHttpException::class);
        $handler->creer($factureSolde, '40.00');
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
