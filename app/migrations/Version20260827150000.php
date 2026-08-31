<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gestion de projet : un travail humain, décidé en interne, avec une échéance.
 *
 * **À ne confondre avec ni l'un ni l'autre de ce qui existait.** `platform_scheduled_task_run` est une
 * tâche *machine*, planifiée en cron. `support_ticket` arrive de l'extérieur et se ferme quand on a
 * répondu. Un projet, c'est refaire les vestiaires, ouvrir la patinoire éphémère, préparer la saison.
 *
 * La distinction mérite d'être écrite dans la migration elle-même, parce qu'elle se perd vite : le
 * jour où quelqu'un range une relance commerciale ici plutôt que dans un ticket, **il existe deux
 * endroits où chercher du travail en cours**, et plus personne ne regarde les deux.
 *
 * ---
 *
 * **CE QUE CES DEUX TABLES NE PORTENT PAS, ET QUI EST DÉLIBÉRÉ.**
 *
 * *Aucune colonne d'avancement.* Il se compte depuis les tâches. Une colonne demanderait d'être
 * recalculée à chaque modification — donc partout, donc oubliée quelque part. Un pourcentage faux est
 * pire qu'absent : il rassure.
 *
 * *Aucun statut « en retard ».* C'est une échéance comparée à aujourd'hui. En faire un état obligerait
 * à le maintenir toutes les nuits, et un projet serait à l'heure jusqu'au prochain passage, puis en
 * retard d'un coup, sans que rien ne se soit produit.
 *
 * > **Un chiffre qu'on peut compter ne se recopie pas ; un état qui se calcule ne se stocke pas.**
 *
 * *Aucun établissement sur la tâche.* Elle tient le sien de son projet — patron des entités
 * satellites du dépôt. Une colonne propre ouvrirait la possibilité qu'une tâche appartienne à un autre
 * établissement que son projet, incohérence que rien ne rattraperait.
 *
 * DDL relevé par `doctrine:schema:update --dump-sql` sur le mapping (D32).
 */
final class Version20260827150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gestion de projet : projets et tâches, rattachés à un établissement.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE project_project (
                id BINARY(16) NOT NULL,
                name VARCHAR(200) NOT NULL,
                description LONGTEXT DEFAULT NULL,
                status VARCHAR(20) NOT NULL,
                start_date DATE DEFAULT NULL,
                due_date DATE DEFAULT NULL,
                created_at DATETIME NOT NULL,
                establishment_id BINARY(16) NOT NULL,
                owner_id BINARY(16) DEFAULT NULL,
                INDEX IDX_B9ADDC8B8565851 (establishment_id),
                INDEX IDX_B9ADDC8B7E3C61F9 (owner_id),
                INDEX idx_project_establishment_status (establishment_id, status),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4'
        );

        $this->addSql(
            'CREATE TABLE project_task (
                id BINARY(16) NOT NULL,
                title VARCHAR(250) NOT NULL,
                status VARCHAR(20) NOT NULL,
                due_date DATE DEFAULT NULL,
                position INT DEFAULT 0 NOT NULL,
                done_at DATETIME DEFAULT NULL,
                project_id BINARY(16) NOT NULL,
                assignee_id BINARY(16) DEFAULT NULL,
                INDEX IDX_6BEF133D166D1F9C (project_id),
                INDEX IDX_6BEF133D59EC7D60 (assignee_id),
                INDEX idx_project_task_project (project_id, status),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4'
        );

        $this->addSql(
            'ALTER TABLE project_project
             ADD CONSTRAINT FK_B9ADDC8B8565851 FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)'
        );
        $this->addSql(
            'ALTER TABLE project_project
             ADD CONSTRAINT FK_B9ADDC8B7E3C61F9 FOREIGN KEY (owner_id) REFERENCES sec_utilisateur (id)'
        );
        $this->addSql(
            'ALTER TABLE project_task
             ADD CONSTRAINT FK_6BEF133D166D1F9C FOREIGN KEY (project_id) REFERENCES project_project (id)'
        );
        $this->addSql(
            'ALTER TABLE project_task
             ADD CONSTRAINT FK_6BEF133D59EC7D60 FOREIGN KEY (assignee_id) REFERENCES sec_utilisateur (id)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project_task DROP FOREIGN KEY FK_6BEF133D59EC7D60');
        $this->addSql('ALTER TABLE project_task DROP FOREIGN KEY FK_6BEF133D166D1F9C');
        $this->addSql('ALTER TABLE project_project DROP FOREIGN KEY FK_B9ADDC8B7E3C61F9');
        $this->addSql('ALTER TABLE project_project DROP FOREIGN KEY FK_B9ADDC8B8565851');
        $this->addSql('DROP TABLE project_task');
        $this->addSql('DROP TABLE project_project');
    }
}
