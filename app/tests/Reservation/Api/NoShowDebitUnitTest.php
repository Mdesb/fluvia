<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Api;

use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\ComplementaryProduct;
use App\Offre\Entity\Produit;
use App\Offre\Enum\ComplementMode;
use App\Reservation\Enum\ModeFacturationNoShow;
use App\Tests\Reservation\ReservationApiTestCase;
use App\Tests\Reservation\Support\NoShowBillingScenarios;
use App\Tests\Vente\Support\FailingWriteListener;
use App\Tests\Vente\Support\OtherProcess;
use App\Tests\Vente\Support\RowHolder;
use App\Vente\Entity\Paiement;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LE NO-SHOW EN UNE SEULE UNITÉ (G-1 et G-5 du ticket opposable, É10 du plan).
 *
 * `DebitPmvStrategie` ouvrait une vente neuve à chaque tentative, débitait le porte-monnaie hors de
 * toute transaction, puis validait la vente hors de son `try`. Une validation en échec laissait donc le
 * client débité sans règlement et la facturation « à facturer » : la relance débitait de nouveau, sur
 * une autre vente. Deux applications simultanées débitaient deux fois, et une exonération pouvait
 * écraser un débit en cours.
 *
 * Les témoins se lisent EN BASE : la réponse ne dit rien d'un débit parti avant une écriture refusée.
 * Le porte-monnaie du payeur porte 50,00 (CrmFixtures) ; chaque absence en coûte 10,00.
 */
final class NoShowDebitUnitTest extends ReservationApiTestCase
{
    use NoShowBillingScenarios;
    use OtherProcess;
    use RowHolder;

    private const EMETTRE = '/api/reservation/facturations-no-show/%s/emettre-vente';

