<?php

declare(strict_types=1);

namespace App\Compta\PayFip;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * THE SETTINGS OF THE PAYFIP "RÉGIE" CLIENT — read from the environment, never from the code.
 *
 * Source: DGFiP, « Guide de mise en œuvre PayFiP — Régies de recettes du secteur local — Web service »,
 * version 18.0, août 2019, §3.1 (numéro de client), §3.5.2.1 (paramètres), §3.5.3 (test et activation).
 * The June 2025 edition exists but is no longer published: collectivities obtain it from the
 * "correspondant moyens de paiement" of their DDFiP (collectivites-locales.gouv.fr, page « L'offre de
 * paiement en ligne PayFiP », read on 05/11/2026). Every value below can be overridden without a
 * release, precisely because the edition we could read is not the latest one.
 *
 * - PAYFIP_NUMCLI: the 6-digit client number the DDFiP gives the régie at adhesion (§3.1).
 * - PAYFIP_ENTRY_MODE: SAISIE — `T` test, `X` activation, `W` real payments (§3.5.2.1, §3.5.4).
 *   Defaults to `T`: an unset mode can never take real money.
 * - PAYFIP_NOTIFICATION_URL: absolute public URL of our notification endpoint (URLNOTIF). Its domain
 *   must be declared to the DDFiP, and must not rely on SNI (§2).
 * - PAYFIP_SERVICE_URL / PAYFIP_PAYMENT_PAGE_URL: the web service and the payment page. Defaults are
 *   the payfip.gouv.fr addresses (the tipi.budget.gouv.fr ones of the 2019 guide moved on 20/05/2021).
 *
 * Nothing here is a secret: PayFiP authenticates the régie by its client number and the declared
 * notification domain, not by a key. The configuration is complete or the adapter stays off.
 */
final class PayFipConfiguration
{
    public const DEFAULT_SERVICE_URL = 'https://www.payfip.gouv.fr/tpa/services/securite';
    public const DEFAULT_PAYMENT_PAGE_URL = 'https://www.payfip.gouv.fr/tpa/paiementws.web';

    public const MODE_TEST = 'T';
    public const MODE_ACTIVATION = 'X';
    public const MODE_REAL = 'W';

    private readonly string $clientNumber;
    private readonly string $entryMode;
    private readonly string $notificationUrl;
    private readonly string $serviceUrl;
    private readonly string $paymentPageUrl;

    public function __construct(
        #[Autowire(env: 'default::PAYFIP_NUMCLI')] ?string $clientNumber = null,
        #[Autowire(env: 'default::PAYFIP_ENTRY_MODE')] ?string $entryMode = null,
        #[Autowire(env: 'default::PAYFIP_NOTIFICATION_URL')] ?string $notificationUrl = null,
        #[Autowire(env: 'default::PAYFIP_SERVICE_URL')] ?string $serviceUrl = null,
        #[Autowire(env: 'default::PAYFIP_PAYMENT_PAGE_URL')] ?string $paymentPageUrl = null,
    ) {
        $this->clientNumber = trim((string) $clientNumber);
        $this->entryMode = strtoupper(trim((string) $entryMode)) ?: self::MODE_TEST;
        $this->notificationUrl = trim((string) $notificationUrl);
        $this->serviceUrl = trim((string) $serviceUrl) ?: self::DEFAULT_SERVICE_URL;
        $this->paymentPageUrl = trim((string) $paymentPageUrl) ?: self::DEFAULT_PAYMENT_PAGE_URL;
    }

    /**
     * Complete = the adapter may run. Incomplete = it stays off and the selector falls back to the
     * demonstration stub, which itself refuses to run outside preproduction (fail-closed).
     */
    public function isConfigured(): bool
    {
        return $this->missing() === [];
    }

    /** @return list<string> the variables that are missing or malformed, for the operator's eyes */
    public function missing(): array
    {
        $missing = [];
        if (preg_match('/^\d{6}$/', $this->clientNumber) !== 1) {
            $missing[] = 'PAYFIP_NUMCLI (6 chiffres)';
        }
        if (!\in_array($this->entryMode, [self::MODE_TEST, self::MODE_ACTIVATION, self::MODE_REAL], true)) {
            $missing[] = 'PAYFIP_ENTRY_MODE (T, X ou W)';
        }
        if (!self::isPublicHttpUrl($this->notificationUrl)) {
            $missing[] = 'PAYFIP_NOTIFICATION_URL (URL absolue https)';
        }

        return $missing;
    }

    public function clientNumber(): string
    {
        return $this->clientNumber;
    }

    public function entryMode(): string
    {
        return $this->entryMode;
    }

    public function notificationUrl(): string
    {
        return $this->notificationUrl;
    }

    public function serviceUrl(): string
    {
        return $this->serviceUrl;
    }

    public function paymentPageUrl(string $operationId): string
    {
        return $this->paymentPageUrl . '?idop=' . rawurlencode($operationId);
    }

    /** Test and activation payments must carry whole euros (§3.5.3.1: "les centimes doivent être nuls"). */
    public function requiresWholeEuros(): bool
    {
        return $this->entryMode !== self::MODE_REAL;
    }

    private static function isPublicHttpUrl(string $url): bool
    {
        return preg_match('#^https?://[^\s/]+\.[^\s/]+(/\S*)?$#', $url) === 1;
    }
}
