<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Clause « preavis reduit contractuel » sur le creancier SEPA (D115, audit du 14/09).
 *
 * La regle SEPA Core fixe 14 jours de preavis par defaut, reductibles seulement si le contrat du
 * creancier porte une clause de preavis reduit. Ce drapeau la declare : sans lui,
 * `prenotification_delay_days` ne peut pas descendre sous 14 (NoticeDelayCoversPeriodValidator).
 *
 * Colonne booleenne, defaut 0 (false) : l'existant — quatre creanciers a 14 jours — reste valide et
 * n'a aucune clause a declarer.
 */
final class Version20260914130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute preavis_reduit_contractuel a sepa_config_creancier (D115) : condition d un delai < 14 jours.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sepa_config_creancier ADD preavis_reduit_contractuel TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sepa_config_creancier DROP preavis_reduit_contractuel');
    }
}
