<?php

declare(strict_types=1);

namespace App\Vente\Nf525;

/**
 * Résultat de la vérification d'une chaîne NF525 (CA-15). Si la chaîne est rompue (trou de séquence,
 * empreinte incohérente, signature invalide), `intacte` est faux et `anomalies` détaille les
 * maillons fautifs — c'est l'« alerte de contrôle ».
 */
final class RapportVerification
{
    /**
     * @param list<array{sequence: int, type: string, probleme: string}> $anomalies
     */
    public function __construct(
        public readonly bool $intacte,
        public readonly int $nbOperations,
        public readonly array $anomalies = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'intacte' => $this->intacte,
            'nbOperations' => $this->nbOperations,
            'anomalies' => $this->anomalies,
            'alerte' => $this->intacte ? null : 'Rupture de chaîne NF525 détectée (alerte de contrôle).',
        ];
    }
}
