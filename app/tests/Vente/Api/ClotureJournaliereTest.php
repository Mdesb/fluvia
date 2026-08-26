<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Tests\Vente\VenteApiTestCase;
use App\Vente\Entity\Vente;
use App\Vente\Nf525\Entity\DailyClosure;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D57 — la clôture journalière NF525 n'est pas la clôture Z, et le dépôt les confondait.
 *
 * Le Z ferme une **session de caisse** : il compte du liquide et constate un écart. Il n'a de sens que
 * là où quelqu'un tient un tiroir. Tant que toute vente passait par une caisse, il faisait office de
 * clôture quotidienne sans que personne ait eu à décider que c'en était une — et un point de vente de
 * vente directe se serait retrouvé **sans clôture quotidienne du tout**.
 *
 * Ce que ces tests protègent n'est pas un chiffre de gestion : c'est le **cumul perpétuel**, le seul
 * mécanisme qui rende une suppression détectable. Un cumul faux n'est pas un cumul approximatif — c'est
 * un contrôle qui répondra « tout va bien » sur une base amputée.
 */
final class ClotureJournaliereTest extends VenteApiTestCase
{
    /** Une journée s'arrête, et l'arrêté totalise ce que la chaîne a scellé. */
    public function testLaClotureArreteLesTotauxDuJour(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->venteValidee($client, $entete, $session['id']);
        $this->antidater($vente['id'], 'yesterday 10:00');

        $cloture = $client->request('POST', '/api/point_de_ventes/' . $this->idPointDeVente() . '/cloture-journaliere', $entete + [
            'json' => ['journee' => (new \DateTimeImmutable('yesterday'))->format('Y-m-d')],
        ])->toArray();
        self::assertResponseIsSuccessful();

        self::assertSame(1, $cloture['salesCount']);
        self::assertSame('0.00', $cloture['previousGrandTotal'], 'Première clôture : le cumul part de zéro.');
        self::assertSame($cloture['dailyTotal'], $cloture['grandTotal'], 'Cumul = report + journée.');
        self::assertNotNull($cloture['lastSequence'], 'L\'arrêté est ancré dans la chaîne, pas seulement dans la table.');
    }

    /**
     * **Le test qui porte la décision** : le cumul se reporte d'une clôture à l'autre.
     *
     * C'est ce report qui rend une disparition visible. Supprimer une vente d'avant-hier laisserait le
     * cumul d'avant-hier plus grand que la somme des ventes qui restent, sans qu'on ait besoin de
     * savoir ce qui manquait.
     */
    public function testLeCumulSeReporteDUneJourneeALAutre(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $session = $this->ouvrirSession($client, $entete);
        $premiere = $this->venteValidee($client, $entete, $session['id']);
        $this->antidater($premiere['id'], '-2 days 10:00');

        $avantHier = $client->request('POST', '/api/point_de_ventes/' . $this->idPointDeVente() . '/cloture-journaliere', $entete + [
            'json' => ['journee' => (new \DateTimeImmutable('-2 days'))->format('Y-m-d')],
        ])->toArray();
        self::assertResponseIsSuccessful();

        $seconde = $this->venteValidee($client, $entete, $session['id']);
        $this->antidater($seconde['id'], 'yesterday 10:00');

        $hier = $client->request('POST', '/api/point_de_ventes/' . $this->idPointDeVente() . '/cloture-journaliere', $entete + [
            'json' => ['journee' => (new \DateTimeImmutable('yesterday'))->format('Y-m-d')],
        ])->toArray();
        self::assertResponseIsSuccessful();

        self::assertSame($avantHier['grandTotal'], $hier['previousGrandTotal'], 'La clôture dit d\'où elle part.');
        self::assertNotSame($hier['previousGrandTotal'], $hier['grandTotal'], 'Et la journée s\'y ajoute.');
    }

    /**
     * Sauter une journée qui porte des ventes est refusé — **le refus le moins évident et le plus
     * important**.
     *
     * Clore le 12 en laissant le 10 ouvert ferait partir le total du 12 du cumul du 9 : les ventes du
     * 10 existeraient en base et seraient absentes de l'arrêté. C'est exactement la signature d'une
     * suppression, produite ici par une clôture dans le désordre — et elle serait entrée dans la
     * chaîne, signée, indiscutable.
     */
    public function testOnNeSautePasUneJourneePorteuseDeVentes(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $session = $this->ouvrirSession($client, $entete);
        $oubliee = $this->venteValidee($client, $entete, $session['id']);
        $this->antidater($oubliee['id'], '-3 days 10:00');

        $client->request('POST', '/api/point_de_ventes/' . $this->idPointDeVente() . '/cloture-journaliere', $entete + [
            'json' => ['journee' => (new \DateTimeImmutable('yesterday'))->format('Y-m-d')],
        ]);
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString(
            (new \DateTimeImmutable('-3 days'))->format('Y-m-d'),
            $client->getResponse()->toArray(false)['detail'] ?? '',
            'Le refus nomme la journée à clôturer, sinon il n\'est pas actionnable.',
        );
    }

