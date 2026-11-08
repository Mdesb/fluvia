<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * DEMANDES RGPD : D'OÙ VIENT LA DEMANDE (#344).
 *
 * L'épuration sélective et la purge à échéance tracent chaque fiche anonymisée par une
 * `DemandeRGPD` réalisée. Sans origine, ces traces se confondraient avec les demandes des
 * personnes sur l'écran qui porte le délai légal d'un mois.
 *
 * Sans perte : une colonne s'ajoute, à « request » par défaut. Toutes les demandes existantes ont
 * été saisies depuis l'écran, donc par une personne : la valeur par défaut est vraie pour elles.
 * (La tâche de conservation n'a jamais tourné : D109, #207.)
 *
 * Écrite à la main, horodatée en UTC.
 */
final class Version20261105114303 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Demande RGPD : origine (demande de la personne, épuration, conservation échue).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE crm_demande_rgpd ADD origin VARCHAR(16) DEFAULT 'request' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE crm_demande_rgpd DROP origin');
    }
}
