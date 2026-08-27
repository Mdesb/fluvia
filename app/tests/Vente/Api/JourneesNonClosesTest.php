<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Caisse\Entity\PointDeVente;
use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Organisation\Entity\Etablissement;
use App\Tests\Vente\VenteApiTestCase;
use App\Vente\DataFixtures\VenteFixtures;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D57 + D55 — **une clôture manquée doit se voir sans que personne ne bute dessus.**
 *
 * Le détecteur existait déjà : le refus « journée sautée » du `DailyClosureHandler`. Mais il ne
 * parlait qu'à celui qui *tentait* une clôture — une journée oubliée restait donc invisible jusqu'à ce
 * que quelqu'un s'y heurte, éventuellement des semaines plus tard, c'est-à-dire quand l'arriéré est
 * devenu pénible à rattraper.
 *
 * Automatiser la clôture sans traiter l'échec aurait échangé un oubli visible contre un **oubli
 * invisible**, et le second est pire : tout le monde croirait que c'est fait. D'où une file qui
 * descend à zéro plutôt qu'une ligne de journal que personne ne relit.
 */
final class JourneesNonClosesTest extends VenteApiTestCase
{
    private const URI = '/api/clotures-journalieres/en-attente';

    /** Une journée porteuse de ventes, jamais arrêtée, apparaît — avec son retard en clair. */
    public function testUneJourneeOublieeApparaitDansLaFile(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->venteValidee($client, $entete, $session['id']);
        $this->antidater($vente['id'], '-3 days 10:00');

        $file = $client->request('GET', self::URI, $entete)->toArray();
        self::assertResponseIsSuccessful();

        $journees = array_column($file['member'] ?? $file, 'journee');
        self::assertContains($this->jourComptable('-3 days'), $journees);

        $entree = ($file['member'] ?? $file)[0];
        self::assertSame(1, $entree['nombreVentes']);
        // D46 — on affiche l'écart, jamais les dates brutes : « en retard de 3 j » se lit sans
        // soustraire, à chaque ligne, toute la journée.
        self::assertSame(3, $entree['joursDeRetard']);
        self::assertNotSame('', $entree['raison'], 'Sans la raison, on cherche au mauvais endroit.');
    }

    /** **Le test qui compte : la file descend.** Une journée arrêtée en sort. */
    public function testLaFileDescendQuandOnClot(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->venteValidee($client, $entete, $session['id']);
        $jour = $this->jourComptable('-2 days');
        $this->antidater($vente['id'], '-2 days 10:00');

        $avant = $this->journees($client, $entete);
        self::assertContains($jour, $avant);

        $client->request('POST', '/api/point_de_ventes/' . $this->idPointDeVente() . '/cloture-journaliere', $entete + [
            'json' => ['journee' => $jour],
        ]);
        self::assertResponseIsSuccessful();

        $apres = $this->journees($client, $entete);
        self::assertNotContains($jour, $apres, 'Une liste qu\'on ne peut pas vider est une liste qu\'on cesse de lire.');
    }

    /** La journée en cours n'est pas en retard : elle n'est pas finie. */
    public function testLaJourneeEnCoursNEstPasDansLaFile(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $session = $this->ouvrirSession($client, $entete);
        $this->venteValidee($client, $entete, $session['id']);

        self::assertNotContains($this->jourComptable('today'), $this->journees($client, $entete));
    }

    /**
     * La file est cloisonnée : elle ne montre que l'établissement actif.
     *
     * Une file inter-établissements ne se remarquerait pas — elle aurait simplement l'air plus longue.
     */
    public function testLaFileNeMontreQueLEtablissementActif(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $session = $this->ouvrirSession($client, $entete);
        $vente = $this->venteValidee($client, $entete, $session['id']);
        $this->antidater($vente['id'], '-2 days 10:00');

        // On déplace le point de vente sur l'établissement B ; l'en-tête désigne toujours A.
        $em = $this->em();
        $pdv = $em->getRepository(PointDeVente::class)->findOneBy(['libelle' => VenteFixtures::PDV_LIBELLE]);
        self::assertNotNull($pdv);
        $pdv->setEtablissement($this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM]));
        $em->flush();

        self::assertSame([], $this->journees($client, $entete));
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return list<string>
     */
    private function journees(object $client, array $entete): array
    {
        $reponse = $client->request('GET', self::URI, $entete)->toArray();
        self::assertResponseIsSuccessful();

        /** @var list<string> $journees */
        $journees = array_column($reponse['member'] ?? $reponse, 'journee');

        return $journees;
    }

    private function antidater(string $venteId, string $quand): void
    {
        $em = $this->em();
        $em->getConnection()->executeStatement(
            'UPDATE vente_vente SET date = ? WHERE id = UNHEX(REPLACE(?, "-", ""))',
            [$this->momentUtc($quand), $venteId],
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
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/lignes', $entete + [
            'json' => [
                'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_ENTREE),
                'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
                'quantite' => 1,
            ],
        ]);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/paiements', $entete + ['json' => ['moyen' => 'cb']]);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/ventes/' . $vente['id'] . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        return $client->getResponse()->toArray();
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
