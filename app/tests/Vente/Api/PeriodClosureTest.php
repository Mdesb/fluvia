<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Vente\VenteApiTestCase;
use App\Vente\Nf525\Entity\PeriodClosure;
use App\Vente\Nf525\OperationInalterableException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * #342 — clôtures mensuelle et annuelle (BOI-TVA-DECLA-30-10-30 § 170 : journalière, mensuelle et
 * annuelle, « cumulatives et impératives »).
 *
 * Ce que ces tests protègent : le mois additionne des arrêtés de journée, l'année additionne des mois,
 * et chaque niveau refuse de sceller un total qui ne couvrirait pas toutes les ventes du niveau du
 * dessous. Un total de période faux n'est pas approximatif : il serait signé.
 */
final class PeriodClosureTest extends VenteApiTestCase
{
    /** Le mois additionne ses journées arrêtées, reprend le cumul perpétuel et entre dans la chaîne. */
    public function testLeMoisAdditionneSesJourneesEtSeScelle(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $session = $this->ouvrirSession($client, $entete);

        $j1 = $this->vendreLe($client, $entete, $session['id'], $this->leMois(-2, 1));
        $j2 = $this->vendreLe($client, $entete, $session['id'], $this->leMois(-2, 4));
        $c1 = $this->arreterJournee($client, $entete, $j1);
        $c2 = $this->arreterJournee($client, $entete, $j2);

        $mois = $this->cloreMois($client, $entete, 'first day of -2 months');
        self::assertResponseIsSuccessful();

        self::assertSame('mensuelle', $mois['type']);
        self::assertSame(2, $mois['dailyClosureCount']);
        self::assertSame(2, $mois['salesCount']);
        self::assertSame($this->somme($c1['dailyTotal'], $c2['dailyTotal']), $mois['periodTotal'], 'Le mois additionne ses journées.');
        self::assertSame('0.00', $mois['previousGrandTotal']);
        self::assertSame($c2['grandTotal'], $mois['grandTotal'], 'Le perpétuel est celui de la dernière journée arrêtée.');
        self::assertSame($mois['periodTotal'], $mois['recomputedTotal'], 'Base intacte : la table des ventes dit la même chose.');
        self::assertFalse($mois['discrepancy']);
        self::assertNotNull($mois['lastSequence']);

        // Scellée : la clôture est un maillon de la chaîne, et la chaîne reste intacte.
        $ops = $this->em()->getConnection()->fetchFirstColumn(
            "SELECT type_operation FROM nf525_operation_scellee WHERE cible_type = 'PeriodClosure'",
        );
        self::assertSame(['cloture_mensuelle'], $ops);
        $client->request('POST', '/api/nf525/verifier-chaine', [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => $entete['headers'] + ['Content-Type' => 'application/ld+json'],
            'json' => ['pointDeVente' => $this->idPointDeVente()],
        ]);
        self::assertResponseStatusCodeSame(200);
    }

    /**
     * Une journée de ventes non arrêtée bloque le mois : sinon le mois scellerait un total qui ne couvre
     * pas toutes ses ventes, et la journée oubliée n'aurait plus de clôture qui la contienne.
     */
    public function testUnMoisDontUneJourneeDeVentesNEstPasArreteeEstRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $session = $this->ouvrirSession($client, $entete);
        $jour = $this->vendreLe($client, $entete, $session['id'], $this->leMois(-2, 6));

