<?php

declare(strict_types=1);

namespace App\Acces\Service;

use App\Acces\Entity\JetonTerminal;
use App\Acces\Entity\Terminal;
use App\Acces\Enum\StatutJetonTerminal;

/**
 * Émission d'un `JetonTerminal` (US-TERM-01/09, plan-acces-terminal.md §2.1) : secret à forte entropie
 * généré côté serveur (`random_bytes`, CSPRNG), hashé (`sha256`, Risque R-1) avant persistance. Le
 * secret en clair n'est **jamais** conservé — retourné une seule fois à l'appelant (enrôlement/rotation).
 */
final class JetonTerminalFactory
{
    private const LONGUEUR_SECRET_OCTETS = 32;

    /** @return array{0: JetonTerminal, 1: string} le jeton (non persisté) et le secret en clair */
    public function emettre(Terminal $terminal): array
    {
        $secret = bin2hex(random_bytes(self::LONGUEUR_SECRET_OCTETS));

        $jeton = new JetonTerminal();
        $jeton->setTerminal($terminal)
            ->setSecretHash(hash('sha256', $secret))
            ->setStatut(StatutJetonTerminal::Actif)
            ->setEtablissement($terminal->getEtablissement());

        return [$jeton, $secret];
    }
}
