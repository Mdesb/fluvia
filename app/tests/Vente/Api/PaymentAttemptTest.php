<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Tests\Crm\CrmApiTestCase;
use App\Tests\Vente\Support\FailingWriteListener;
use App\Tests\Vente\Support\SettlementScenarios;
use App\Vente\Entity\CardRejection;
use App\Vente\Entity\Paiement;
use App\Vente\Entity\Vente;
use App\Vente\Service\CardRejectionRecorder;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Uid\Uuid;

/**
 * UNE SEULE TENTATIVE « EN COURS » PAR VENTE, ÉCRITE AVANT L'EFFET (G-3, G-5, G-6 du ticket opposable).
 *
 * Avant ce lot, rien n'était écrit avant que l'argent bouge : le débit du porte-monnaie partait en
 * autocommit, le terminal était sollicité, et seul le `flush()` final disait si le règlement existait.
 * Une tentative est désormais écrite et validée en base AVANT l'effet ; elle tient la vente tant que
 * l'effet est en cours (`pending`) ou que son issue est inconnue (`unresolved`).
 *
 * Ces tests jouent la concurrence en un seul processus (une tentative écrite d'avance, ou une seconde
 * connexion qui écrit pendant la requête). Les courses à deux processus : `ConcurrentSettlementTest`.
 */
final class PaymentAttemptTest extends CrmApiTestCase
{
    use SettlementScenarios;

    /** Une autre demande tient la vente : « en cours », rien d'encaissé — avec ou sans clé. */
    public function testAnAttemptInFlightOnTheSaleAnswersInProgressAndCollectsNothing(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->cardSale($client, $entete);
        $this->attemptInFlight($vente, 'especes', false, '-5 seconds');

        foreach ([[], ['cleIdempotence' => (string) Uuid::v4()]] as $cle) {
            $reponse = $this->pay($client, $entete, $vente, ['moyen' => 'especes', 'montant' => '45.00'] + $cle);
            self::assertSame(409, $reponse->getStatusCode());
            self::assertSame('payment_in_progress', $reponse->toArray(false)['code']);
        }
        self::assertSame(0, $this->paymentCount($vente));
    }

    /**
     * Un règlement validé par une autre requête APRÈS la lecture de la vente compte quand même : le
     * reste dû est relu en base une fois la vente tenue (`refresh`), deux connexions réelles.
     *
     * La course est jouée comme dans `ConcurrentFmiCountTest` : dès que la requête charge la vente, une
     * AUTRE connexion y écrit un règlement de 45,00 en espèces et solde le reste dû. Sans relecture, la
     * requête faisait payer 45,00 de plus par carte sur une vente déjà soldée.
     */
    public function testAPaymentCommittedByAnotherConnectionAfterTheSaleWasReadIsSeen(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $vente = $this->cardSale($client, $entete);
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $autre = DriverManager::getConnection($em->getConnection()->getParams());
        $course = new class($vente, $autre) {
            public bool $jouee = false;

            public function __construct(private readonly string $vente, private readonly \Doctrine\DBAL\Connection $autre)
            {
            }

            public function postLoad(PostLoadEventArgs $args): void
            {
                $objet = $args->getObject();
                if ($this->jouee || !$objet instanceof Vente || (string) $objet->getId() !== $this->vente) {
                    return;
                }
                $this->jouee = true;
                $v = str_replace('-', '', $this->vente);
                $this->autre->executeStatement(
                    "INSERT INTO vente_paiement (id, vente_id, moyen_code, montant, rendu, differe, date_heure) VALUES (UNHEX(:id), UNHEX(:v), 'especes', '45.00', '0.00', 0, NOW())",
                    ['id' => str_replace('-', '', (string) Uuid::v4()), 'v' => $v],
                );
                $this->autre->executeStatement("UPDATE vente_vente SET reste_apayer = '0.00' WHERE id = UNHEX(:v)", ['v' => $v]);
            }
        };
        $em->getEventManager()->addEventListener(Events::postLoad, $course);

        $reponse = $this->pay($client, $entete, $vente, ['moyen' => 'cb', 'montant' => '45.00']);
        $em->getEventManager()->removeEventListener(Events::postLoad, $course);
        $autre->close();

        self::assertTrue($course->jouee, 'témoin : le règlement concurrent est écrit pendant la requête, après la lecture de la vente');
        self::assertSame(422, $reponse->getStatusCode(), 'Vente soldée : aucun rendu possible par carte, le terminal n\'est pas sollicité.');
        self::assertSame(1, $this->paymentCount($vente), 'Le seul règlement est celui de l\'autre connexion.');
    }

