<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Tests\Vente\VenteApiTestCase;

/**
 * `GET /api/ventes/{id}/ticket.pdf` — la route qui manquait au rendu.
 *
 * ⚠ **CE QUE CES TESTS NE PROUVENT PAS, ET IL FAUT LE DIRE.** Le harnais parle au noyau Symfony et
 * ne traverse jamais nginx. Ils prouvent donc que le contrôleur répond, pas que le chemin y mène
 * depuis l'extérieur. Le préfixe `/api` a été choisi pour ça — il figure dans la `location` de
 * `billetterie-preprod-preprod.conf`, contrairement à `/ventes` — mais la preuve du chemin se fait
 * après déploiement, par un appel réel. Deux moitiés, deux preuves.
 */
final class TicketPdfRouteTest extends VenteApiTestCase
{
    /** Le PDF sort, avec le bon type et un nom de fichier. */
    public function testLaRouteRendUnPdf(): void
    {
        [$client, $entete] = $this->adminSurA();
        $venteId = $this->venteValidee($client, $entete);

        $reponse = $client->request('GET', '/api/ventes/' . $venteId . '/ticket.pdf', $entete);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF-', $reponse->getContent());
    }

    /**
     * ⚠ LE GET NE MARQUE RIEN — deux appels rendent le même document.
     *
     * Une route qui produirait un effet de bord à chaque lecture ferait grimper un compteur de
     * duplicata à chaque rafraîchissement d'onglet. Le POST décide ; ce GET rend.
     */
    public function testDeuxLecturesNeChangentRien(): void
    {
        [$client, $entete] = $this->adminSurA();
        $venteId = $this->venteValidee($client, $entete);

        $avant = $client->request('GET', '/api/ventes/' . $venteId, $entete)->toArray()['imprime'];
        $client->request('GET', '/api/ventes/' . $venteId . '/ticket.pdf', $entete);
        $apres = $client->request('GET', '/api/ventes/' . $venteId, $entete)->toArray()['imprime'];

        self::assertSame($avant, $apres, 'Lire un ticket ne l\'imprime pas.');
    }

    /** Une vente inconnue : 404, jamais une page HTML ni une erreur bavarde. */
    public function testUneVenteInconnueRend404(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('GET', '/api/ventes/11111111-1111-1111-1111-111111111111/ticket.pdf', $entete);

        self::assertResponseStatusCodeSame(404);
    }

    /** Un identifiant qui n'est pas un UUID ne fait pas tomber le serveur. */
    public function testUnIdentifiantMalformeRend404(): void
    {
        [$client, $entete] = $this->adminSurA();

        $client->request('GET', '/api/ventes/pas-un-uuid/ticket.pdf', $entete);

        self::assertResponseStatusCodeSame(404);
    }

    /** @param array<string, mixed> $entete */
    private function venteValidee(object $client, array $entete): string
    {
        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->creerVente($client, $entete, $session['id']);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_CARTE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + [
            'json' => ['moyen' => 'especes', 'montant' => '45.00'],
        ]);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete + ['json' => []]);

        return $vente['id'];
    }
}
