<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Offre\Entity\Produit;
use App\Offre\Enum\StatutProduit;
use App\Reservation\Entity\Creneau;
use App\Reservation\Enum\StatutCreneau;
use App\Tests\Boutique\BoutiqueApiTestCase;

/**
 * UN PRODUIT VENDU À L'HORAIRE NE SE PUBLIE PAS SANS UN CRÉNEAU À VENIR (lot des garde-fous, 08/10).
 *
 * `AjouterLignePanierProcessor` exige un créneau pour un produit `timedEntry` : publié sans créneau
 * ouvert, il s'affiche en boutique et ne peut pas y être acheté. Le témoin est le même produit avec
 * son créneau de lundi prochain (fixture), qui passe.
 */
final class TimedEntryPublicationTest extends BoutiqueApiTestCase
{
    public function testATimedProductWithoutAnOpenSlotIsNotPublishable(): void
    {
        [$client, $header] = $this->adminSurA();
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_TIMED_ENTRY_CODE]);
        $readiness = '/api/produits/'.$produit->getId().'/readiness';

        $codes = array_column($client->request('GET', $readiness, $header)->toArray()['missing'], 'code');
        self::assertNotContains('creneau', $codes, 'témoin : le créneau de lundi prochain suffit');

        $em = $this->em();
        foreach ($em->getRepository(Creneau::class)->findAll() as $creneau) {
            $creneau->setStatut(StatutCreneau::Annule);
        }
        $em->getRepository(Produit::class)->find($produit->getId())?->setStatut(StatutProduit::Brouillon);
        $em->flush();

        $codes = array_column($client->request('GET', $readiness, $header)->toArray()['missing'], 'code');
        self::assertContains('creneau', $codes);
        $client->request('POST', '/api/produits/'.$produit->getId().'/publier', $header);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('créneau', (string) ($client->getResponse()?->toArray(false)['detail'] ?? ''));

        // Au seul guichet, rien ne lit `timedEntry` : la garde n'y exige pas de créneau non plus.
        $em = $this->em();
        $em->getRepository(Produit::class)->find($produit->getId())?->setCanaux(['guichet']);
        $em->flush();
        $codes = array_column($client->request('GET', $readiness, $header)->toArray()['missing'], 'code');
        self::assertNotContains('creneau', $codes);
    }
}
