<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot D2-B de #365 — le numéro client PayFiP (NUMCLI) de la régie : colonne `payfip_client_number` sur
 * `compta_profil_exploitant`.
 *
 * Écrite à la main (jamais un diff brut), SQL relevé par `doctrine:schema:update --dump-sql`, horodatée
 * en UTC après la dernière migration (Version20261121110000). Colonne nullable, sans défaut : aucune
 * reprise de données, aucune régie n'a de numéro en base.
 *
 * `down()` retire la colonne, donc les numéros saisis : les régies retombent sur le bouchon.
 */
final class Version20261125100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D2-B #365 : colonne compta_profil_exploitant.payfip_client_number (NUMCLI PayFiP de la régie, 6 chiffres)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compta_profil_exploitant ADD payfip_client_number VARCHAR(6) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compta_profil_exploitant DROP COLUMN payfip_client_number');
    }
}
