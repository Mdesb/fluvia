<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Security\PanierProprietaireGuard;
use App\Tests\Boutique\BoutiqueApiTestCase;

/**
 * LE PANIER RATTACHÉ À UN COMPTE NEUF EST PROUVÉ PAR SON JETON.
 *
 * Dette gelée depuis le 22/08 dans la ligne de base du cloisonnement, remontée le 06/09 quand sa ligne a
 * bougé : `POST /boutique/comptes` acceptait un `panier` par identifiant et le rattachait au compte créé,
 * sans preuve. N'importe qui pouvait s'annexer le panier d'un autre en devinant son identifiant — et un
 * identifiant de panier circule dans les URL. Le jeton `X-Panier-Token` est la preuve, comme partout.
 *
 * Les deux tests sont le même défaut vu des deux côtés : sans le second, un garde qui refuserait tout
 * passerait le premier.
 */
final class RattachementPanierCompteTest extends BoutiqueApiTestCase
{
    public function testSansLeJetonDuPanierLeRattachementEstRefuse(): void
    {
        [$client, $panierId, ] = $this->ouvrirPanierInviteA();

        $client->request('POST', '/api/boutique/comptes', [
            'json' => $this->corps('sans.jeton@example.test', $panierId),
        ]);

        self::assertResponseStatusCodeSame(403);
        $this->em()->clear();
        $panier = $this->em()->getRepository(PanierEnLigne::class)->find($panierId);
        self::assertNotNull($panier);
        self::assertNull($panier->getCompteClient(), 'le panier ne doit pas avoir changé de mains');
    }

    public function testAvecLeJetonDuPanierLeRattachementReussit(): void
    {
        [$client, $panierId, $jeton] = $this->ouvrirPanierInviteA();

        $client->request('POST', '/api/boutique/comptes', [
            'headers' => [PanierProprietaireGuard::HEADER => $jeton],
            'json' => $this->corps('avec.jeton@example.test', $panierId),
        ]);

        self::assertResponseIsSuccessful();
        $this->em()->clear();
        $panier = $this->em()->getRepository(PanierEnLigne::class)->find($panierId);
        self::assertNotNull($panier?->getCompteClient(), 'le panier suit son propriétaire');
    }

    /** @return array<string, string> */
    private function corps(string $email, string $panierId): array
    {
        return [
            'vitrine' => $this->idVitrineA(), 'email' => $email, 'motDePasse' => 'MotDePasse#Long#1',
            'nom' => 'Panier', 'prenom' => 'Rattaché', 'dateNaissance' => '1990-01-01', 'panier' => $panierId,
        ];
    }
}
