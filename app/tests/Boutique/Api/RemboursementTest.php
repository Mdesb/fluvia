<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Offre\Entity\Produit;
use App\Tests\Boutique\BoutiqueApiTestCase;
use App\Vente\Entity\Avoir;
use App\Vente\Enum\StatutVente;

/**
 * Demande de remboursement en ligne — jamais automatique (US-L8-12, RG-M3-15, CA-17).
 */
final class RemboursementTest extends BoutiqueApiTestCase
{
    private function acheterEnTantQueTitulaireDeCompte(): string
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_SIMPLE_CODE]);

        [$client, $panierId, $jeton] = $this->ouvrirPanierInviteA();
        $entete = [PanierProprietaireGuard::HEADER => $jeton];

        $reponse = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes', [
            'headers' => $entete,
            'json' => ['produit' => (string) $produit->getId()],
        ]);
        $ligneId = (string) $reponse->toArray()['lignes'][0]['id'];

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/identifier', [
            'headers' => $entete,
            'json' => ['mode' => 'compte', 'email' => BoutiqueFixtures::CLIENT_EMAIL, 'motDePasse' => BoutiqueFixtures::CLIENT_MDP],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/consentement', ['headers' => $entete, 'json' => ['rgpd' => true]]);
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/beneficiaires', [
            'headers' => $entete,
            'json' => ['lignes' => [['ligneId' => $ligneId, 'beneficiaireSimple' => ['nom' => 'Martin', 'prenom' => 'Camille']]]],
        ]);

        $reponsePayer = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/payer', ['headers' => $entete]);
        self::assertResponseIsSuccessful();
        $donneesPaiement = $reponsePayer->toArray();

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/retour-paiement', [
            'headers' => $entete,
            'json' => ['referenceTransaction' => $donneesPaiement['referenceTransaction'], 'recu' => $donneesPaiement['simulation']['accepte']],
        ]);
        self::assertResponseIsSuccessful();

        return (string) $client->getResponse()->toArray()['vente'];
    }

    public function testCa17DemandeAccepteeGenereUnAvoirRefuseeCommuniqueUnMotif(): void
    {
        $venteId = $this->acheterEnTantQueTitulaireDeCompte();

        $clientTitulaire = static::createClient();
        $token = $this->jeton($clientTitulaire, BoutiqueFixtures::CLIENT_EMAIL, BoutiqueFixtures::CLIENT_MDP);

        $reponse = $clientTitulaire->request('POST', '/api/boutique/demandes-remboursement', [
            'auth_bearer' => $token,
            'json' => ['vente' => $venteId, 'motif' => 'Erreur de créneau'],
        ]);
        self::assertResponseIsSuccessful();
        $demande = $reponse->toArray();
        self::assertSame('recue', $demande['statut'], 'CA-17 : aucun remboursement automatique.');
        $demandeId = (string) $demande['id'];

        [$clientResponsable, $enteteResponsable] = $this->responsableSurA();
        $clientResponsable->request('POST', '/api/boutique/demandes-remboursement/' . $demandeId . '/accepter', $enteteResponsable);
        self::assertResponseIsSuccessful();
        $resultat = $clientResponsable->getResponse()->toArray();
        self::assertSame('acceptee', $resultat['statut']);
        $avoirs = $this->em()->getRepository(Avoir::class)->findAll();
        self::assertCount(1, $avoirs, 'CA-17 : acceptation déclenche un avoir M2 (ContrePassationHandler réutilisé).');

        $vente = $this->em()->getRepository(\App\Vente\Entity\Vente::class)->find($venteId);
        self::assertSame(StatutVente::AvoirEmis, $vente->getStatut());
    }

    public function testCa17DemandeRefuseeCommuniqueLeMotif(): void
    {
        $venteId = $this->acheterEnTantQueTitulaireDeCompte();

        $clientTitulaire = static::createClient();
        $token = $this->jeton($clientTitulaire, BoutiqueFixtures::CLIENT_EMAIL, BoutiqueFixtures::CLIENT_MDP);
        $demande = $clientTitulaire->request('POST', '/api/boutique/demandes-remboursement', [
            'auth_bearer' => $token,
            'json' => ['vente' => $venteId, 'motif' => 'Changement d\'avis'],
        ])->toArray();

        [$clientResponsable, $enteteResponsable] = $this->responsableSurA();
        $clientResponsable->request('POST', '/api/boutique/demandes-remboursement/' . $demande['id'] . '/refuser', $enteteResponsable + [
            'json' => ['motifRefus' => 'Délai de rétractation dépassé'],
        ]);
        self::assertResponseIsSuccessful();
        $resultat = $clientResponsable->getResponse()->toArray();
        self::assertSame('refusee', $resultat['statut']);
        self::assertSame('Délai de rétractation dépassé', $resultat['motifRefus']);
    }

    /** @return array{0: \ApiPlatform\Symfony\Bundle\Test\Client, 1: array<string, mixed>} */
    private function responsableSurA(): array
    {
        $client = static::createClient();
        $token = $this->jeton($client, BoutiqueFixtures::RESPONSABLE_EMAIL, BoutiqueFixtures::RESPONSABLE_MDP);
        $idA = $this->idEtablissement(\App\DataFixtures\SocleFixtures::ETAB_A_NOM);

        return [$client, ['auth_bearer' => $token, 'headers' => [\App\Securite\Service\ContexteEtablissement::HEADER => $idA]]];
    }
}