    /** La validation échoue : rien n'est débité, la facturation reste à facturer ; la relance débite une fois, la suivante est refusée. */
    public function testAFailedValidationDebitsNothingAndTheRetryDebitsOnce(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        // Le produit du padel exige un complément : la vente du no-show ne peut pas se valider.
        $lien = $this->requireAComplement();

        $facturation = $this->creerFacturationNoShow($client, $entete, ModeFacturationNoShow::DebitPmv);

        self::assertSame('50.00', $this->walletBalance(), 'La validation a échoué : le porte-monnaie ne doit pas avoir été débité.');
        self::assertSame('a_facturer', $this->billingStatus($facturation));
        self::assertSame(0, $this->noShowSales(), 'Rien ne survit à l\'unité annulée, pas même la vente ouverte.');

        $this->db()->executeStatement('DELETE FROM off_complementary_product WHERE id = UNHEX(:l)', ['l' => $this->hex($lien)]);
        $client->request('POST', sprintf(self::EMETTRE, $facturation), $entete + ['json' => []]);
        self::assertResponseIsSuccessful();
        self::assertSame(['40.00', 'facturee', 1], [$this->walletBalance(), $this->billingStatus($facturation), $this->noShowSales()]);

        $client->request('POST', sprintf(self::EMETTRE, $facturation), $entete + ['json' => []]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('40.00', $this->walletBalance());
    }

    /** L'écriture du règlement échoue APRÈS le débit, pendant le `flush()` : tout est annulé, débit compris. */
    public function testAWriteFailingAfterTheDebitLeavesTheWalletIntact(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $facturation = $this->pendingDebitBilling($client, $entete);

        /** @var FailingWriteListener $echecs */
        $echecs = static::getContainer()->get(FailingWriteListener::class);
        $echecs->failOn(Paiement::class);
        try {
            $statut = $client->request('POST', sprintf(self::EMETTRE, $facturation), $entete + ['json' => []])->getStatusCode();
        } finally {
            $echecs->failOn(null);
        }

        self::assertSame(422, $statut);
        self::assertSame(['50.00', 'a_facturer', 0], [$this->walletBalance(), $this->billingStatus($facturation), $this->noShowSales()]);
    }

    /**
     * Deux applications simultanées (deux agents, ou l'automate et un agent) : la seconde attend la
     * première sur la facturation, puis la trouve facturée. Un seul débit.
     */
    public function testTwoSimultaneousApplicationsDebitOnce(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();
        $client->disableReboot();
        $facturation = $this->pendingDebitBilling($client, $entete);

        $premiere = $this->settleInOtherProcess(sprintf(self::EMETTRE, $facturation), $entete['auth_bearer'], $idA, []);
        $this->waitUntilHeld($premiere, 'wallet');
        $minuterie = $this->releaseLater($premiere, 2.0);
        $debut = microtime(true);
        $statut = $client->request('POST', sprintf(self::EMETTRE, $facturation), $entete + ['json' => []])->getStatusCode();
        $attente = microtime(true) - $debut;
        proc_close($minuterie);
        $issuePremiere = $this->release($premiere);

        self::assertSame(201, $issuePremiere['status'], (string) json_encode($issuePremiere['body']));
        self::assertSame(409, $statut, 'La seconde application trouve la facturation déjà facturée.');
        self::assertGreaterThanOrEqual(1.5, $attente, 'Elle attend la première sur la facturation : sinon elle n\'a rien vu.');
        self::assertSame(['40.00', 'facturee', 1], [$this->walletBalance(), $this->billingStatus($facturation), $this->noShowSales()]);
    }

    /**
     * Deux absences d'un même établissement débitées en même temps partagent la session système : le
     * numéro de leurs ventes et leur chaîne NF525. La seconde attend la première, puis passe avec le
     * numéro suivant ; sinon elle prenait le même numéro et échouait au `flush()`.
     */
    public function testTwoNoShowsOfOneSiteAreDebitedOneAfterTheOther(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();
        $client->disableReboot();
        $premiere = $this->pendingDebitBilling($client, $entete);
        $seconde = $this->pendingDebitBilling($client, $entete, 180);

        $autre = $this->settleInOtherProcess(sprintf(self::EMETTRE, $premiere), $entete['auth_bearer'], $idA, []);
        $this->waitUntilHeld($autre, 'wallet');
        $minuterie = $this->releaseLater($autre, 2.0);
        $debut = microtime(true);
        $statut = $client->request('POST', sprintf(self::EMETTRE, $seconde), $entete + ['json' => []])->getStatusCode();
        $attente = microtime(true) - $debut;
        proc_close($minuterie);
        $issuePremiere = $this->release($autre);

        self::assertSame([201, 201], [$issuePremiere['status'], $statut]);
        self::assertGreaterThanOrEqual(1.5, $attente, 'La seconde attend la première sur la session système.');
        self::assertSame(['30.00', 'facturee', 'facturee'], [$this->walletBalance(), $this->billingStatus($premiere), $this->billingStatus($seconde)]);
        self::assertSame(2, (int) $this->db()->fetchOne(
            'SELECT COUNT(DISTINCT v.numero) FROM vente_vente v JOIN vente_ligne l ON l.vente_id = v.id WHERE l.note LIKE :n',
            ['n' => 'No-show (débit PMV automatique)%'],
        ), 'Deux ventes, deux numéros.');
    }

    /**
     * La clé du débit vient de la facturation : rouverte (correction en base, reprise de données), elle
     * ne débite pas une seconde fois. Et ce n'est l'identifiant d'aucun objet que l'API montre : qui la
     * devinerait pourrait la prendre d'avance sur une autre vente, et le débit serait refusé pour toujours.
     */
    public function testTheDebitKeyComesFromTheBillingAndIsNoVisibleIdentifier(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $facturation = $this->pendingDebitBilling($client, $entete);
        $client->request('POST', sprintf(self::EMETTRE, $facturation), $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        $debit = $this->db()->fetchAssociative(
            'SELECT LOWER(HEX(p.cle_idempotence)) AS cle, LOWER(HEX(p.vente_id)) AS vente, LOWER(HEX(f.reservation_id)) AS reservation '
            . 'FROM reservation_facturation_no_show f JOIN vente_paiement p ON p.vente_id = f.vente_rattachee_id WHERE f.id = UNHEX(:f)',
            ['f' => $this->hex($facturation)],
        );
        self::assertIsArray($debit);
        self::assertNotNull($debit['cle'], 'Le débit d\'un no-show porte une clé (G-1).');
        self::assertNotSame($this->hex($facturation), $debit['cle'], 'La clé n\'est pas l\'identifiant de la facturation, que l\'API montre.');
        self::assertNotSame($debit['vente'], $debit['cle'], 'Ni celui de la vente qu\'elle ouvre (G-1).');
        self::assertNotSame($debit['reservation'], $debit['cle']);

        $this->db()->executeStatement(
            "UPDATE reservation_facturation_no_show SET statut = 'a_facturer', vente_rattachee_id = NULL WHERE id = UNHEX(:f)",
            ['f' => $this->hex($facturation)],
        );
        $client->request('POST', sprintf(self::EMETTRE, $facturation), $entete + ['json' => []]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['40.00', 1], [$this->walletBalance(), $this->noShowSales()]);
    }

    /** Une exonération pendant un débit en cours attend son issue, puis refuse : jamais « exonérée » ET débitée. */
    public function testAnExemptionWaitsForTheDebitInProgressThenRefuses(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $facturation = $this->pendingDebitBilling($client, $entete);

        $debit = $this->holdBillingWhileSetting($facturation, "statut = 'facturee'");
        $debut = microtime(true);
        $statut = $client->request('POST', '/api/reservation/facturations-no-show/' . $facturation . '/exonerer', $entete + [
            'json' => ['motif' => 'Geste commercial'],
        ])->getStatusCode();
        $attente = microtime(true) - $debut;
        $this->releaseRow($debit);

        self::assertSame(409, $statut);
        self::assertGreaterThanOrEqual(1.5, $attente, 'L\'exonération attend le débit en cours sur la facturation.');
        self::assertSame('facturee', $this->billingStatus($facturation));
        self::assertNull($this->db()->fetchOne('SELECT exonere_par_id FROM reservation_facturation_no_show WHERE id = UNHEX(:f)', ['f' => $this->hex($facturation)]));
    }

    /** La vente d'un agent émise pendant une exonération en cours attend, puis refuse : aucune vente pour une absence exonérée. */
    public function testAnAgentSaleWaitsForTheExemptionInProgressThenRefuses(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $facturation = $this->creerFacturationNoShow($client, $entete, ModeFacturationNoShow::VenteDiffereeAgent);
        $session = $this->idSessionOuverteOuNouvelle($client, $entete);

        $exoneration = $this->holdBillingWhileSetting($facturation, "statut = 'exoneree', motif_exoneration = 'Geste commercial'");
        $debut = microtime(true);
        $statut = $client->request('POST', sprintf(self::EMETTRE, $facturation), $entete + [
            'json' => ['session' => '/api/session_caisses/' . $session],
        ])->getStatusCode();
        $attente = microtime(true) - $debut;
        $this->releaseRow($exoneration);

        self::assertSame(409, $statut);
        self::assertGreaterThanOrEqual(1.5, $attente, 'L\'émission attend l\'exonération en cours sur la facturation.');
        self::assertSame('exoneree', $this->billingStatus($facturation));
        self::assertSame(0, $this->noShowSales('No-show / annulation tardive%'));
    }

    /**
     * Une facturation « débit PMV » restée à facturer : le porte-monnaie était vide au moment de
     * l'annulation, puis il est rechargé.
     *
     * @param array<string, mixed> $entete
     */
    private function pendingDebitBilling(object $client, array $entete, int $decalageMinutes = 10): string
    {
        $this->setWalletBalance('0.00');
        $facturation = $this->creerFacturationNoShow($client, $entete, ModeFacturationNoShow::DebitPmv, decalageMinutes: $decalageMinutes);
        self::assertSame('a_facturer', $this->billingStatus($facturation));
        $this->setWalletBalance('50.00');

        return $facturation;
    }

    /** @return string l'identifiant du lien « l'entrée exige la carte » */
    private function requireAComplement(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $produit = static fn (string $libelle): Produit => $em->getRepository(Produit::class)->findOneBy(['libelleRecherche' => $libelle])
            ?? self::fail('Produit de fixture introuvable : ' . $libelle);
        $lien = (new ComplementaryProduct())->setProduct($produit(OffreFixtures::PRODUIT_ENTREE))
            ->setComplement($produit(OffreFixtures::PRODUIT_CARTE))->setMode(ComplementMode::Required);
        $em->persist($lien);
        $em->flush();

        return (string) $lien->getId();
    }

    /**
     * Une autre requête tient la facturation (`FOR UPDATE`) le temps de son geste, puis l'écrit.
     *
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function holdBillingWhileSetting(string $facturation, string $affectation): array
    {
        return $this->holdRow(
            [
                'SELECT id FROM reservation_facturation_no_show WHERE id = UNHEX(?) FOR UPDATE',
                'UPDATE reservation_facturation_no_show SET ' . $affectation . ' WHERE id = UNHEX(?)',
            ],
            [[$this->hex($facturation)], [$this->hex($facturation)]],
        );
    }
}