    /**
     * Le débit du porte-monnaie et l'écriture du règlement forment une seule unité (G-5). L'écriture
     * échoue APRÈS le débit : le solde revient, aucun règlement, la tentative est « failed » — et la
     * même demande, rejouée, encaisse une fois.
     */
    public function testAWriteFailureAfterTheWalletDebitLeavesTheBalanceIntact(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $vente = $this->creerVenteAvecClient($client, $entete, '/api/clients/' . $this->idPayeur(), 2)['id']; // 9,90
        $corps = ['moyen' => 'pmv', 'montant' => '4.00', 'cleIdempotence' => $cle = (string) Uuid::v4()];
        $echec = static::getContainer()->get(FailingWriteListener::class);
        self::assertInstanceOf(FailingWriteListener::class, $echec);

        $echec->failOn(Paiement::class);
        $reponse = $this->pay($client, $entete, $vente, $corps);
        $echec->failOn(null);

        self::assertSame(500, $reponse->getStatusCode());
        self::assertSame('50.00', $this->balance($client, $entete), 'Le débit est annulé avec l\'écriture du règlement.');
        self::assertSame(0, $this->paymentCount($vente));
        self::assertSame('failed', $this->attemptOf($cle)['status'] ?? null);

        $rejeu = $this->pay($client, $entete, $vente, $corps);
        self::assertSame(201, $rejeu->getStatusCode(), 'Rien n\'a bougé : la même demande se rejoue à l\'identique.');
        self::assertSame('46.00', $this->balance($client, $entete));
        self::assertSame(1, $this->paymentCount($vente));
    }

    /** Une tentative sans terminal « pending » depuis plus de 120 s est jugée sur la base : aucun règlement, donc « failed ». */
    public function testAStaleCashAttemptIsClosedFromTheBaseAndNoLongerBlocks(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->cardSale($client, $entete);
        $vieille = $this->attemptInFlight($vente, 'especes', false, '-121 seconds');

        $reponse = $this->pay($client, $entete, $vente, ['moyen' => 'especes', 'montant' => '45.00']);

        self::assertSame(201, $reponse->getStatusCode());
        self::assertSame('failed', $this->attemptOf($vieille)['status'] ?? null);
        self::assertSame(1, $this->paymentCount($vente));
    }

