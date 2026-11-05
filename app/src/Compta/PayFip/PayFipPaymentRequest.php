<?php

declare(strict_types=1);

namespace App\Compta\PayFip;

/**
 * The parameters of `creerPaiementSecurise`, checked against the table of the DGFiP guide
 * (Régie Web service V18.0, §3.5.2.1) BEFORE they leave: PayFiP answers a malformed call with a
 * functional fault, and the buyer would see a dead end instead of a payment page.
 *
 *   NUMCLI 6 digits · EXER 4 digits · REFDET 6 to 30 alphanumerics · OBJET < 100 alphanumerics, no
 *   personal data · MONTANT ≤ 7 digits, in cents, whole euros in test/activation · MEL 6 to 80 ·
 *   URLNOTIF / URLREDIRECT < 250 · SAISIE T | X | W.
 *
 * Construct it with {@see self::forOrder()}: it derives REFDET and OBJET from the order number, so
 * the régisseur reads OUR order number in PayFiP's daily remittance file (§4.3) — that is what makes
 * the reconciliation possible without a lookup table.
 */
final class PayFipPaymentRequest
{
    private function __construct(
        public readonly string $clientNumber,
        public readonly string $year,
        public readonly string $debtReference,
        public readonly string $subject,
        public readonly int $amountCents,
        public readonly string $email,
        public readonly string $notificationUrl,
        public readonly string $redirectUrl,
        public readonly string $entryMode,
    ) {
    }

    /**
     * @param string $orderNumber  our order number (any punctuation is dropped: REFDET is alphanumeric)
     * @param string $fallbackId   used when the order number gives fewer than 6 or more than 30 characters
     */
    public static function forOrder(
        PayFipConfiguration $configuration,
        string $orderNumber,
        string $fallbackId,
        int $amountCents,
        string $email,
        string $redirectUrl,
        \DateTimeImmutable $now,
    ): self {
        $reference = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $orderNumber));
        if (\strlen($reference) < 6 || \strlen($reference) > 30) {
            $reference = (string) preg_replace('/[^A-Za-z0-9]/', '', $fallbackId);
        }

        $request = new self(
            clientNumber: $configuration->clientNumber(),
            year: $now->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y'),
            debtReference: $reference,
            subject: 'Commande en ligne ' . $reference,
            amountCents: $amountCents,
            email: trim($email),
            notificationUrl: $configuration->notificationUrl(),
            redirectUrl: $redirectUrl,
            entryMode: $configuration->entryMode(),
        );
        $request->assertValid();

        return $request;
    }

    /** @return array<string, string> the `arg0` children, in the order of the XSD */
    public function toFields(): array
    {
        return [
            'exer' => $this->year,
            'mel' => $this->email,
            'montant' => (string) $this->amountCents,
            'numcli' => $this->clientNumber,
            'objet' => $this->subject,
            'refdet' => $this->debtReference,
            'saisie' => $this->entryMode,
            'urlnotif' => $this->notificationUrl,
            'urlredirect' => $this->redirectUrl,
        ];
    }

    private function assertValid(): void
    {
        $errors = [];
        if (preg_match('/^\d{6}$/', $this->clientNumber) !== 1) {
            $errors[] = 'numéro de client PayFiP (6 chiffres)';
        }
        if (preg_match('/^[A-Za-z0-9]{6,30}$/', $this->debtReference) !== 1) {
            $errors[] = 'référence de la dette (6 à 30 caractères alphanumériques)';
        }
        if (preg_match('/^[A-Za-z0-9 ]{1,99}$/', $this->subject) !== 1) {
            $errors[] = 'objet (moins de 100 caractères alphanumériques)';
        }
        if ($this->amountCents < 1 || $this->amountCents > 9_999_999) {
            $errors[] = 'montant (de 0,01 € à 99 999,99 €)';
        }
        if ($this->entryMode !== PayFipConfiguration::MODE_REAL && $this->amountCents % 100 !== 0) {
            $errors[] = 'montant : en test et en activation, PayFiP n\'accepte que des euros ronds';
        }
        $emailLength = \strlen($this->email);
        if ($emailLength < 6 || $emailLength > 80 || filter_var($this->email, \FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'adresse électronique de l\'usager (6 à 80 caractères)';
        }
        if ($this->notificationUrl === '' || \strlen($this->notificationUrl) >= 250) {
            $errors[] = 'URL de notification (moins de 250 caractères)';
        }
        if ($this->redirectUrl === '' || \strlen($this->redirectUrl) >= 250) {
            $errors[] = 'URL de retour (moins de 250 caractères)';
        }

        if ($errors !== []) {
            throw new PayFipException('Paiement PayFiP impossible à initier : ' . implode(' ; ', $errors) . '.');
        }
    }
}
