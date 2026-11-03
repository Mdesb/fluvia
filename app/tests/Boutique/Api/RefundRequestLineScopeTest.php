<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Boutique\Entity\DemandeRemboursement;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Offre\Entity\Produit;
use App\Tests\Boutique\BoutiqueApiTestCase;
use App\Vente\Entity\LigneVente;

/**
 * Une demande de remboursement ne vise qu'une ligne de SA commande (D8).
 *
 * La commande était contrôlée (elle doit être celle du client connecté), la ligne non : un simple
 * `find()` rattachait à la demande la ligne de la commande d'un autre client, et la réponse la
 * renvoyait. 404 pour une ligne étrangère, comme pour une ligne qui n'existe pas (D3).
 */
final class RefundRequestLineScopeTest extends BoutiqueApiTestCase
{
    public function testLineOfAnotherCustomersOrderIsNotFound(): void
    {
        $own = $this->buy(['mode' => 'compte', 'email' => BoutiqueFixtures::CLIENT_EMAIL, 'motDePasse' => BoutiqueFixtures::CLIENT_MDP]);
        $foreign = $this->buy(['mode' => 'invite', 'email' => 'autre.client@example.test']);

        self::assertSame(404, $this->requestRefund($own, $this->lineOf($foreign)), 'La ligne d\'une autre commande ne doit pas entrer dans la demande.');
        self::assertSame(0, $this->em()->getRepository(DemandeRemboursement::class)->count([]));
    }

    /** Témoin : la ligne de sa propre commande est acceptée. */
    public function testLineOfTheOwnOrderIsAccepted(): void
    {
        $own = $this->buy(['mode' => 'compte', 'email' => BoutiqueFixtures::CLIENT_EMAIL, 'motDePasse' => BoutiqueFixtures::CLIENT_MDP]);

        self::assertSame(201, $this->requestRefund($own, $this->lineOf($own)));
    }

    private function requestRefund(string $saleId, string $lineId): int
    {
        $client = static::createClient();
        $token = $this->jeton($client, BoutiqueFixtures::CLIENT_EMAIL, BoutiqueFixtures::CLIENT_MDP);

        return $client->request('POST', '/api/boutique/demandes-remboursement', [
            'auth_bearer' => $token,
            'json' => ['vente' => $saleId, 'ligne' => $lineId, 'motif' => 'Empêchement'],
        ])->getStatusCode();
    }

    private function lineOf(string $saleId): string
    {
        $ligne = $this->em()->getRepository(LigneVente::class)->findOneBy(['vente' => $saleId]);
        self::assertInstanceOf(LigneVente::class, $ligne);

        return (string) $ligne->getId();
    }

    /** @param array<string, string> $identification */
    private function buy(array $identification): string
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_SIMPLE_CODE]);
        [$client, $panierId, $jeton] = $this->ouvrirPanierInviteA();
        $entete = ['headers' => [PanierProprietaireGuard::HEADER => $jeton]];
        $url = '/api/boutique/paniers/' . $panierId;

        $ligneId = (string) $client->request('POST', $url . '/lignes', $entete + ['json' => ['produit' => (string) $produit->getId()]])->toArray()['lignes'][0]['id'];
        $client->request('POST', $url . '/identifier', $entete + ['json' => $identification]);
        $client->request('POST', $url . '/consentement', $entete + ['json' => ['mentionVersion' => 'mention-test']]);
        $client->request('POST', $url . '/beneficiaires', $entete + ['json' => ['lignes' => [['ligneId' => $ligneId, 'beneficiaireSimple' => ['nom' => 'Martin', 'prenom' => 'Camille']]]]]);
        $paiement = $client->request('POST', $url . '/payer', $entete)->toArray();
        $retour = $client->request('POST', $url . '/retour-paiement', $entete + ['json' => [
            'referenceTransaction' => $paiement['referenceTransaction'],
            'recu' => $paiement['simulation']['accepte'],
        ]]);
        self::assertResponseIsSuccessful();

        return (string) $retour->toArray()['vente'];
    }
}
