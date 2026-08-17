<?php

declare(strict_types=1);

namespace App\Acces\Service;

use Doctrine\DBAL\Connection;

/**
 * Générateur du curseur de version du snapshot terminal (US-TERM-03/04, plan-acces-terminal.md §1.4).
 * Adossé à une séquence native MariaDB (`acces_snapshot_seq`, `CREATE SEQUENCE`, MariaDB ≥ 10.3) :
 * `SELECT NEXT VALUE FOR acces_snapshot_seq` renvoie un entier monotone, sans contention de ligne
 * (contrairement à un compteur mono-ligne `UPDATE ... SET valeur = valeur + 1`). Utilisé à chaque
 * mutation affectant la projection d'un `Support` (blocage/déblocage, appairage/révocation, décompte
 * crédit) pour horodater logiquement `Support.versionMaj`.
 */
final class VersionSnapshotSequencer
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function suivant(): int
    {
        return (int) $this->connection->fetchOne('SELECT NEXT VALUE FOR acces_snapshot_seq');
    }
}
