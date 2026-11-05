<?php

declare(strict_types=1);

namespace App\Caisse\Service;

use App\Securite\Entity\Utilisateur;

/**
 * La règle du code agent de caisse (#346), sans base ni requête : forme, hachage, comparaison,
 * blocage. Séparée de `AgentPinChecker` pour se prouver en test unitaire.
 *
 * ── LE DÉFAUT QU'ELLE CORRIGE ───────────────────────────────────────────────────────────────────
 *
 * Jusqu'au 05/11/2026, `RouvrirCaisseProcessor` acceptait N'IMPORTE QUEL « code régisseur » non vide :
 * le contrôle vérifiait qu'un code était saisi, jamais lequel. « 0000 » rouvrait une caisse sécurisée.
 * Un code se compare désormais au haché du code personnel de la personne qui l'invoque.
 *
 * ── ⚠ LE BLOCAGE N'EST PAS UNE OPTION ───────────────────────────────────────────────────────────
 *
 * Quatre chiffres, c'est 10 000 possibilités : une boucle les essaie en quelques minutes. Après
 * `MAX_FAILURES` échecs CONSÉCUTIFS, le code est refusé même juste. Le compteur repart à zéro à la
 * première saisie juste (avant le blocage), à la redéfinition par le titulaire, ou à l'effacement
 * par un administrateur.
 */
final class AgentPinPolicy
{
    public const MAX_FAILURES = 5;

    /** 4 à 8 chiffres : saisissable sur un pavé numérique de caisse. */
    private const FORMAT = '/^[0-9]{4,8}$/';

    public static function isWellFormed(string $pin): bool
    {
        return preg_match(self::FORMAT, $pin) === 1;
    }

    public static function hash(string $pin): string
    {
        if (!self::isWellFormed($pin)) {
            throw new \InvalidArgumentException('Le code agent doit compter 4 à 8 chiffres.');
        }

        return password_hash($pin, \PASSWORD_DEFAULT);
    }

    /**
     * Compare `$pin` au code de l'agent et met à jour son compteur d'échecs. Ne persiste rien.
     */
    public static function check(Utilisateur $agent, string $pin): AgentPinOutcome
    {
        $hash = $agent->getCashPinHash();
        if ($hash === null) {
            return AgentPinOutcome::Undefined;
        }
        if ($agent->getCashPinFailures() >= self::MAX_FAILURES) {
            return AgentPinOutcome::Locked;
        }
        if (password_verify($pin, $hash)) {
            $agent->setCashPinFailures(0);

            return AgentPinOutcome::Accepted;
        }
        $agent->setCashPinFailures($agent->getCashPinFailures() + 1);

        return $agent->getCashPinFailures() >= self::MAX_FAILURES
            ? AgentPinOutcome::Locked
            : AgentPinOutcome::Rejected;
    }

    public static function remainingAttempts(Utilisateur $agent): int
    {
        return max(0, self::MAX_FAILURES - $agent->getCashPinFailures());
    }
}