        $this->cloreMois($client, $entete, 'first day of -2 months');
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString($jour, $client->getResponse()->toArray(false)['detail'] ?? '', 'Le refus nomme la journée.');
    }

    /** On ne clôt pas un mois qui n'est pas terminé : ses ventes à venir tomberaient hors de la clôture. */
    public function testLeMoisEnCoursEstRefuse(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $this->cloreMois($client, $entete, 'now');
        self::assertResponseStatusCodeSame(422);

        $client->request('POST', $this->uri('monthly-closure'), $entete + ['json' => ['month' => '2026-13']]);
        self::assertResponseStatusCodeSame(422, 'Un mois invalide est refusé, pas reporté sur janvier.');
    }

    /** Un mois ne se clôt qu'une fois : deux clôtures le compteraient deux fois dans le cumul d'exercice. */
    public function testUnMoisNeSeClotPasDeuxFois(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $this->cloreMois($client, $entete, 'first day of -2 months');
        self::assertResponseIsSuccessful();
        $this->cloreMois($client, $entete, 'first day of -2 months');
        self::assertResponseStatusCodeSame(409);
    }

    /**
     * Un mois sauté est refusé, et le cumul d'exercice se reporte d'un mois à l'autre.
     */
    public function testUnMoisSauteEstRefuseEtLeCumulDExerciceSeReporte(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $session = $this->ouvrirSession($client, $entete);

        $a = $this->arreterJournee($client, $entete, $this->vendreLe($client, $entete, $session['id'], $this->leMois(-3, 3)));
        $b = $this->arreterJournee($client, $entete, $this->vendreLe($client, $entete, $session['id'], $this->leMois(-2, 3)));

        $this->cloreMois($client, $entete, 'first day of -2 months');
        self::assertResponseStatusCodeSame(409, 'Le mois précédent porte une journée arrêtée et n\'est pas clos.');
        self::assertStringContainsString(
            (new \DateTimeImmutable('first day of -3 months'))->format('m/Y'),
            $client->getResponse()->toArray(false)['detail'] ?? '',
        );

        $premier = $this->cloreMois($client, $entete, 'first day of -3 months');
        self::assertResponseIsSuccessful();
        $second = $this->cloreMois($client, $entete, 'first day of -2 months');
        self::assertResponseIsSuccessful();

        self::assertSame($premier['grandTotal'], $second['previousGrandTotal'], 'Le mois dit d\'où il part.');
        self::assertSame($b['grandTotal'], $second['grandTotal']);
        $memeAnnee = substr($premier['periodStart'], 0, 4) === substr($second['periodStart'], 0, 4);
        self::assertSame(
            $memeAnnee ? $this->somme($a['dailyTotal'], $b['dailyTotal']) : $b['dailyTotal'],
            $second['fiscalYearTotal'],
            'Cumul d\'exercice = mois précédents de la même année + ce mois.',
        );
    }

    /**
     * L'année additionne ses mois — et refuse tant qu'un mois porteur de journées arrêtées n'est pas clos.
     */
    public function testLAnneeAdditionneSesMoisEtRefuseUnMoisNonClos(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $session = $this->ouvrirSession($client, $entete);
        $annee = (int) (new \DateTimeImmutable('last year'))->format('Y');

        $mars = $this->arreterJournee($client, $entete, $this->vendreLe($client, $entete, $session['id'], sprintf('%d-03-10 10:00', $annee)));
        $juin = $this->arreterJournee($client, $entete, $this->vendreLe($client, $entete, $session['id'], sprintf('%d-06-15 10:00', $annee)));

        $client->request('POST', $this->uri('annual-closure'), $entete + ['json' => ['year' => $annee]]);
        self::assertResponseStatusCodeSame(409, 'Mars n\'est pas clos.');
        self::assertStringContainsString('03/' . $annee, $client->getResponse()->toArray(false)['detail'] ?? '');

        $client->request('POST', $this->uri('monthly-closure'), $entete + ['json' => ['month' => $annee . '-03']]);
        self::assertResponseIsSuccessful();
        $client->request('POST', $this->uri('annual-closure'), $entete + ['json' => ['year' => $annee]]);
        self::assertResponseStatusCodeSame(409, 'Juin n\'est pas clos.');
        self::assertStringContainsString('06/' . $annee, $client->getResponse()->toArray(false)['detail'] ?? '');

        $client->request('POST', $this->uri('monthly-closure'), $entete + ['json' => ['month' => $annee . '-06']]);
        self::assertResponseIsSuccessful();
        $an = $client->request('POST', $this->uri('annual-closure'), $entete + ['json' => ['year' => $annee]])->toArray();
        self::assertResponseIsSuccessful();

        self::assertSame('annuelle', $an['type']);
        self::assertSame($annee . '-01-01', substr($an['periodStart'], 0, 10));
        self::assertSame($annee . '-12-31', substr($an['periodEnd'], 0, 10));
        self::assertSame(2, $an['dailyClosureCount']);
        self::assertSame(2, $an['salesCount']);
        self::assertSame($this->somme($mars['dailyTotal'], $juin['dailyTotal']), $an['periodTotal']);
        self::assertSame($an['periodTotal'], $an['fiscalYearTotal']);
        self::assertSame($juin['grandTotal'], $an['grandTotal']);

        $client->request('POST', $this->uri('annual-closure'), $entete + ['json' => ['year' => (int) date('Y')]]);
        self::assertResponseStatusCodeSame(422, 'L\'année en cours n\'est pas terminée.');
    }

    /** Une clôture de période est immuable, comme tout ce qui est scellé. */
    public function testUneClotureDePeriodeEstImmuable(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $mois = $this->cloreMois($client, $entete, 'first day of -2 months');
        self::assertResponseIsSuccessful();

        $em = $this->em();
        $cloture = $em->getRepository(PeriodClosure::class)->find($mois['id']);
        self::assertInstanceOf(PeriodClosure::class, $cloture);
        $cloture->setPeriodTotal('999.99');

        $this->expectException(OperationInalterableException::class);
        $em->flush();
    }

    /**
     * Cloisonnement : deux établissements et un témoin. La clôture de A se lit sous A (témoin), pas sous
     * B ; un point de vente de B ne se clôt pas depuis A (404 anti-oracle).
     */
    public function testUneClotureNeSeLitNiNeSePoseDUnEtablissementALAutre(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $mois = $this->cloreMois($client, $entete, 'first day of -2 months');
        self::assertResponseIsSuccessful();

        self::assertContains($mois['id'], $this->idsListes($client, $entete), 'Témoin : visible sous A.');

        $idB = $this->idEtablissement(\App\DataFixtures\SocleFixtures::ETAB_B_NOM);
        $enteteB = ['auth_bearer' => $entete['auth_bearer'], 'headers' => [ContexteEtablissement::HEADER => $idB]];
        self::assertNotContains($mois['id'], $this->idsListes($client, $enteteB), 'Invisible sous B.');
        $client->request('GET', '/api/period_closures/' . $mois['id'], $enteteB);
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', $this->uri('monthly-closure'), $enteteB + ['json' => ['month' => (new \DateTimeImmutable('first day of -3 months'))->format('Y-m')]]);
        self::assertResponseStatusCodeSame(404, 'Le point de vente de A, vu depuis B, est introuvable.');
    }

    /** Témoin de droit : un compte en lecture seule ne clôt pas un mois. */
    public function testUnLecteurNeClotPasUnMois(): void
    {
        [$client, $entete] = $this->adminSurA();
        $token = $this->jeton($client, SocleFixtures::LECTEUR_EMAIL, SocleFixtures::LECTEUR_MDP);
        $client->request('POST', $this->uri('monthly-closure'), [
            'auth_bearer' => $token,
            'headers' => $entete['headers'],
            'json' => ['month' => (new \DateTimeImmutable('first day of -2 months'))->format('Y-m')],
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    // ── outils ─────────────────────────────────────────────────────────────────────────────────

    /** Le jour `$jour` du mois décalé de `$decalage`, à 10 h — écrit en toutes lettres (« first day of » écrase un « +N days »). */
    private function leMois(int $decalage, int $jour): string
    {
        return (new \DateTimeImmutable(sprintf('first day of %d months', $decalage)))->format('Y-m-') . sprintf('%02d 10:00', $jour);
    }

    private function uri(string $operation): string
    {
        return '/api/point_de_ventes/' . $this->idPointDeVente() . '/' . $operation;
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    private function cloreMois(object $client, array $entete, string $quand): array
    {
        $mois = (new \DateTimeImmutable($quand))->format('Y-m');

        return $client->request('POST', $this->uri('monthly-closure'), $entete + ['json' => ['month' => $mois]])->toArray(false);
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    private function arreterJournee(object $client, array $entete, string $jour): array
    {
        $r = $client->request('POST', $this->uri('cloture-journaliere'), $entete + ['json' => ['journee' => $jour]])->toArray(false);
        self::assertResponseIsSuccessful();

        return $r;
    }

    /**
     * Une vente validée, antidatée — rend la journée comptable.
     *
     * @param array<string, mixed> $entete
     */
    private function vendreLe(object $client, array $entete, string $sessionId, string $quand): string
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

        // Hors ORM : la date d'une vente scellée est figée par le listener, et il a raison. On pose le
        // décor, on ne contourne pas la garde.
        $em = $this->em();
        $em->getConnection()->executeStatement(
            'UPDATE vente_vente SET date = ? WHERE id = UNHEX(REPLACE(?, "-", ""))',
            [$this->momentUtc($quand), $vente['id']],
        );
        $em->clear();

        return $this->jourComptable($quand);
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return list<string>
     */
    private function idsListes(object $client, array $entete): array
    {
        $rendu = $client->request('GET', '/api/period_closures', $entete)->toArray();
        self::assertResponseIsSuccessful();
        $membres = $rendu['member'] ?? $rendu['hydra:member'] ?? [];

        return array_map(static fn (array $m): string => (string) $m['id'], $membres);
    }

    private function somme(string $a, string $b): string
    {
        return number_format(((int) round((float) $a * 100) + (int) round((float) $b * 100)) / 100, 2, '.', '');
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