    /**
     * L'autre branche de la même règle : un règlement porte la clé de la tentative périmée, elle est
     * « accepted ». Jugée par une AUTRE demande (une autre clé) : sans la lecture de la base, elle
     * serait close « failed » alors que son règlement existe.
     */
    public function testAStaleCashAttemptWhoseKeyIsOnAPaymentIsAccepted(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->cardSale($client, $entete);
        $corps = ['moyen' => 'especes', 'montant' => '20.00', 'cleIdempotence' => $cle = (string) Uuid::v4()];
        self::assertSame(201, $this->pay($client, $entete, $vente, $corps)->getStatusCode());
        $this->db()->executeStatement(
            "UPDATE sale_payment_attempt SET status = 'pending', open_sale_id = sale_id, payment_id = NULL, closed_at = NULL, started_at = :d WHERE idempotency_key = UNHEX(:k)",
            ['d' => (new \DateTimeImmutable('-121 seconds'))->format('Y-m-d H:i:s'), 'k' => $this->hex($cle)],
        );

        $autre = $this->pay($client, $entete, $vente, ['moyen' => 'especes', 'montant' => '25.00', 'cleIdempotence' => (string) Uuid::v4()]);
        self::assertSame(201, $autre->getStatusCode());
        self::assertSame(
            ['accepted', 0, 1],
            [$this->attemptOf($cle)['status'] ?? null, (int) ($this->attemptOf($cle)['holds_sale'] ?? -1), (int) $this->db()->fetchOne('SELECT COUNT(*) FROM sale_payment_attempt WHERE idempotency_key = UNHEX(:k) AND payment_id IS NOT NULL', ['k' => $this->hex($cle)])],
        );

        $rejeu = $this->pay($client, $entete, $vente, $corps);
        self::assertSame(200, $rejeu->getStatusCode());
        self::assertTrue($rejeu->toArray()['dejaEnregistre']);
        self::assertSame(2, $this->paymentCount($vente));
    }

    /**
     * Après un refus du terminal, la même clé ne repart pas au terminal et ne sert pas un autre montant.
     *
     * Le rejeu est envoyé SANS forcer d'issue : le terminal simulé accepterait. Une réponse « refusé »
     * prouve donc qu'il n'a pas été sollicité. Un nouvel essai est une nouvelle intention, une nouvelle clé.
     */
    public function testARefusedKeyNeverReachesTheTerminalAgain(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->cardSale($client, $entete);
        $corps = ['moyen' => 'cb', 'montant' => '20.00', 'cleIdempotence' => $cle = (string) Uuid::v4()];
        self::assertSame('refuse', $this->pay($client, $entete, $vente, $corps, 'refuse')->toArray()['statutTPE']);

        $rejeu = $this->pay($client, $entete, $vente, $corps);
        self::assertSame(200, $rejeu->getStatusCode());
        self::assertSame(['reglementEnregistre' => false, 'dejaEnregistre' => true, 'statutTPE' => 'refuse'], array_intersect_key($rejeu->toArray(), array_flip(['reglementEnregistre', 'dejaEnregistre', 'statutTPE'])));

        $autreMontant = $this->pay($client, $entete, $vente, ['montant' => '25.00'] + $corps);
        self::assertSame(422, $autreMontant->getStatusCode(), 'Même clé, autre montant : refus, même après un refus du terminal.');
        self::assertSame(0, $this->paymentCount($vente));

        $nouvelle = $this->pay($client, $entete, $vente, ['cleIdempotence' => (string) Uuid::v4()] + $corps);
        self::assertSame(201, $nouvelle->getStatusCode(), 'Le refus a libéré la vente : une nouvelle intention passe.');
    }

    /** L'`id` fourni vaut clé (D-3), pour la tentative aussi : le même `id` après un refus rend le refus, sans le terminal. */
    public function testAProvidedIdIsTheKeyOfItsAttempt(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->cardSale($client, $entete);
        $corps = ['moyen' => 'cb', 'montant' => '20.00', 'id' => (string) Uuid::v4()];
        self::assertSame('refuse', $this->pay($client, $entete, $vente, $corps, 'refuse')->toArray()['statutTPE']);

        $rejeu = $this->pay($client, $entete, $vente, $corps);
        self::assertSame([200, 'refuse', true], [$rejeu->getStatusCode(), $rejeu->toArray()['statutTPE'], $rejeu->toArray()['dejaEnregistre']]);
        self::assertSame(0, $this->paymentCount($vente));
    }

