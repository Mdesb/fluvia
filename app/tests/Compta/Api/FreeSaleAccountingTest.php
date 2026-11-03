<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Compta\Adapter\ProjectionVenteDoctrineAdapter;
use App\Offre\DataFixtures\OffreFixtures;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Une vente à 0 € (billet gratuit délivré en caisse) n'a rien à comptabiliser : aucune écriture,
 * aucune anomalie, et surtout **elle n'est pas relue à chaque génération**.
 *
 * Avant ce test, `GenerateurEcrituresHandler` sautait l'écriture vide (`$dto->lignes === []`) sans
 * rien noter : la vente restait « validée non comptabilisée » pour toujours, et chaque génération
 * la rechargeait, projetait ses lignes (une requête par ligne) et la jetait. Un site qui délivre
 * 43 000 billets gratuits par an en aurait relu 43 000 de plus à chaque passage, chaque année.
 */
final class FreeSaleAccountingTest extends ComptaApiTestCase
{
    public function testFreeSaleIsNotReprojectedAtEachGeneration(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        $gratuite = $this->creerVenteGratuiteValidee($client, $entete, $session['id']);
        self::assertSame('validee', $gratuite['statut']);
        self::assertSame(0.0, (float) $gratuite['total']);

        // Témoin positif : une vente payée de la même session, elle, doit être projetée.
        $payee = $this->creerVenteValidee($client, $entete, sessionId: $session['id']);

        $avant = $this->idsProjetes();
        self::assertContains($payee['id'], $avant, 'Témoin : la vente payée doit être à comptabiliser.');
        self::assertNotContains($gratuite['id'], $avant, 'Une vente à 0 € n\'a rien à comptabiliser : elle ne doit pas être projetée.');

        $iri = '/api/profil_exploitants/' . $this->idProfilExploitant();
        $premier = $client->request('POST', '/api/compta/ecritures/generer', $entete + ['json' => ['profilExploitant' => $iri]])->toArray();
        self::assertSame(1, $premier['ecrituresGenerees']);
        self::assertSame([], $premier['anomalies']);

        // Après génération, plus rien n'est en attente — la gratuite pas davantage que la payée.
        self::assertSame([], $this->idsProjetes());
    }

    /** @return list<string> */
    private function idsProjetes(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $projection = new ProjectionVenteDoctrineAdapter($em);
        $ids = [];
        foreach ($projection->ventesValideesNonComptabilisees($this->profilExploitant()) as $dto) {
            $ids[] = (string) $dto->id;
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    private function creerVenteGratuiteValidee(Client $client, array $entete, string $sessionId): array
    {
        $vente = $client->request('POST', '/api/ventes', $entete + [
            'json' => ['session' => '/api/session_caisses/' . $sessionId],
        ])->toArray();

        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 2,
                'prixForce' => true,
                'prixUnitaire' => '0.00',
            ],
        ]);
        self::assertResponseIsSuccessful();

        return $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete)->toArray();
    }
}
