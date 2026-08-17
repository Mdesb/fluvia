<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Boutique\Entity\BilletQrMeta;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Entity\SuiviCommandeEnLigne;
use App\Boutique\Enum\StatutPanier;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Offre\Entity\Produit;
use App\Tests\Boutique\BoutiqueApiTestCase;
use App\Vente\Entity\BilletSupport;
use App\Vente\Enum\StatutVente;

/**
 * Tunnel de commande complet — achat invité simple, régime régie directe (US-L8-04 à 08, RG-M3-06/07/
 * 11/04/14, CA-4/CA-6/CA-7/CA-9/CA-10/CA-11/CA-12).
 */
final class TunnelAchatSimpleTest extends BoutiqueApiTestCase
{
    public function testTunnelCompletJusquAuBilletQr(): void
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_SIMPLE_CODE]);

        [$client, $panierId, $jeton] = $this->ouvrirPanierInviteA();
        $entete = [PanierProprietaireGuard::HEADER => $jeton];

        // Ajout au panier (achat simple, invité — RG-M3-06).
        $reponse = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes', [
            'headers' => $entete,
            'json' => ['produit' => (string) $produit->getId(), 'quantite' => 1],
        ]);
        self::assertResponseIsSuccessful();
        $panier = $reponse->toArray();
        self::assertCount(1, $panier['lignes']);
        $ligneId = (string) $panier['lignes'][0]['id'];

        // CA-4 : identification invité (achat simple sans compte).
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/identifier', [
            'headers' => $entete,
            'json' => ['mode' => 'invite', 'email' => 'invite.tunnel@example.test'],
        ]);
        self::assertResponseIsSuccessful();

        // Étape paiement bloquée tant que le consentement RGPD n'est pas coché (CA-6).
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/payer', ['headers' => $entete]);
        self::assertResponseStatusCodeSame(422);

        // CA-7 : chaque article doit porter un bénéficiaire avant paiement.
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/consentement', [
            'headers' => $entete,
            'json' => ['rgpd' => true],
        ]);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/payer', ['headers' => $entete]);
        self::assertResponseStatusCodeSame(422, 'CA-7 : paiement refusé tant qu\'un bénéficiaire n\'est pas affecté à la ligne.');

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/beneficiaires', [
            'headers' => $entete,
            'json' => ['lignes' => [['ligneId' => $ligneId, 'beneficiaireSimple' => ['nom' => 'Dupont', 'prenom' => 'Jean', 'dateNaissance' => '1990-01-01']]]],
        ]);
        self::assertResponseIsSuccessful();

        // CA-9 : établissement en régie directe -> PayFiP.
        $reponsePayer = $client->request('POST', '/api/boutique/paniers/' . $panierId . '/payer', ['headers' => $entete]);
        self::assertResponseIsSuccessful();
        $donneesPaiement = $reponsePayer->toArray();
        self::assertSame('payfip', $donneesPaiement['moyen']);
        self::assertNotEmpty($donneesPaiement['referenceTransaction']);

        // La Vente M2 existe déjà en_cours (satellite SuiviCommandeEnLigne, §0 décision n°1).
        $panierEntity = $this->em()->getRepository(PanierEnLigne::class)->find($panierId);
        self::assertInstanceOf(PanierEnLigne::class, $panierEntity);
        $suivi = $this->em()->getRepository(SuiviCommandeEnLigne::class)->findOneBy(['panierOrigine' => $panierEntity]);
        self::assertInstanceOf(SuiviCommandeEnLigne::class, $suivi);
        self::assertSame(StatutVente::EnCours, $suivi->getVente()?->getStatut());

        // CA-10 : retour d'échec -> aucun paiement enregistré, panier toujours ouvert, retentative possible.
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/retour-paiement', [
            'headers' => $entete,
            'json' => ['referenceTransaction' => $donneesPaiement['referenceTransaction'], 'statut' => 'refuse', 'montantCentimes' => $donneesPaiement['montantCentimes']],
        ]);
        self::assertResponseIsSuccessful();
        $this->em()->clear();
        $panierApresEchec = $this->em()->getRepository(PanierEnLigne::class)->find($panierId);
        self::assertSame(StatutPanier::Ouvert, $panierApresEchec->getStatut(), 'CA-10 : panier toujours valide après un paiement refusé.');
        $venteApresEchec = $this->em()->getRepository(SuiviCommandeEnLigne::class)->findOneBy(['panierOrigine' => $panierApresEchec])?->getVente();
        self::assertSame(StatutVente::EnCours, $venteApresEchec?->getStatut());
        self::assertCount(0, $venteApresEchec->getPaiements(), 'CA-10 : aucun paiement enregistré en cas d\'échec.');

        // Retentative réussie (CA-11) : billets immédiats, repli QR (CA-12, aucun wallet réel intégré).
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/retour-paiement', [
            'headers' => $entete,
            'json' => ['referenceTransaction' => $donneesPaiement['referenceTransaction'], 'statut' => 'accepte', 'montantCentimes' => $donneesPaiement['montantCentimes']],
        ]);
        self::assertResponseIsSuccessful();
        $confirmation = $client->getResponse()->toArray();
        self::assertSame('confirme', $confirmation['statut']);

        $this->em()->clear();
        $venteId = $confirmation['vente'];
        $vente = $this->em()->getRepository(\App\Vente\Entity\Vente::class)->find($venteId);
        self::assertSame(StatutVente::Validee, $vente->getStatut(), 'CA-11 : la vente est validée (NF525) au paiement réussi.');

        $support = $this->em()->getRepository(BilletSupport::class)->findOneBy(['vente' => $vente]);
        self::assertInstanceOf(BilletSupport::class, $support, 'CA-11 : billet émis immédiatement.');
        $meta = $this->em()->getRepository(BilletQrMeta::class)->findOneBy(['billetSupport' => $support]);
        self::assertInstanceOf(BilletQrMeta::class, $meta);
        self::assertTrue($meta->isRepliQr(), 'CA-12 : repli QR proposé (aucun wallet réel intégré, MVP).');
        self::assertNotEmpty($meta->getQrDynamique());

        // Le billet boutique porte le code de support unique et signé (CA-12), généré automatiquement
        // (aucun override manuel côté tunnel Boutique) — même code que `qrDynamique`.
        self::assertNotEmpty($support->getIdentifiantSupport());
        self::assertSame($support->getIdentifiantSupport(), $meta->getQrDynamique());
        self::assertMatchesRegularExpression('/^QRC-[0-9A-HJKMNP-TV-Z]{16}-[0-9A-F]{10}$/', $support->getIdentifiantSupport());

        $panierFinal = $this->em()->getRepository(PanierEnLigne::class)->find($panierId);
        self::assertSame(StatutPanier::TransformeEnCommande, $panierFinal->getStatut());
    }

    public function testConsentementEtBeneficiairesSansJetonSontRefuses(): void
    {
        [$client, $panierId] = $this->ouvrirPanierInviteA();
        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/consentement', ['json' => ['rgpd' => true]]);
        self::assertResponseStatusCodeSame(403, '⚠ Risque n°3 : jeton de panier requis pour manipuler un panier invité.');
    }
}
