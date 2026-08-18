<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\Billet\GenerateurPdfBillet;
use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Offre\Entity\Produit;
use App\Tests\Boutique\BoutiqueApiTestCase;
use App\Vente\Entity\BilletSupport;
use App\Vente\Entity\Vente;

/**
 * Revue de sécurité — faille mineure (#6) : `GenerateurPdfBillet` ne doit jamais encoder l'UUID brut
 * d'un `BilletSupport` dans le QR en repli d'un `identifiantSupport` manquant (non signé HMAC,
 * prévisible) — une anomalie de données doit lever une exception explicite, jamais fuiter un
 * identifiant brut.
 */
final class GenerateurPdfBilletSignatureTest extends BoutiqueApiTestCase
{
    public function testGenerationDuBilletEchoueExplicitementSiIdentifiantSupportManquant(): void
    {
        $vente = $this->finaliserAchatSimple('sans.identifiant.support@example.test');

        $support = $this->em()->getRepository(BilletSupport::class)->findOneBy(['vente' => $vente]);
        self::assertInstanceOf(BilletSupport::class, $support);

        // Simule une anomalie de données : le code de support signé (CA-12) n'a jamais été généré.
        $support->setIdentifiantSupport(null);
        $this->em()->flush();

        /** @var GenerateurPdfBillet $generateur */
        $generateur = static::getContainer()->get(GenerateurPdfBillet::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/identifiant signé/');
        $generateur->genererPourVente($vente);
    }

    private function finaliserAchatSimple(string $email): Vente
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_SIMPLE_CODE]);

        [$client, $panierId, $jeton] = $this->ouvrirPanierInviteA();
        $entete = [PanierProprietaireGuard::HEADER => $jeton];

        $reponse = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes', [
            'headers' => $entete,
            'json' => ['produit' => (string) $produit->getId(), 'quantite' => 1],
        ]);
        self::assertResponseIsSuccessful();
        $ligneId = (string) $reponse->toArray()['lignes'][0]['id'];

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/identifier', [
            'headers' => $entete,
            'json' => ['mode' => 'invite', 'email' => $email],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/consentement', [
            'headers' => $entete,
            'json' => ['rgpd' => true],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/beneficiaires', [
            'headers' => $entete,
            'json' => ['lignes' => [['ligneId' => $ligneId, 'beneficiaireSimple' => ['nom' => 'Test', 'prenom' => 'QR', 'dateNaissance' => '1990-01-01']]]],
        ]);
        self::assertResponseIsSuccessful();

        $reponsePayer = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/payer', ['headers' => $entete]);
        self::assertResponseIsSuccessful();
        $donneesPaiement = $reponsePayer->toArray();

        $reponseConfirmation = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/retour-paiement', [
            'headers' => $entete,
            'json' => [
                'referenceTransaction' => $donneesPaiement['referenceTransaction'],
                'statut' => 'accepte',
                'montantCentimes' => $donneesPaiement['montantCentimes'],
            ],
        ]);
        self::assertResponseIsSuccessful();
        $confirmation = $reponseConfirmation->toArray();

        $vente = $this->em()->getRepository(Vente::class)->find($confirmation['vente']);
        self::assertInstanceOf(Vente::class, $vente);

        return $vente;
    }
}
