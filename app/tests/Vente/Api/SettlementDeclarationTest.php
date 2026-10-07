<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\DataFixtures\SocleFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Crm\CrmApiTestCase;
use App\Tests\Vente\Support\SettlementScenarios;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * UN TERMINAL MUET SE FAIT CONSTATER, IL NE SE RELANCE PAS (G-6 du ticket opposable, Q-A1, D122).
 *
 * Après un timeout, la tentative reste `unresolved` et tient la vente : rien ne repart au terminal.
 * Le lot 2 s'arrêtait là, et la vente restait bloquée — ni payable, ni validable, ni annulable, et
 * sa session ne se clôturait plus. Ce lot apporte la sortie : le caissier déclare ce qu'affiche le
 * terminal.
 * - « accepté » : il saisit la référence du ticket CB ; le règlement est écrit avec la clé et le
 *   montant de la tentative, sans terminal ; qui, quand et quelle tentative restent écrits.
 * - « non passé » : la tentative est close, la vente libérée, un nouvel envoi permis.
 *
 * Et la validation attend : tant qu'une tentative tient la vente, elle est refusée (D-4 du plan).
 * Les témoins sont lus EN BASE.
 */
final class SettlementDeclarationTest extends CrmApiTestCase
{
    use SettlementScenarios;

    /** Le refus d'un règlement sur une vente tenue nomme la tentative à déclarer : l'écran en a besoin après un F5. */
    public function testAfterATimeoutTheConflictNamesTheAttemptToDeclare(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->cardSale($client, $entete);
        $this->timeout($client, $entete, $vente);

        $reponse = $this->pay($client, $entete, $vente, ['moyen' => 'especes', 'cleIdempotence' => (string) Uuid::v4()]);

        self::assertSame(409, $reponse->getStatusCode());
        $corps = $reponse->toArray(false);
        self::assertSame('payment_outcome_unknown', $corps['code']);
        self::assertSame(['cb', '45.00'], [$corps['tentative']['moyen'] ?? null, $corps['tentative']['montant'] ?? null]);
        self::assertSame($this->attemptIdOnSale($vente), $corps['tentative']['id'] ?? null);
    }