    /** Témoin : un `id` nul reste admis comme `id` (lot 1) ; il ne devient pas la clé de la tentative, qui en reçoit une du serveur. */
    public function testANilIdIsStillAnIdButNotAKey(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->cardSale($client, $entete);

        $reponse = $this->pay($client, $entete, $vente, ['moyen' => 'especes', 'montant' => '20.00', 'id' => '00000000-0000-0000-0000-000000000000']);

        self::assertSame(201, $reponse->getStatusCode(), (string) $reponse->getContent(false));
        self::assertSame('00000000-0000-0000-0000-000000000000', $reponse->toArray()['paiement']);
        self::assertSame(0, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM sale_payment_attempt WHERE idempotency_key = UNHEX('00000000000000000000000000000000')"));
    }

    /**
     * La clé retient ce qui a été DEMANDÉ : un montant absent (« le reste dû ») n'est pas le même
     * contenu qu'un montant donné, dans un sens comme dans l'autre (G-4).
     */
    public function testAReplayWithoutTheAmountIsAnotherRequest(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->cardSale($client, $entete);
        $cle = (string) Uuid::v4();
        self::assertSame(201, $this->pay($client, $entete, $vente, ['moyen' => 'especes', 'montant' => '20.00', 'cleIdempotence' => $cle])->getStatusCode());

        $sansMontant = $this->pay($client, $entete, $vente, ['moyen' => 'especes', 'cleIdempotence' => $cle]);
        self::assertSame(422, $sansMontant->getStatusCode(), 'Demandé : 20,00. Rejoué : « le reste dû ». Ce n\'est pas la même demande.');

        $reste = (string) Uuid::v4();
        self::assertSame(201, $this->pay($client, $entete, $vente, ['moyen' => 'especes', 'cleIdempotence' => $reste])->getStatusCode());
        $avecMontant = $this->pay($client, $entete, $vente, ['moyen' => 'especes', 'montant' => '25.00', 'cleIdempotence' => $reste]);
        self::assertSame(422, $avecMontant->getStatusCode(), 'Demandé : « le reste dû ». Rejoué : 25,00.');

        self::assertSame(2, $this->paymentCount($vente));
        $tentative = $this->attemptOf($reste);
        self::assertNotNull($tentative);
        self::assertNull($tentative['requested_amount'], 'Le montant demandé reste « absent » : c\'est ce qui a été demandé.');
        self::assertSame('25.00', $tentative['amount'], 'Le montant encaissé, lui, est le reste dû relu au moment de l\'effet.');
    }

    /**
     * Après un timeout, rien ne repart au terminal (Q-A1) : ni la même clé, ni une autre intention sur la
     * même vente — « résultat inconnu » jusqu'à ce que le caissier déclare ce qu'affiche le terminal (lot 3).
     */
    public function testAfterATimeoutNothingReachesTheTerminalAgain(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->cardSale($client, $entete);
        $corps = ['moyen' => 'cb', 'montant' => '45.00', 'cleIdempotence' => $cle = (string) Uuid::v4()];
        self::assertSame('timeout', $this->pay($client, $entete, $vente, $corps, 'timeout')->toArray()['statutTPE']);

        $rejeu = $this->pay($client, $entete, $vente, $corps, 'refuse');
        self::assertSame(409, $rejeu->getStatusCode(), 'Sollicité, le terminal aurait répondu « refusé » (200).');
        self::assertSame('payment_outcome_unknown', $rejeu->toArray(false)['code']);

        $autre = $this->pay($client, $entete, $vente, ['moyen' => 'especes', 'montant' => '45.00', 'cleIdempotence' => (string) Uuid::v4()]);
        self::assertSame(409, $autre->getStatusCode(), 'La carte a peut-être été débitée : rien d\'autre ne s\'encaisse sur la vente.');
        self::assertSame('payment_outcome_unknown', $autre->toArray(false)['code']);

        self::assertSame(0, $this->paymentCount($vente));
        self::assertSame(['unresolved', 'timeout', 1], [$this->attemptOf($cle)['status'] ?? null, $this->attemptOf($cle)['terminal_status'] ?? null, (int) ($this->attemptOf($cle)['holds_sale'] ?? 0)]);
    }

    /**
     * Un processus mort pendant l'appel au terminal laisse sa tentative « pending ». Au-delà de 120 s,
     * elle devient « unresolved » sans nouvel appel ; en deçà, elle est « en cours ».
     */
    public function testATerminalAttemptPendingForMoreThanTwoMinutesBecomesUnresolved(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);

        $vieille = $this->cardSale($client, $entete, $session['id']);
        $cle = $this->attemptInFlight($vieille, 'cb', true, '-121 seconds');
        $rejeu = $this->pay($client, $entete, $vieille, ['moyen' => 'cb', 'montant' => '45.00', 'cleIdempotence' => $cle], 'refuse');
        self::assertSame(409, $rejeu->getStatusCode());
        self::assertSame('payment_outcome_unknown', $rejeu->toArray(false)['code']);
        self::assertSame('unresolved', $this->attemptOf($cle)['status'] ?? null);

        $recente = $this->cardSale($client, $entete, $session['id']);
        $cle = $this->attemptInFlight($recente, 'cb', true, '-30 seconds');
        $rejeu = $this->pay($client, $entete, $recente, ['moyen' => 'cb', 'montant' => '45.00', 'cleIdempotence' => $cle], 'refuse');
        self::assertSame('payment_in_progress', $rejeu->toArray(false)['code']);
        self::assertSame('pending', $this->attemptOf($cle)['status'] ?? null);

        self::assertSame(0, $this->paymentCount($vieille) + $this->paymentCount($recente));
    }

