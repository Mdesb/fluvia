<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot D2-B de #365 — le numéro client PayFiP envoyé à l'initiation est figé sur le bordereau de l'opération :
 * colonne `client_number` sur `compta_bordereau_payfip`.
 *
 * Écrite à la main (jamais un diff brut), SQL relevé par `doctrine:schema:update --dump-sql`. Colonne nullable,
 * sans défaut, sans reprise : un bordereau antérieur n'a pas de numéro figé et n'atteste donc rien au retour
 * (fermeture à l'échec) ; aucun encaissement réel n'a eu lieu avant ce lot.
 *
 * `down()` retire la colonne : les numéros figés des opérations en cours sont perdus.
 */
final class Version20261125110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D2-B #365 : colonne compta_bordereau_payfip.client_number (NUMCLI figé à l\'initiation)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compta_bordereau_payfip ADD client_number VARCHAR(6) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compta_bordereau_payfip DROP COLUMN client_number');
    }
}
