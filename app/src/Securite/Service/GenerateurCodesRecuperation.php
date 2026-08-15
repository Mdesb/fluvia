<?php

declare(strict_types=1);

namespace App\Securite\Service;

/**
 * Codes de récupération MFA (§2.3 plan-backoffice.md) : générés en clair une seule fois à
 * l'activation, persistés hachés (sha256, usage unique chacun — pas besoin d'un hash lent type
 * bcrypt, la haute entropie du code suffit).
 */
final class GenerateurCodesRecuperation
{
    private const NOMBRE_CODES = 8;

    /** @return list<string> codes en clair (à afficher une seule fois, jamais persistés tels quels) */
    public function genererCodesClair(): array
    {
        $codes = [];
        for ($i = 0; $i < self::NOMBRE_CODES; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(5))); // 10 caractères hexadécimaux
        }

        return $codes;
    }

    /**
     * @param list<string> $codesClair
     *
     * @return list<string> hashes sha256, à persister
     */
    public function hacher(array $codesClair): array
    {
        return array_map(static fn (string $code): string => hash('sha256', $code), $codesClair);
    }

    /**
     * Vérifie un code fourni contre la liste de hashes persistés et retourne la liste MISE À JOUR
     * (le hash consommé retiré) si le code était valide, `null` sinon.
     *
     * @param list<string> $hashesPersistes
     *
     * @return list<string>|null
     */
    public function consommer(array $hashesPersistes, string $codeFourni): ?array
    {
        $hash = hash('sha256', strtoupper(trim($codeFourni)));
        $index = array_search($hash, $hashesPersistes, true);
        if ($index === false) {
            return null;
        }

        unset($hashesPersistes[$index]);

        return array_values($hashesPersistes);
    }
}
