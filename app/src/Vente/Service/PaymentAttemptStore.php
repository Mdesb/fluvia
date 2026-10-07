<?php

declare(strict_types=1);

namespace App\Vente\Service;

use App\Vente\Entity\Vente;
use App\Vente\Enum\PaymentAttemptStatus;
use App\Vente\Enum\StatutTPE;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Uid\Uuid;

/**
 * Les tentatives de règlement, en SQL — pourquoi pas l'`EntityManager` : voir `PaymentAttempt`.
 *
 * Chaque changement de statut est un `UPDATE … WHERE status IN (<attendus>)` : il rend faux quand une
 * autre demande a déjà fait passer la tentative ailleurs, et l'appelant en tire la conséquence. Les
 * identifiants se comparent en `UNHEX` (garde-fou n°14).
 *
 * @phpstan-type Attempt array{id: string, sale: string, key: Uuid, method: string, requested: ?string, amount: string, usesTerminal: bool, status: PaymentAttemptStatus, terminal: ?StatutTPE, startedAt: \DateTimeImmutable, reason: ?string}
 */
final class PaymentAttemptStore
{
    private const SELECT = 'SELECT LOWER(HEX(id)) id, LOWER(HEX(sale_id)) sale, idempotency_key, payment_method_code, requested_amount, amount, '
        . 'uses_terminal, status, terminal_status, started_at, failure_reason FROM sale_payment_attempt WHERE ';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Écrit une tentative `pending` qui tient la vente. Appelée hors de toute transaction, elle est
     * validée en base au retour : c'est ce qui la rend visible des autres demandes AVANT l'effet.
     *
     * @return Attempt
     *
     * @throws UniqueConstraintViolationException la clé a déjà sa tentative, ou une autre tient la vente
     */
    public function open(Vente $sale, Uuid $key, string $method, ?string $requested, string $amount, bool $usesTerminal): array
    {
        $tentative = [
            'id' => self::hex(Uuid::v4()),
            'sale' => self::hex($sale->getId()),
            'key' => $key,
            'method' => $method,
            'requested' => $requested,
            'amount' => $amount,
            'usesTerminal' => $usesTerminal,
            'status' => PaymentAttemptStatus::Pending,
            'terminal' => null,
            'startedAt' => new \DateTimeImmutable(),
            'reason' => null,
        ];
        $this->connection->executeStatement(
            'INSERT INTO sale_payment_attempt (id, sale_id, open_sale_id, idempotency_key, payment_method_code, requested_amount, amount, uses_terminal, status, started_at) '
            . 'VALUES (UNHEX(:id), UNHEX(:sale), UNHEX(:sale), UNHEX(:key), :method, :requested, :amount, :terminal, :status, :now)',
            [
                'id' => $tentative['id'],
                'sale' => $tentative['sale'],
                'key' => self::hex($key),
                'method' => $method,
                'requested' => $requested,
                'amount' => $amount,
                'terminal' => (int) $usesTerminal,
                'status' => PaymentAttemptStatus::Pending->value,
                'now' => $tentative['startedAt']->format('Y-m-d H:i:s'),
            ],
        );

        return $tentative;
    }

    /** @return Attempt|null */
    public function byKey(Uuid $key): ?array
    {
        return $this->one(self::SELECT . 'idempotency_key = UNHEX(:v)', self::hex($key));
    }

    /** @return Attempt|null */
    public function byId(string $id): ?array
    {
        return $this->one(self::SELECT . 'id = UNHEX(:v)', $id);
    }

    /**
     * @param bool $locking lecture verrouillante (dans une transaction) : elle lit la dernière version
     *                      validée, et une tentative en cours d'écriture se fait attendre
     *
     * @return Attempt|null la tentative qui tient la vente, s'il y en a une
     */
    public function holding(Vente $sale, bool $locking = false): ?array
    {
        return $this->one(self::SELECT . 'open_sale_id = UNHEX(:v)' . ($locking ? ' LOCK IN SHARE MODE' : ''), self::hex($sale->getId()));
    }

    /**
     * La déclaration du caissier (Q-A1, D122) : une tentative `unresolved` passe à son issue déclarée,
     * libère la vente et garde qui, quand, et la référence du ticket CB. La raison du « sans issue »
     * reste écrite. Faux si la tentative n'était plus `unresolved` (une réponse tardive du terminal,
     * ou une autre déclaration, l'a précédée).
     */
    public function declare(string $id, PaymentAttemptStatus $to, Uuid $by, ?string $cardReference, ?Uuid $payment): bool
    {
        return 1 === (int) $this->connection->executeStatement(
            'UPDATE sale_payment_attempt SET status = :to, open_sale_id = NULL, closed_at = :now, declared_by_id = UNHEX(:by), '
            . 'declared_at = :now, card_reference = :ref, payment_id = UNHEX(:payment) WHERE id = UNHEX(:id) AND status = :from',
            [
                'to' => $to->value,
                'now' => self::now(),
                'by' => self::hex($by),
                'ref' => $cardReference,
                'payment' => $payment !== null ? self::hex($payment) : null,
                'id' => $id,
                'from' => PaymentAttemptStatus::Unresolved->value,
            ],
        );
    }

