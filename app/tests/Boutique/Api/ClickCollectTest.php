<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Boutique\Entity\RetraitClickCollect;
use App\Boutique\Enum\StatutRetraitClickCollect;
use App\Boutique\Security\PanierProprietaireGuard;
use App\DataFixtures\SocleFixtures;
use App\Offre\Entity\Produit;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Boutique\BoutiqueApiTestCase;
use App\Vente\Entity\BilletSupport;

/**
 * Retrait / click & collect d'un support physique (US-L8-13, RG-M3-18, CA-18).
 */
final class ClickCollectTest extends BoutiqueApiTestCase
{
    public function testCa18QrProvisoireImmediatPuisAppairageAuRetrait(): void
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_SUPPORT_PHYSIQUE_CODE]);

        [$client, $panierId, $jeton] = $this->ouvrirPanierInviteA();
        $entete = [PanierProprietaireGuard::HEADER => $jeton];

        $reponse = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes', [
            'headers' => $entete,
            'json' => ['produit' => (string) $produit->getId()],
        ]);
        $ligneId = (string) $reponse->toArray()['lignes'][0]['id'];

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/identifier', ['headers' => $entete, 'json' => ['mode' => 'invite', 'email' => 'clickcollect@example.test']]);
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/consentement', ['headers' => $entete, 'json' => ['rgpd' => true]]);
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/beneficiaires', [
            'headers' => $entete,
            'json' => ['lignes' => [['ligneId' => $ligneId, 'beneficiaireSimple' => ['nom' => 'Retrait', 'prenom' => 'Test']]]],
        ]);

        $reponsePayer = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/payer', ['headers' => $entete]);
        $donneesPaiement = $reponsePayer->toArray();
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/retour-paiement', [
            'headers' => $entete,
            'json' => ['referenceTransaction' => $donneesPaiement['referenceTransaction'], 'statut' => 'accepte', 'montantCentimes' => $donneesPaiement['montantCentimes']],
        ]);
        self::assertResponseIsSuccessful();
        $venteId = (string) $client->getResponse()->toArray()['vente'];

        $support = $this->em()->getRepository(BilletSupport::class)->findOneBy(['vente' => $this->em()->getRepository(\App\Vente\Entity\Vente::class)->find($venteId)]);
        self::assertInstanceOf(BilletSupport::class, $support, 'CA-18 : billet QR provisoire émis immédiatement à la confirmation.');

        $retrait = $this->em()->getRepository(RetraitClickCollect::class)->findOneBy(['billetSupport' => $support]);
        self::assertInstanceOf(RetraitClickCollect::class, $retrait, 'RG-M3-18 : un support physique déclenche un retrait click & collect.');
        self::assertSame(StatutRetraitClickCollect::ARetirer, $retrait->getStatut());

        // Retrait avec code : support physique appairé, remplace le QR provisoire.
        $etabA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $clientAgent = static::createClient();
        $token = $this->jeton($clientAgent, BoutiqueFixtures::RESPONSABLE_EMAIL, BoutiqueFixtures::RESPONSABLE_MDP);
        $clientAgent->request('POST', '/api/boutique/retraits/' . $retrait->getId() . '/valider', [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => $etabA],
            'json' => ['codeRetrait' => $retrait->getCodeRetrait(), 'identifiantSupportPhysique' => 'RFID-DEMO-01'],
        ]);
        self::assertResponseIsSuccessful();

        $this->em()->clear();
        $retraitApres = $this->em()->getRepository(RetraitClickCollect::class)->find($retrait->getId());
        self::assertSame(StatutRetraitClickCollect::Retire, $retraitApres->getStatut());
        self::assertNotNull($retraitApres->getDateRetrait());
    }
}
