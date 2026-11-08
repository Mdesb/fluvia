<?php

declare(strict_types=1);

namespace App\Compta\PayFip;

/**
 * The answer of `recupererDetailPaiementSecurise` (DGFiP guide, Régie Web service V18.0, §3.5.2.6).
 *
 * RESULTRANS: `P` paid by card, `V` paid by direct debit, `A` card payment abandoned, `R` card
 * refused, `Z` direct debit refused. Only `P` and `V` may update the information system: "Seules les
 * opérations payées doivent donner lieu à mise à jour du système d'information" (same §).
 */
final class PayFipPaymentDetail
{
    public function __construct(
        public readonly string $operationId,
        public readonly string $clientNumber,
        public readonly string $year,
        public readonly string $debtReference,
        public readonly string $subject,
        public readonly string $amount,
        public readonly string $email,
        public readonly string $entryMode,
        public readonly string $result,
        public readonly string $authorizationNumber,
        public readonly string $transactionDate,
        public readonly string $transactionTime,
    ) {
    }

    /** @param array<string, string> $fields the children of `return`, by element name */
    public static function fromFields(array $fields): self
    {
        $get = static fn (string $name): string => trim($fields[$name] ?? '');

        return new self(
            operationId: $get('idOp'),
            clientNumber: $get('numcli'),
            year: $get('exer'),
            debtReference: $get('refdet'),
            subject: $get('objet'),
            amount: $get('montant'),
            email: $get('mel'),
            entryMode: $get('saisie'),
            result: strtoupper($get('resultrans')),
            authorizationNumber: $get('numauto'),
            transactionDate: $get('dattrans'),
            transactionTime: $get('heurtrans'),
        );
    }

    public function isPaid(): bool
    {
        return $this->result === 'P' || $this->result === 'V';
    }

    public function isAbandoned(): bool
    {
        return $this->result === 'A';
    }

    public function isRefused(): bool
    {
        return $this->result === 'R' || $this->result === 'Z';
    }

    /** `null` when MONTANT is not a plain number of cents — such an answer proves nothing. */
    public function amountCents(): ?int
    {
        return preg_match('/^\d{1,7}$/', $this->amount) === 1 ? (int) $this->amount : null;
    }

    /** DATTRANS JJMMAAAA + HEURTRANS HHMM, Paris time; `null` when absent or malformed. */
    public function transactedAt(): ?\DateTimeImmutable
    {
        if (preg_match('/^\d{8}$/', $this->transactionDate) !== 1) {
            return null;
        }
        $time = preg_match('/^\d{4}$/', $this->transactionTime) === 1 ? $this->transactionTime : '0000';
        $date = \DateTimeImmutable::createFromFormat('!dmYHi', $this->transactionDate . $time, new \DateTimeZone('Europe/Paris'));

        return $date === false ? null : $date;
    }
}
