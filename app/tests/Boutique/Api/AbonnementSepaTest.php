<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Offre\Entity\Produit;
use App\Sepa\Entity\MandatSepa;
use App\Tests\Boutique\BoutiqueApiTestCase;

/**
 * Achat d'abonnement + mandat SEPA en ligne — compte obligatoire (US-L8-09, RG-M3-12/17, CA-13).
 */
final class AbonnementSepaTest extends BoutiqueApiTestCase
{
    public function testCa13InviteBloqueTitulaireDeCompteSouscrit(): void
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_ABONNEMENT_CODE]);

        // Invité (aucune authentification) : bloqué avant paiement.
        $clientInvite = static::createClient();
        $clientInvite->request('POST', '/api/boutique/abonnements/souscrire', [
            'json' => [
                'produit' => (string) $produit->getId(),
                'iban' => 'FR7630006000011234567890189',
                'bicDebiteur' => 'AGRIFRPP',
                'debiteurNom' => 'Client Invité',
            ],
        ]);
        self::assertResponseStatusCodeSame(403, 'CA-13 : achat d\'abonnement en pur parcours invité bloqué (RG-M3-12).');

        // Titulaire de compte : mandat SEPA collecté et rattaché au payeur.
        $client = static::createClient();
        $token = $this->jeton($client, BoutiqueFixtures::CLIENT_EMAIL, BoutiqueFixtures::CLIENT_MDP);
        $reponse = $client->request('POST', '/api/boutique/abonnements/souscrire', [
            'auth_bearer' => $token,
            'json' => [
                'produit' => (string) $produit->getId(),
                'iban' => 'FR7630006000011234567890189',
                'bicDebiteur' => 'AGRIFRPP',
                'debiteurNom' => 'Camille Martin',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $donnees = $reponse->toArray();
        self::assertSame('validee', $donnees['statut']);

        $mandats = $this->em()->getRepository(MandatSepa::class)->findBy(['debiteurNom' => 'Camille Martin']);
        self::assertCount(1, $mandats, 'CA-13 : mandat SEPA créé (IBAN jamais en clair, TokenisationIbanInterface).');
        self::assertSame('0189', $mandats[0]->getIban4Derniers());
    }
}
