<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Segments marketing — une définition de clients, jamais une liste.
 *
 * **Aucune colonne ne porte de membres, et c'est le point.** Un segment stocke ses CRITÈRES ; ses
 * membres se calculent à chaque lecture. Une liste figée serait juste le jour de sa création et
 * fausse le lendemain, avec exactement la même allure — et son critère d'acceptation exige qu'il
 * soit « immédiatement disponible et à jour ».
 *
 * `uniq_marketing_segment_label` porte le couple (établissement, libellé) : deux segments du même
 * nom dans le même établissement se ressembleraient sans être le même, et l'exploitant enverrait au
 * mauvais.
 *
 * **Aucune donnée n'est créée ici (D66-ter).** Les permissions `campagne.*` et le segment de
 * démonstration viennent des fixtures, dont la section permissions est rejouée à chaque chargement.
 */
final class Version20260827230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Marketing : segments dynamiques (critères stockés, membres calculés).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE marketing_segment (
                id BINARY(16) NOT NULL,
                label VARCHAR(120) NOT NULL,
                criteria JSON NOT NULL,
                establishment_id BINARY(16) NOT NULL,
                INDEX IDX_B36CAEEA8565851 (establishment_id),
                UNIQUE INDEX uniq_marketing_segment_label (establishment_id, label),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        $this->addSql('ALTER TABLE marketing_segment ADD CONSTRAINT FK_B36CAEEA8565851 FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE marketing_segment');
    }
}