    /** Une journée ne se clôt qu'une fois : deux arrêtés compteraient deux fois la même journée. */
    public function testUneJourneeNeSeClotPasDeuxFois(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $jour = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');
        $uri = '/api/point_de_ventes/' . $this->idPointDeVente() . '/cloture-journaliere';

        $client->request('POST', $uri, $entete + ['json' => ['journee' => $jour]]);
        self::assertResponseIsSuccessful();

        $client->request('POST', $uri, $entete + ['json' => ['journee' => $jour]]);
        self::assertResponseStatusCodeSame(409);
    }

    /** On n'arrête pas une journée qui n'a pas eu lieu : elle se remplirait après l'arrêté. */
    public function testOnNeClotPasUneJourneeAVenir(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $client->request('POST', '/api/point_de_ventes/' . $this->idPointDeVente() . '/cloture-journaliere', $entete + [
            'json' => ['journee' => (new \DateTimeImmutable('tomorrow'))->format('Y-m-d')],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * **Le cas qui a fait naître D57** : un point de vente de vente directe se clôt, sans session et
     * sans comptage.
     *
     * Sans cette opération, il n'aurait eu aucune clôture quotidienne — un défaut qui ne se serait vu
     * qu'au premier contrôle, ou au premier exploitant vendant sans caisse.
     */
    public function testLePointDeVenteDeVenteDirecteSeClotSansSession(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $vente = $client->request('POST', '/api/ventes', $entete + ['json' => ['origineHorsLigne' => false]])->toArray();
        $this->ajouterLigne($client, $entete, $vente['id']);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'cb']]);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        $pdvDirect = $vente['pointDeVente'];
        self::assertIsString($pdvDirect);
        $this->antidater($vente['id'], 'yesterday 10:00');

        $cloture = $client->request('POST', $pdvDirect . '/cloture-journaliere', $entete + [
            'json' => ['journee' => (new \DateTimeImmutable('yesterday'))->format('Y-m-d')],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertSame(1, $cloture['salesCount']);

        // La clôture Z reste hors sujet : rien n'a été compté, parce qu'il n'y avait rien à compter.
        self::assertArrayNotHasKey('comptages', $cloture);
    }

    /** Un point de vente d'un autre établissement est introuvable, pas interdit (404 anti-oracle). */
    public function testUnPointDeVenteHorsPerimetreEstIntrouvable(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $em = $this->em();
        $pdv = $em->getRepository(\App\Caisse\Entity\PointDeVente::class)
            ->findOneBy(['libelle' => \App\Vente\DataFixtures\VenteFixtures::PDV_LIBELLE]);
        self::assertNotNull($pdv);

        // On bascule le point de vente sur l'établissement B : l'admin y a bien une affectation, mais
        // l'en-tête désigne A. Le contrôle doit porter sur l'entité résolue, pas sur l'en-tête.
        $etabB = $this->entite(\App\Organisation\Entity\Etablissement::class, ['nom' => \App\DataFixtures\SocleFixtures::ETAB_B_NOM]);
        $pdv->setEtablissement($etabB);
        $em->flush();

        $client->request('POST', '/api/point_de_ventes/' . $pdv->getId() . '/cloture-journaliere', $entete + [
            'json' => ['journee' => (new \DateTimeImmutable('yesterday'))->format('Y-m-d')],
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Antidate une vente **déjà validée**, en base.
     *
     * `InalterabiliteListener` fige la date d'une vente scellée : le passer par l'API serait refusé, et
     * il aurait raison. Ici on écrit en SQL, hors ORM, pour fabriquer un passé que le test a besoin
     * d'avoir — ce n'est pas un contournement du garde-fou, c'est la mise en place du décor.
     */
    private function antidater(string $venteId, string $quand): void
    {
        $em = $this->em();
        $em->getConnection()->executeStatement(
            'UPDATE vente_vente SET date = ? WHERE id = UNHEX(REPLACE(?, "-", ""))',
            [(new \DateTimeImmutable($quand))->format('Y-m-d H:i:s'), $venteId],
        );
        $em->clear();
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    private function venteValidee(object $client, array $entete, string $sessionId): array
    {
        $vente = $this->creerVente($client, $entete, $sessionId);
        $this->ajouterLigne($client, $entete, $vente['id']);
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'cb']]);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray();
    }

    /** @param array<string, mixed> $entete */
    private function ajouterLigne(object $client, array $entete, string $venteId): void
    {
        $client->request('POST', '/api/ventes/' . $venteId . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        self::assertResponseIsSuccessful();
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