    /**
     * Le refus de carte s'annonce APRÈS le commit (D7-bis) : à la publication, aucune transaction
     * n'est ouverte et une autre connexion lit déjà la trace. Témoin de non-régression — `main`
     * publiait déjà après son propre `flush()` ; c'est l'unité de ce lot qui pourrait l'avancer.
     */
    public function testACardRefusalIsAnnouncedOnlyOnceCommitted(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $vente = $this->cardSale($client, $entete);
        $connexion = $this->db();
        $lecteur = DriverManager::getConnection($connexion->getParams());
        $vus = [];
        static::getContainer()->get('event_dispatcher')->addListener(
            CardRejectionRecorder::EVENEMENT,
            static function () use ($connexion, $lecteur, &$vus): void {
                $vus[] = [$connexion->isTransactionActive(), (int) $lecteur->fetchOne('SELECT COUNT(*) FROM sale_card_rejection')];
            },
        );

        $this->pay($client, $entete, $vente, ['moyen' => 'cb', 'montant' => '45.00'], 'refuse');
        $lecteur->close();

        self::assertSame([[false, 1]], $vus, 'Une annonce, hors transaction, d\'une trace déjà validée.');
    }

    /** Une unité annulée n'annonce rien : la trace du refus échoue à s'écrire, aucun événement ne part. */
    public function testARolledBackRefusalAnnouncesNothing(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $vente = $this->cardSale($client, $entete);
        $vus = 0;
        static::getContainer()->get('event_dispatcher')->addListener(CardRejectionRecorder::EVENEMENT, static function () use (&$vus): void {
            ++$vus;
        });
        $echec = static::getContainer()->get(FailingWriteListener::class);
        self::assertInstanceOf(FailingWriteListener::class, $echec);

        $echec->failOn(CardRejection::class);
        $reponse = $this->pay($client, $entete, $vente, ['moyen' => 'cb', 'montant' => '45.00', 'cleIdempotence' => $cle = (string) Uuid::v4()], 'refuse');
        $echec->failOn(null);

        self::assertSame(500, $reponse->getStatusCode());
        self::assertSame(0, $vus);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM sale_card_rejection'));
        self::assertSame('refused', $this->attemptOf($cle)['status'] ?? null, 'Le terminal a refusé : aucun argent n\'a bougé, la vente est libre.');
    }
}
