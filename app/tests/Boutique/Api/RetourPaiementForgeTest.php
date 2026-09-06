<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Entity\SuiviCommandeEnLigne;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Offre\Entity\Produit;
use App\Tests\Boutique\BoutiqueApiTestCase;
use App\Vente\Enum\StatutVente;

/**
 * AUDIT DU 06/09, CONSTAT 1 — le retour de paiement ne se forge plus.
 *
 * Avant : `{"statut":"accepte"}` dans le corps confirmait la commande, billets compris. L'acheteur
 * décidait de l'issue de son propre paiement. Après : le processeur ne lit plus de statut ; il confronte
 * la référence à celle mémorisée à l'initiation, puis demande à l'adaptateur d'ATTESTER — pour le bouchon,
 * par le reçu signé remis à l'initiation ; pour un vrai prestataire, par sa signature.
 *
 * `TunnelAchatSimpleTest` reste le témoin positif : avec le reçu « accepté », la commande se confirme.
 */
final class RetourPaiementForgeTest extends BoutiqueApiTestCase
{
    public function testUnStatutNuDansLeCorpsNeConfirmeRien(): void
    {
        [$client, $panierId, $entete, $paiement] = $this->panierPretAPayer();

        // L'exploit de l'audit, tel quel.
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/retour-paiement', [
            'headers' => $entete,
            'json' => ['referenceTransaction' => $paiement['referenceTransaction'], 'statut' => 'accepte', 'montantCentimes' => $paiement['montantCentimes']],
        ]);

        self::assertResponseStatusCodeSame(422);
        $this->assertVenteToujoursEnCours($panierId);
    }

    public function testUnRecuForgeNeConfirmeRien(): void
    {
        [$client, $panierId, $entete, $paiement] = $this->panierPretAPayer();

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/retour-paiement', [
            'headers' => $entete,
            'json' => ['referenceTransaction' => $paiement['referenceTransaction'], 'recu' => str_repeat('0', 64)],
        ]);

        self::assertResponseStatusCodeSame(422);
        $this->assertVenteToujoursEnCours($panierId);
    }

    /** Le reçu « refusé » est authentique — mais il atteste un refus, pas une acceptation. */
    public function testLeRecuDUneAutreIssueNeConfirmePasLaCommande(): void
    {
        [$client, $panierId, $entete, $paiement] = $this->panierPretAPayer();

        $reponse = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/retour-paiement', [
            'headers' => $entete,
            'json' => ['referenceTransaction' => $paiement['referenceTransaction'], 'recu' => $paiement['simulation']['refuse']],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('echec', $reponse->toArray()['statut']);
        $this->assertVenteToujoursEnCours($panierId);
    }

    public function testUneReferenceQuiNEstPasCelleInitieeEstRefusee(): void
    {
        [$client, $panierId, $entete, $paiement] = $this->panierPretAPayer();

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/retour-paiement', [
            'headers' => $entete,
            'json' => ['referenceTransaction' => 'PSPCB-0000000000000000', 'recu' => $paiement['simulation']['accepte']],
        ]);

        self::assertResponseStatusCodeSame(422);
        $this->assertVenteToujoursEnCours($panierId);
    }

    /** Le témoin, ici aussi : le reçu authentique de l'issue « accepté » confirme — sinon les refus ne prouveraient rien. */
    public function testLeRecuAcceptePourLaBonneReferenceConfirme(): void
    {
        [$client, $panierId, $entete, $paiement] = $this->panierPretAPayer();

        $reponse = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/retour-paiement', [
            'headers' => $entete,
            'json' => ['referenceTransaction' => $paiement['referenceTransaction'], 'recu' => $paiement['simulation']['accepte']],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('confirme', $reponse->toArray()['statut']);
    }

    /** @return array{0: \ApiPlatform\Symfony\Bundle\Test\Client, 1: string, 2: array<string, string>, 3: array<string, mixed>} */
    private function panierPretAPayer(): array
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_SIMPLE_CODE]);
        [$client, $panierId, $jeton] = $this->ouvrirPanierInviteA();
        $entete = [PanierProprietaireGuard::HEADER => $jeton];

        $panier = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes', ['headers' => $entete, 'json' => ['produit' => (string) $produit->getId(), 'quantite' => 1]])->toArray();
        $ligneId = (string) $panier['lignes'][0]['id'];
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/identifier', ['headers' => $entete, 'json' => ['mode' => 'invite', 'email' => 'forge.retour@example.test']]);
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/consentement', ['headers' => $entete, 'json' => ['rgpd' => true]]);
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/beneficiaires', ['headers' => $entete, 'json' => ['lignes' => [['ligneId' => $ligneId, 'beneficiaireSimple' => ['nom' => 'Forge', 'prenom' => 'Retour', 'dateNaissance' => '1990-01-01']]]]]);

        $paiement = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/payer', ['headers' => $entete])->toArray();
        self::assertNotEmpty($paiement['referenceTransaction']);
        self::assertArrayHasKey('simulation', $paiement, 'le bouchon remet ses reçus à l\'initiation');

        return [$client, $panierId, $entete, $paiement];
    }

    private function assertVenteToujoursEnCours(string $panierId): void
    {
        $this->em()->clear();
        $panier = $this->em()->getRepository(PanierEnLigne::class)->find($panierId);
        self::assertInstanceOf(PanierEnLigne::class, $panier);
        $vente = $this->em()->getRepository(SuiviCommandeEnLigne::class)->findOneBy(['panierOrigine' => $panier])?->getVente();
        self::assertNotNull($vente);
        self::assertSame(StatutVente::EnCours, $vente->getStatut(), 'la vente ne doit pas avoir été validée');
        self::assertCount(0, $vente->getPaiements(), 'aucun règlement ne doit avoir été enregistré');
    }
}
