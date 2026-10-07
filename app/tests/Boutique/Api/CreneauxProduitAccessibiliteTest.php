<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Offre\Entity\Produit;
use App\Tests\Boutique\BoutiqueApiTestCase;

/**
 * Revue de sécurité — faille majeure (#4) : les créneaux d'un produit non publié (brouillon) ou hors
 * canal `en_ligne` ne doivent jamais être exposés publiquement.
 */
final class CreneauxProduitAccessibiliteTest extends BoutiqueApiTestCase
{
    public function testCreneauxDUnProduitBrouillonRepond404(): void
    {
        // Produit `PRD-ENTREE01` (OffreFixtures) : rattaché à l'établissement A, canal `en_ligne`
        // inclus. Publié par les fixtures depuis le 06/10/2026 (la caisse le vend) : on le remet
        // ici en `brouillon`, explicitement — c'est la précondition de ce test.
        $produitBrouillon = $this->entite(Produit::class, ['code' => 'PRD-ENTREE01']);
        $produitBrouillon->setStatut(\App\Offre\Enum\StatutProduit::Brouillon);
        $this->em()->flush();

        $client = static::createClient();
        $client->request('GET', '/api/boutique/produits/' . (string) $produitBrouillon->getId() . '/creneaux');
        self::assertResponseStatusCodeSame(404, 'RG-M1-07/09 : créneaux d\'un produit non publié introuvables.');
    }
}
