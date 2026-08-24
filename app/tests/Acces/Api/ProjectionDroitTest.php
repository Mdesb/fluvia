<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Port\ProjectionDroitInterface;
use App\Offre\DataFixtures\OffreFixtures;
use App\Organisation\Entity\Etablissement;
use App\Tests\Acces\AccesApiTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Projection locale d'un droit vendu M1/M2 (T4, point ouvert n°9) : `StubProjectionDroit` construit
 * `DroitAcces` à partir d'un `BilletSupport` (M2) émis pour un produit carte multi-entrées (M1) — la
 * projection porte le crédit initial de la carte (RG-M1-04/13).
 */
final class ProjectionDroitTest extends AccesApiTestCase
{
    public function testProjectionDepuisCarteM1DonneUnDroitCarteQuota(): void
    {
        [$client, $entete] = $this->adminSurA();

        $session = $client->request('POST', '/api/sessions-caisse/ouvrir', $entete + [
            'json' => [
                'pointDeVente' => '/api/point_de_ventes/' . $this->idPointDeVente(),
                'caisse' => '/api/caisses/' . $this->idCaisse(),
                'regisseur' => '/api/utilisateurs/' . $this->idAdmin(),
                'codeRegisseur' => 'CODE-REGIE-2026',
                'fondDeCaisse' => '50.00',
            ],
        ])->toArray();

        $vente = $client->request('POST', '/api/ventes', $entete + [
            'json' => ['session' => '/api/session_caisses/' . $session['id']],
        ])->toArray();

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_CARTE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + [
            'json' => ['moyen' => 'especes', 'montant' => '45.00'],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        $venteDetail = $client->request('GET', '/api/ventes/' . $vente['id'], $entete)->toArray();
        self::assertNotEmpty($venteDetail['supports']);
        $supportRef = basename((string) $venteDetail['supports'][0]['id']);

        /** @var ProjectionDroitInterface $projection */
        $projection = static::getContainer()->get(ProjectionDroitInterface::class);
        $etablissement = $this->entite(Etablissement::class, ['nom' => \App\DataFixtures\SocleFixtures::ETAB_A_NOM]);

        $droit = $projection->projeter(Uuid::fromString($supportRef), $etablissement);

        self::assertSame(TypeDroitAcces::CarteQuota, $droit->getSourceType());
        self::assertSame(12, $droit->getCreditRestant(), 'Stock initial de la carte 10=12 (RG-M1-04/13).');
        self::assertSame(StatutProjectionDroit::Valide, $droit->getStatutProjection());
    }
}
