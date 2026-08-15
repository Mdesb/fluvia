<?php

declare(strict_types=1);

namespace App\Tests\Crm\Api;

use App\Crm\DataFixtures\CrmFixtures;
use App\Crm\Entity\Client;
use App\Crm\Entity\PorteMonnaieVirtuel;
use App\Tests\Crm\CrmApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * RG-M4-03, §2.2 plan-crm.md : débit PMV atomique — même patron de preuve que
 * `App\Tests\Acces\Api\ValidationPassageTest::testConcurrenceDecrementAtomiqueEviteDoubleDecompte`
 * (UPDATE conditionnel : la 2ᵉ tentative sur un solde tout juste insuffisant échoue proprement, sans
 * solde négatif).
 */
final class PmvConcurrenceTest extends CrmApiTestCase
{
    public function testDeuxDebitsSurSoldeToutJusteSuffisantUnSeulReussit(): void
    {
        [$client, $entete] = $this->adminSurA();
        $payeurId = $this->idPayeur();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $payeur = $this->entite(Client::class, ['email' => CrmFixtures::PAYEUR_EMAIL]);
        $pmv = $this->entite(PorteMonnaieVirtuel::class, ['client' => $payeur]);
        $pmv->setSolde('10.00'); // Tout juste suffisant pour UN débit de 6.00, pas deux.
        $em->flush();

        // Quantité 2 (total 11.00) : un règlement partiel de 6.00 en PMV ne déclenche pas la garde
        // « rendu de monnaie » (6.00 < resteAPayer), isolant l'atomicité du débit testée ici. Un seul
        // point de vente n'autorise qu'une session active à la fois (RG-M2-01) : même session réutilisée.
        $session = $this->ouvrirSession($client, $entete);
        $venteA = $this->creerVenteAvecClient($client, $entete, '/api/clients/' . $payeurId, 2, $session['id']);
        $client->request('POST', '/api/ventes/' . $venteA['id'] . '/paiements', $entete + ['json' => ['moyen' => 'pmv', 'montant' => '6.00']]);
        self::assertResponseIsSuccessful();

        $venteB = $this->creerVenteAvecClient($client, $entete, '/api/clients/' . $payeurId, 2, $session['id']);
        $refus = $client->request('POST', '/api/ventes/' . $venteB['id'] . '/paiements', $entete + ['json' => ['moyen' => 'pmv', 'montant' => '6.00']]);
        self::assertSame(422, $refus->getStatusCode(), 'Le second débit doit être refusé (solde restant 4.00 < 6.00).');

        $em->clear();
        $pmvFinal = $this->entite(PorteMonnaieVirtuel::class, ['client' => $payeur]);
        self::assertSame('4.00', $pmvFinal->getSolde(), 'Un seul débit appliqué, jamais de solde négatif.');
    }
}