    /**
     * Ce que l'écran reçoit d'une tentative : de quoi la nommer au caissier et la déclarer.
     *
     * @param Attempt $attempt
     *
     * @return array{id: string, moyen: string, montant: string, statut: string, statutTPE: ?string, depuis: string, raison: ?string}
     */
    public static function summary(array $attempt): array
    {
        return [
            'id' => Uuid::fromBinary((string) hex2bin($attempt['id']))->toRfc4122(),
            'moyen' => $attempt['method'],
            'montant' => $attempt['amount'],
            'statut' => $attempt['status']->value,
            'statutTPE' => $attempt['terminal']?->value,
            'depuis' => $attempt['startedAt']->format(\DATE_ATOM),
            'raison' => $attempt['reason'],
        ];
    }

    /**
     * Fait passer la tentative d'un des statuts `$from` à `$to` ; faux si elle n'y était plus. La vente
     * suit le statut : tenue en `pending` et `unresolved`, libérée sinon. Repasser en `pending` (une
     * demande « failed » rejouée) la redate et peut lever un doublon si une autre tient la vente.
     *
     * @param list<PaymentAttemptStatus> $from
     *
     * @throws UniqueConstraintViolationException retour en `pending` alors qu'une autre tient la vente
     */
    public function move(string $id, array $from, PaymentAttemptStatus $to, ?StatutTPE $terminal = null, ?string $reason = null, ?Uuid $payment = null): bool
    {
        return 1 === (int) $this->connection->executeStatement(
            'UPDATE sale_payment_attempt SET status = :to, open_sale_id = IF(:holds, sale_id, NULL), '
            . 'started_at = IF(:restart, :now, started_at), closed_at = IF(:holds, NULL, :now), '
            . 'terminal_status = COALESCE(:terminal, terminal_status), failure_reason = :reason, '
            . 'payment_id = COALESCE(UNHEX(:payment), payment_id) '
            . 'WHERE id = UNHEX(:id) AND status IN (:from)',
            [
                'to' => $to->value,
                'holds' => (int) $to->holdsTheSale(),
                'restart' => (int) ($to === PaymentAttemptStatus::Pending),
                'now' => self::now(),
                'terminal' => $terminal?->value,
                'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
                'payment' => $payment !== null ? self::hex($payment) : null,
                'id' => $id,
                'from' => array_map(static fn (PaymentAttemptStatus $s): string => $s->value, $from),
            ],
            ['from' => ArrayParameterType::STRING],
        );
    }

    /** Écrit la raison seule, quel que soit le statut : un fait appris après l'issue (réponse tardive du terminal). */
    public function note(string $id, string $reason): void
    {
        $this->connection->executeStatement(
            'UPDATE sale_payment_attempt SET failure_reason = :reason WHERE id = UNHEX(:id)',
            ['reason' => mb_substr($reason, 0, 255), 'id' => $id],
        );
    }

    /** Le montant de l'effet, une fois la vente relue ; faux si la tentative n'est plus `pending`. */
    public function setAmount(string $id, string $amount): bool
    {
        return 1 === (int) $this->connection->executeStatement(
            'UPDATE sale_payment_attempt SET amount = :amount WHERE id = UNHEX(:id) AND status = :pending',
            ['amount' => $amount, 'id' => $id, 'pending' => PaymentAttemptStatus::Pending->value],
        );
    }

    /** Le règlement qui porte cette clé, s'il a été écrit. */
    public function paymentWithKey(Uuid $key): ?Uuid
    {
        $id = $this->connection->fetchOne('SELECT id FROM vente_paiement WHERE cle_idempotence = UNHEX(:k)', ['k' => self::hex($key)]);

        return \is_string($id) ? Uuid::fromString($id) : null;
    }

    /** @return Attempt|null */
    private function one(string $sql, string $value): ?array
    {
        $ligne = $this->connection->fetchAssociative($sql, ['v' => $value]);
        if ($ligne === false) {
            return null;
        }

        return [
            'id' => (string) $ligne['id'],
            'sale' => (string) $ligne['sale'],
            'key' => Uuid::fromString((string) $ligne['idempotency_key']),
            'method' => (string) $ligne['payment_method_code'],
            'requested' => $ligne['requested_amount'] !== null ? (string) $ligne['requested_amount'] : null,
            'amount' => (string) $ligne['amount'],
            'usesTerminal' => (bool) $ligne['uses_terminal'],
            'status' => PaymentAttemptStatus::from((string) $ligne['status']),
            'terminal' => $ligne['terminal_status'] !== null ? StatutTPE::from((string) $ligne['terminal_status']) : null,
            'startedAt' => new \DateTimeImmutable((string) $ligne['started_at']),
            'reason' => $ligne['failure_reason'] !== null ? (string) $ligne['failure_reason'] : null,
        ];
    }

    public static function hex(Uuid $uuid): string
    {
        return bin2hex($uuid->toBinary());
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