    /** « Accepté » : un règlement, avec la référence du ticket CB, la clé et le montant de la tentative ; la trace dit qui et quand. */
    public function testDeclaredAcceptedWritesOnePaymentAndTheTrace(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->cardSale($client, $entete);
        $cle = $this->timeout($client, $entete, $vente);
        $tentative = $this->attemptIdOnSale($vente);

        $reponse = $this->declare($client, $entete, $vente, ['tentative' => $tentative, 'issue' => 'accepte', 'referenceCarte' => ' CB-004211 ']);

        self::assertSame(201, $reponse->getStatusCode(), $reponse->getContent(false));
        $corps = $reponse->toArray();
        self::assertTrue($corps['reglementEnregistre']);
        self::assertSame(['45.00', '0.00', 'declared_accepted'], [$corps['montant'], $corps['resteAPayer'], $corps['issue']]);
        $paiement = $this->db()->fetchAssociative(
            'SELECT LOWER(HEX(id)) id, moyen_code, montant, ref_tpe, statut_tpe, LOWER(HEX(cle_idempotence)) cle FROM vente_paiement WHERE vente_id = UNHEX(:v)',
            ['v' => $this->hex($vente)],
        );
        self::assertSame(['cb', '45.00', 'CB-004211', 'accepte', $this->hex($cle)], [$paiement['moyen_code'], $paiement['montant'], $paiement['ref_tpe'], $paiement['statut_tpe'], $paiement['cle']]);
        $trace = $this->db()->fetchAssociative(
            'SELECT status, LOWER(HEX(declared_by_id)) par, declared_at, card_reference, open_sale_id IS NULL libre, LOWER(HEX(payment_id)) paiement FROM sale_payment_attempt WHERE id = UNHEX(:id)',
            ['id' => $this->hex($tentative)],
        );
        self::assertSame(['declared_accepted', $this->hex($this->idAdmin()), 'CB-004211', 1, $paiement['id']], [$trace['status'], $trace['par'], $trace['card_reference'], (int) $trace['libre'], $trace['paiement']]);
        self::assertNotNull($trace['declared_at']);

        // La même déclaration rejouée (réponse perdue, double clic) rend la même issue, sans second règlement.
        $rejeu = $this->declare($client, $entete, $vente, ['tentative' => $tentative, 'issue' => 'accepte', 'referenceCarte' => 'CB-004211']);
        self::assertSame(200, $rejeu->getStatusCode());
        self::assertTrue($rejeu->toArray()['dejaEnregistre']);
        // La clé de la tentative rend le règlement déclaré : le terminal n'est pas sollicité (forcé en refus, il aurait refusé).
        $cleRejouee = $this->pay($client, $entete, $vente, ['moyen' => 'cb', 'montant' => '45.00', 'cleIdempotence' => $cle], 'refuse');
        self::assertSame([200, true, true], [$cleRejouee->getStatusCode(), $cleRejouee->toArray()['reglementEnregistre'], $cleRejouee->toArray()['dejaEnregistre']]);
        self::assertSame(1, $this->paymentCount($vente));

        $client->request('POST', '/api/ventes/' . $vente . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();
    }

    /** « Non passé » : rien d'écrit sinon la trace, la vente est libérée, un nouvel envoi passe — l'ancienne clé, elle, ne repart pas. */
    public function testDeclaredNotProcessedFreesTheSaleForANewSend(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->cardSale($client, $entete);
        $cle = $this->timeout($client, $entete, $vente);
        $tentative = $this->attemptIdOnSale($vente);

        $reponse = $this->declare($client, $entete, $vente, ['tentative' => $tentative, 'issue' => 'non_passe']);

        self::assertSame(200, $reponse->getStatusCode(), $reponse->getContent(false));
        self::assertSame([false, 'declared_not_processed', '45.00'], [$reponse->toArray()['reglementEnregistre'], $reponse->toArray()['issue'], $reponse->toArray()['resteAPayer']]);
        $trace = $this->db()->fetchAssociative('SELECT status, declared_by_id IS NOT NULL signee, card_reference, open_sale_id IS NULL libre FROM sale_payment_attempt WHERE id = UNHEX(:id)', ['id' => $this->hex($tentative)]);
        self::assertSame(['declared_not_processed', 1, null, 1], [$trace['status'], (int) $trace['signee'], $trace['card_reference'], (int) $trace['libre']]);

        $ancienne = $this->pay($client, $entete, $vente, ['moyen' => 'cb', 'montant' => '45.00', 'cleIdempotence' => $cle], 'accepte');
        self::assertSame(422, $ancienne->getStatusCode(), 'La clé déclarée « non passé » ne repart pas au terminal (forcé en accepté, il aurait encaissé).');
        self::assertSame(0, $this->paymentCount($vente));

        $nouvelle = $this->pay($client, $entete, $vente, ['moyen' => 'cb', 'montant' => '45.00', 'cleIdempotence' => (string) Uuid::v4()], 'accepte');
        self::assertSame(201, $nouvelle->getStatusCode());
        self::assertSame(1, $this->paymentCount($vente));
    }

    /** Une déclaration incomplète ne change rien : « accepté » exige la référence (64 caractères au plus), l'issue est l'une des deux. */
    public function testAnIncompleteDeclarationChangesNothing(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->cardSale($client, $entete);
        $this->timeout($client, $entete, $vente);
        $tentative = $this->attemptIdOnSale($vente);

        foreach ([
            ['tentative' => $tentative, 'issue' => 'accepte'],
            ['tentative' => $tentative, 'issue' => 'accepte', 'referenceCarte' => '   '],
            ['tentative' => $tentative, 'issue' => 'accepte', 'referenceCarte' => str_repeat('7', 65)],
            ['tentative' => $tentative, 'issue' => 'peut-etre'],
            ['issue' => 'non_passe'],
            ['tentative' => (string) Uuid::v4(), 'issue' => 'non_passe'],
        ] as $corps) {
            self::assertSame(422, $this->declare($client, $entete, $vente, $corps)->getStatusCode(), (string) json_encode($corps));
        }
        self::assertSame(['unresolved', 0], [$this->attemptStatus($tentative), $this->paymentCount($vente)]);
    }

    /**
     * La validation attend une issue définitive (D-4 du plan) : avec un paiement différé, le reste dû
     * ne l'arrête pas, et elle passerait sur une vente dont la carte a peut-être été débitée.
     */
    public function testValidationIsRefusedWhileAnAttemptHoldsTheSale(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->cardSale($client, $entete);
        $differe = $this->pay($client, $entete, $vente, ['moyen' => 'differe', 'montant' => '20.00', 'differe' => true]);
        self::assertSame(201, $differe->getStatusCode(), 'témoin : le différé laisse 25,00 dus et autorise la validation');
        $this->timeout($client, $entete, $vente, '25.00');

        $refus = $client->request('POST', '/api/ventes/' . $vente . '/valider', $entete + ['json' => []]);
        self::assertSame(409, $refus->getStatusCode());
        self::assertSame('payment_outcome_unknown', $refus->toArray(false)['code']);
        self::assertSame($this->attemptIdOnSale($vente), $refus->toArray(false)['tentative']['id'] ?? null);
        self::assertSame('en_cours', $this->saleStatus($vente));

        $this->declare($client, $entete, $vente, ['tentative' => $this->attemptIdOnSale($vente), 'issue' => 'non_passe']);
        $client->request('POST', '/api/ventes/' . $vente . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();
    }

    /** Une tentative encore en cours se refuse « en cours » : rien ne se déclare pendant que le terminal travaille. Périmée, elle se déclare. */
    public function testAnAttemptInFlightCannotBeDeclaredUntilItIsStale(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete)['id'];
        $vente = $this->cardSale($client, $entete, $session);
        $this->attemptInFlight($vente, 'cb', true, '-5 seconds');
        $enCours = $this->declare($client, $entete, $vente, ['tentative' => $this->attemptIdOnSale($vente), 'issue' => 'non_passe']);
        self::assertSame([409, 'payment_in_progress'], [$enCours->getStatusCode(), $enCours->toArray(false)['code']]);

        $autre = $this->cardSale($client, $entete, $session);
        $this->attemptInFlight($autre, 'cb', true, '-200 seconds');
        $perimee = $this->declare($client, $entete, $autre, ['tentative' => $this->attemptIdOnSale($autre), 'issue' => 'non_passe']);
        self::assertSame(200, $perimee->getStatusCode(), $perimee->getContent(false));
    }

    /** Une tentative qui a trouvé son issue (ici un refus du terminal) ne se déclare pas : on ne réécrit pas ce qui est su. */
    public function testAnAttemptWithAKnownOutcomeCannotBeDeclared(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->cardSale($client, $entete);
        $cle = (string) Uuid::v4();
        $this->pay($client, $entete, $vente, ['moyen' => 'cb', 'montant' => '45.00', 'cleIdempotence' => $cle], 'refuse');
        $tentative = $this->rfc4122((string) $this->db()->fetchOne('SELECT HEX(id) FROM sale_payment_attempt WHERE idempotency_key = UNHEX(:k)', ['k' => $this->hex($cle)]));

        $reponse = $this->declare($client, $entete, $vente, ['tentative' => $tentative, 'issue' => 'accepte', 'referenceCarte' => 'CB-1']);

        self::assertSame([409, 'payment_outcome_known'], [$reponse->getStatusCode(), $reponse->toArray(false)['code']]);
        self::assertSame(['refused', 0], [$this->attemptStatus($tentative), $this->paymentCount($vente)]);
    }

    /** Le geste est celui d'un caissier de l'établissement : sans `vente.encaisser`, 403 ; d'un autre établissement, 404. */
    public function testOnlyACashierOfTheEstablishmentDeclares(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->cardSale($client, $entete);
        $this->timeout($client, $entete, $vente);
        $corps = ['tentative' => $this->attemptIdOnSale($vente), 'issue' => 'accepte', 'referenceCarte' => 'CB-9'];

        // Le même administrateur, qui encaisse aussi sur B, travaillant sur B : la vente de A n'existe pas pour lui.
        $surB = ['auth_bearer' => $entete['auth_bearer'], 'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_B_NOM)]];
        self::assertSame(404, $this->declare($client, $surB, $vente, $corps)->getStatusCode());
        [$agent, $enteteAgent] = $this->agentSurA();
        self::assertSame(403, $this->declare($agent, $enteteAgent, $vente, $corps)->getStatusCode());
        self::assertSame(['unresolved', 0], [$this->attemptStatus($corps['tentative']), $this->paymentCount($vente)]);
    }

    /**
     * Un règlement par carte que le terminal laisse sans issue (`X-Tpe-Simule: timeout`).
     *
     * @param array<string, mixed> $entete
     *
     * @return string sa clé
     */
    private function timeout(Client $client, array $entete, string $vente, string $montant = '45.00'): string
    {
        $cle = (string) Uuid::v4();
        $reponse = $this->pay($client, $entete, $vente, ['moyen' => 'cb', 'montant' => $montant, 'cleIdempotence' => $cle], 'timeout');
        self::assertSame('timeout', $reponse->toArray()['statutTPE'] ?? null, 'témoin : le terminal est resté muet');
        self::assertSame('unresolved', $this->attemptOf($cle)['status'] ?? null);

        return $cle;
    }

    /**
     * @param array<string, mixed> $entete
     * @param array<string, mixed> $corps
     */
    private function declare(Client $client, array $entete, string $vente, array $corps): ResponseInterface
    {
        return $client->request('POST', '/api/ventes/' . $vente . '/declarer-reglement', $entete + ['json' => $corps]);
    }

    /** L'identifiant de la tentative qui tient la vente, sous la forme où l'écran le reçoit. */
    private function attemptIdOnSale(string $vente): string
    {
        return $this->rfc4122((string) $this->db()->fetchOne('SELECT HEX(id) FROM sale_payment_attempt WHERE open_sale_id = UNHEX(:v)', ['v' => $this->hex($vente)]));
    }

    private function rfc4122(string $hex): string
    {
        return Uuid::fromBinary((string) hex2bin($hex))->toRfc4122();
    }

    private function attemptStatus(string $id): string
    {
        return (string) $this->db()->fetchOne('SELECT status FROM sale_payment_attempt WHERE id = UNHEX(:id)', ['id' => str_replace('-', '', $id)]);
    }

    private function saleStatus(string $vente): string
    {
        return (string) $this->db()->fetchOne('SELECT statut FROM vente_vente WHERE id = UNHEX(:v)', ['v' => $this->hex($vente)]);
    }
}
