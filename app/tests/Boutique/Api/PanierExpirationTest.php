<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Enum\StatutPanier;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Offre\Entity\Produit;
use App\Reservation\Entity\Reservation;
use App\Tests\Boutique\BoutiqueApiTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Panier expiré : libération automatique du stock/créneau, jamais de `Reservation` créée, relance
 * si contact connu (US-L8-03, RG-M3-03/16, CA-3).
 */
final class PanierExpirationTest extends BoutiqueApiTestCase
{
    public function testCa3PanierExpireLibereLaPlaceEtRelanceSiContactConnu(): void
    {
        $produit = $this->entite(Produit::class, ['code' => BoutiqueFixtures::PRODUIT_TIMED_ENTRY_CODE]);

        [$client, $panierId, $jeton] = $this->ouvrirPanierInviteA();
        $entete = [PanierProprietaireGuard::HEADER => $jeton];

        $creneaux = $client->request('GET', '/api/boutique/produits/' . $produit->getId() . '/creneaux')->toArray();
        $creneauId = $creneaux['creneaux'][0]['creneau'];

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes', [
            'headers' => $entete,
            'json' => ['produit' => (string) $produit->getId(), 'creneau' => $creneauId],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/boutique/paniers/' . $panierId . '/identifier', [
            'headers' => $entete,
            'json' => ['mode' => 'invite', 'email' => 'relance.panier@example.test'],
        ]);
        self::assertResponseIsSuccessful();

        // Force l'expiration (délai dépassé).
        $em = $this->em();
        $panier = $em->getRepository(PanierEnLigne::class)->find($panierId);
        self::assertInstanceOf(PanierEnLigne::class, $panier);
        $panier->setDateExpiration(new \DateTimeImmutable('-1 minute'));
        $em->flush();
        $em->clear();

        $application = new Application(static::getContainer()->get('kernel'));
        $commande = $application->find('boutique:liberer-paniers-expires');
        $tester = new CommandTester($commande);
        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());

        $em->clear();
        $panierApres = $em->getRepository(PanierEnLigne::class)->find($panierId);
        self::assertSame(StatutPanier::Expire, $panierApres->getStatut());
        self::assertTrue($panierApres->isRelanceEnvoyee(), 'RG-M3-16 : relance envoyée (contact connu).');

        $reservations = $em->getRepository(Reservation::class)->createQueryBuilder('r')
            ->andWhere('r.creneau = :creneau')->setParameter('creneau', $creneauId, 'uuid')
            ->getQuery()->getResult();
        self::assertCount(0, $reservations, 'CA-3 : aucune Reservation créée pour un panier jamais payé.');
    }
}
