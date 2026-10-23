<?php

declare(strict_types=1);

namespace App\Tests\Reporting\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Compta\ComptaApiTestCase;

/**
 * Billets délivrés : la somme des quantités des lignes de vente scellées, gratuits compris, sur
 * l'établissement actif seulement.
 */
final class SalesStatisticsTest extends ComptaApiTestCase
{
    public function testTicketsIssuedCountSoldUnitsFreeOnesIncluded(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $this->creerVenteValidee($client, $entete, quantite: 2, sessionId: $session['id']);
        $this->venteGratuite($client, $entete, $session['id'], 3);

        $jour = $this->aujourdhui();
        $parJour = $this->stats($client, $entete, $jour, 'jour');
        self::assertSame(5, $parJour['total'], '2 billets payés + 3 gratuits.');
        self::assertCount(1, $parJour['lignes']);
        self::assertSame($jour, $parJour['lignes'][0]['cle']);

        $parHeure = $this->stats($client, $entete, $jour, 'heure');
        self::assertSame(
            (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('H'),
            $parHeure['lignes'][0]['cle'],
            'La tranche horaire est celle de l\'établissement, pas l\'heure UTC du serveur.',
        );

        $parProduit = $this->stats($client, $entete, $jour, 'produit');
        self::assertSame(5, array_sum(array_column($parProduit['lignes'], 'billets')));

        $parPoste = $this->stats($client, $entete, $jour, 'poste');
        self::assertSame(5, $parPoste['lignes'][0]['billets']);
        self::assertNotSame('Vente directe (sans caisse)', $parPoste['lignes'][0]['libelle']);
    }

    public function testAnotherEstablishmentSeesNoneOfIt(): void
    {
        [$client, $entete] = $this->adminSurA();
        $this->creerVenteValidee($client, $entete, quantite: 2);

        $jour = $this->aujourdhui();
        self::assertSame(2, $this->stats($client, $entete, $jour, 'jour')['total'], 'Témoin : A voit ses billets.');

        $enteteB = $entete;
        $enteteB['headers'][ContexteEtablissement::HEADER] = $this->idEtablissement(SocleFixtures::ETAB_B_NOM);
        self::assertSame(0, $this->stats($client, $enteteB, $jour, 'jour')['total'], 'B ne voit pas les billets de A.');
    }

    public function testAUserWithoutRightsOnTheEstablishmentIsRefused(): void
    {
        $client = static::createClient();
        $jeton = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);
        $jour = $this->aujourdhui();

        // Témoin : sur A, où il a un rôle de lecture, la même requête aboutit.
        $client->request('GET', '/reporting/billets-delivres?du=' . $jour . '&au=' . $jour, [
            'auth_bearer' => $jeton,
            'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/reporting/billets-delivres?du=' . $jour . '&au=' . $jour, [
            'auth_bearer' => $jeton,
            'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_B_NOM)],
        ]);
        // Refusé avant même le contrôleur : l'en-tête d'un établissement hors rattachement rend 404
        // (garde globale de l'établissement actif). 403 viendrait du contrôleur ; les deux refusent.
        self::assertContains($client->getResponse()?->getStatusCode(), [403, 404], 'Le lecteur n\'a aucun rôle sur B.');
        self::assertStringNotContainsString('"total"', (string) $client->getResponse()?->getContent(false));
    }

    public function testBadParametersAreRejected(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->request('GET', '/reporting/billets-delivres?du=2026-13-01&au=2026-12-01', $entete);
        self::assertResponseStatusCodeSame(400);
        $client->request('GET', '/reporting/billets-delivres?du=2026-12-02&au=2026-12-01', $entete);
        self::assertResponseStatusCodeSame(400);
        $client->request('GET', '/reporting/billets-delivres?du=2026-12-01&au=2026-12-01&par=couleur', $entete);
        self::assertResponseStatusCodeSame(400);
    }

    private function aujourdhui(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d');
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    private function stats(Client $client, array $entete, string $jour, string $par): array
    {
        $reponse = $client->request('GET', sprintf('/reporting/billets-delivres?du=%s&au=%s&par=%s', $jour, $jour, $par), $entete);
        self::assertResponseIsSuccessful();

        return $reponse->toArray();
    }

    /** @param array<string, mixed> $entete */
    private function venteGratuite(Client $client, array $entete, string $sessionId, int $quantite): void
    {
        $vente = $client->request('POST', '/api/ventes', $entete + [
            'json' => ['session' => '/api/session_caisses/' . $sessionId],
        ])->toArray();
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => $quantite,
                'prixForce' => true,
                'prixUnitaire' => '0.00',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $valide = $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete)->toArray();
        self::assertSame('validee', $valide['statut']);
    }
}
