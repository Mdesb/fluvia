<?php

declare(strict_types=1);

namespace App\Kiosk\Service;

/**
 * Les trois secrets d'une borne, et la seule façon de les fabriquer et de les comparer.
 *
 * - le CODE D'APPAIRAGE : huit caractères lisibles (« K7QM-3XPA »), sans 0/O ni 1/I/L, qu'un agent
 *   recopie de l'écran de gestion sur la borne. Un quart d'heure, usage unique. 32⁸ ≈ 10¹² valeurs :
 *   à cent essais par seconde sur la durée de validité, une chance sur dix millions — et la route est
 *   de plus bornée par adresse (`kiosk_pairing`).
 * - le JETON D'APPAREIL : 256 bits aléatoires, rendus une fois à la borne. Seule son empreinte SHA-256
 *   est gardée (comme le jeton d'un terminal d'accès) : une fuite de la base n'ouvre aucune borne.
 * - le CODE DE SORTIE du personnel : quelques chiffres, donc haché lentement (`password_hash`) et
 *   borné par un verrou après cinq échecs (`KioskUnlock`).
 */
final class KioskSecrets
{
    private const PAIRING_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const PAIRING_LENGTH = 8;

    public function newPairingCode(): string
    {
        $code = '';
        $max = \strlen(self::PAIRING_ALPHABET) - 1;
        for ($i = 0; $i < self::PAIRING_LENGTH; ++$i) {
            $code .= self::PAIRING_ALPHABET[random_int(0, $max)];
        }

        return substr($code, 0, 4) . '-' . substr($code, 4);
    }

    /** Ce que l'agent a recopié, débarrassé des tirets, espaces et minuscules qu'il a pu y mettre. */
    public function normalizePairingCode(string $typed): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $typed));
    }

    public function hashPairingCode(string $code): string
    {
        return hash('sha256', 'kiosk-pairing:' . $this->normalizePairingCode($code));
    }

    public function newDeviceToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function hashDeviceToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function hashExitCode(string $code): string
    {
        return password_hash($code, \PASSWORD_DEFAULT);
    }

    public function exitCodeMatches(string $typed, ?string $hash): bool
    {
        return $hash !== null && $typed !== '' && password_verify($typed, $hash);
    }
}
