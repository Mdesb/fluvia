<?php

declare(strict_types=1);

namespace App\Tests\Crm\Api;

use App\Tests\Crm\CrmApiTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * LE REJEU D'UN RÈGLEMENT PMV NE DÉBITE PAS DEUX FOIS (G-1, G-4 de la spec du ticket opposable).
 *
 * Le débit du porte-monnaie est un `UPDATE` exécuté sur-le-champ (`PorteMonnaieVirtuelAdapter::
 * debiter()`), hors de l'écriture du `Paiement` : c'est l'effet que la garde de rejeu doit précéder.
 * Le témoin est donc le SOLDE, relu après chaque appel — pas la réponse, qui ne dit rien d'un débit
 * parti avant une écriture refusée.
 *
 * Pendant carte et espèces : `App\Tests\Vente\Api\IdempotenceReglementTest`.
 */
final class PmvIdempotenceTest extends CrmApiTestCase
{
    public function testLeRejeuNeDebitePasDeuxFois(): void
    {
        [$client, $entete] = $this->adminSurA();
        // 9,90 dus, 4,00 réglés : le rejeu tient dans le reste dû. À 6,00, il tomberait sur « aucun
        // rendu de monnaie » avant le débit, et le test serait vert sans la garde.
        $vente = $this->creerVenteAvecClient($client, $entete, '/api/clients/' . $this->idPayeur(), 2);
        $corps = ['json' => ['moyen' => 'pmv', 'montant' => '4.00', 'cleIdempotence' => (string) Uuid::v4()]];

        $premier = $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + $corps)->toArray();
        self::assertSame('46.00', $this->solde($client, $entete));

        $rejeu = $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + $corps)->toArray();
        self::assertSame('46.00', $this->solde($client, $entete), 'Le rejeu ne débite pas une seconde fois.');
        self::assertSame($premier['paiement'], $rejeu['paiement']);
        self::assertTrue($rejeu['dejaEnregistre']);
    }

    /** La clé d'une autre vente : refusée AVANT le débit — le solde le prouve, pas le code HTTP. */
    public function testUneCleDUneAutreVenteNeDebiteRien(): void
    {
        [$client, $entete] = $this->adminSurA();
        $payeur = '/api/clients/' . $this->idPayeur();
        $session = $this->ouvrirSession($client, $entete);
        $premiere = $this->creerVenteAvecClient($client, $entete, $payeur, 2, $session['id']);
        $seconde = $this->creerVenteAvecClient($client, $entete, $payeur, 2, $session['id']);
        $cle = (string) Uuid::v4();

        $client->request('POST', '/api/ventes/' . $premiere['id'] . '/paiements', $entete + [
            'json' => ['moyen' => 'pmv', 'montant' => '6.00', 'cleIdempotence' => $cle],
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('44.00', $this->solde($client, $entete));

        $refus = $client->request('POST', '/api/ventes/' . $seconde['id'] . '/paiements', $entete + [
            'json' => ['moyen' => 'pmv', 'montant' => '3.00', 'cleIdempotence' => $cle],
        ]);
        self::assertSame(422, $refus->getStatusCode());
        self::assertSame('44.00', $this->solde($client, $entete), 'Aucun débit pour une clé refusée.');
    }

    /** Même clé, autre montant : refus, et rien n'est débité (G-4). */
    public function testMemeCleAutreMontantNeDebiteRien(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->creerVenteAvecClient($client, $entete, '/api/clients/' . $this->idPayeur(), 2);
        $cle = (string) Uuid::v4();

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + [
            'json' => ['moyen' => 'pmv', 'montant' => '3.00', 'cleIdempotence' => $cle],
        ]);
        self::assertResponseStatusCodeSame(201);

        $refus = $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + [
            'json' => ['moyen' => 'pmv', 'montant' => '4.00', 'cleIdempotence' => $cle],
        ]);
        self::assertSame(422, $refus->getStatusCode());
        self::assertSame('47.00', $this->solde($client, $entete), 'Un seul débit, celui de la demande d\'origine.');
    }

    /** @param array<string, mixed> $entete */
    private function solde(object $client, array $entete): string
    {
        return $client->request('GET', '/api/clients/' . $this->idPayeur() . '/pmv', $entete)->toArray()['solde'];
    }
}
