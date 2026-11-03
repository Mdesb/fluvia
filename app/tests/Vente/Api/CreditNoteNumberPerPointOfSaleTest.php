<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Caisse\Entity\Caisse;
use App\Caisse\Entity\PointDeVente;
use App\Caisse\Enum\EtatCaisse;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Organisation\Entity\Etablissement;
use App\Tests\Vente\VenteApiTestCase;
use App\Vente\DataFixtures\VenteFixtures;
use Doctrine\ORM\EntityManagerInterface;

/**
 * UN AVOIR DE CAISSE PREND SON NUMÉRO DANS LA SÉRIE DE SON POINT DE VENTE.
 *
 * Mesuré le 08/10/2026 : `GenerateurNumero::numeroAvoir()` comptait TOUS les avoirs de la plateforme,
 * tous clients confondus. Le premier avoir d'un point de vente portait donc le rang que lui laissaient
 * les autres : des trous dans chaque série, et le volume d'un client lisible par un autre.
 *
 * La série suit le point de vente, comme la chaîne NF525 (`uniq_op_pdv_sequence`), les sessions et
 * les ventes directes (`S-<pdv>-…`, `D-<pdv>-…`).
 */
final class CreditNoteNumberPerPointOfSaleTest extends VenteApiTestCase
{
    public function testEachPointOfSaleIssuesCreditNoteNumberOneOfItsOwnSeries(): void
    {
        [$client, $headers] = $this->adminSurA();
        $first = $this->cancel($client, $headers, $this->ouvrirSession($client, $headers)['id']);

        [$pointOfSale, $till] = $this->secondPointOfSale();
        $session = $client->request('POST', '/api/sessions-caisse/ouvrir', $headers + ['json' => [
            'pointDeVente' => '/api/point_de_ventes/' . $pointOfSale,
            'caisse' => '/api/caisses/' . $till,
            'regisseur' => '/api/utilisateurs/' . $this->idAdmin(),
            'codeRegisseur' => 'CODE-REGIE-2026',
            'fondDeCaisse' => '50.00',
        ]])->toArray();
        $second = $this->cancel($client, $headers, $session['id']);

        self::assertStringEndsWith('-00001', $second, 'le premier avoir du second point de vente ouvre sa série, quoi qu ait fait le premier');
        self::assertSame('AV-' . $this->prefix($this->idPointDeVente()) . '-00001', $first);
        self::assertSame('AV-' . $this->prefix($pointOfSale) . '-00001', $second);
    }

    /** Vend une entrée sur la session, l'annule, et rend le numéro de l'avoir. */
    private function cancel(Client $client, array $headers, string $sessionId): string
    {
        $sale = $this->creerVente($client, $headers, $sessionId);
        $client->request('POST', '/api/ventes/' . $sale['id'] . '/lignes', $headers + ['json' => [
            'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE),
            'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
            'quantite' => 1,
        ]]);
        $client->request('POST', '/api/ventes/' . $sale['id'] . '/paiements', $headers + ['json' => ['moyen' => 'especes']]);
        $client->request('POST', '/api/ventes/' . $sale['id'] . '/valider', $headers + ['json' => []]);
        self::assertResponseIsSuccessful();

        $response = $client->request('POST', '/api/ventes/' . $sale['id'] . '/annuler', $headers + ['json' => ['motif' => 'Erreur de saisie']]);
        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));

        return $response->toArray()['numero'];
    }

    /** @return array{0: string, 1: string} point de vente et caisse, sur le même site que le premier */
    private function secondPointOfSale(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $site = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        $pointOfSale = (new PointDeVente())->setLibelle('Second guichet')->setEtablissement($site)
            ->setSeuilImpression(VenteFixtures::SEUIL_IMPRESSION)->setMoyensAutorises(VenteFixtures::MOYENS);
        $till = (new Caisse())->setLibelle('Caisse 2')->setPointDeVente($pointOfSale)->setEtat(EtatCaisse::Securisee);
        $em->persist($pointOfSale);
        $em->persist($till);
        $em->flush();

        return [(string) $pointOfSale->getId(), (string) $till->getId()];
    }

    private function prefix(string $pointOfSaleId): string
    {
        return substr(strtoupper($pointOfSaleId), 0, 8);
    }
}
