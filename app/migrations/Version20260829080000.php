<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Un lecteur peut desservir plusieurs zones.
 *
 * Un tourniquet placé entre la piscine et la salle de sport dessert les deux. Depuis que les
 * produits déclarent les zones qu'ils ouvrent, un abonnement salle s'y voyait refuser l'entrée : le
 * contrôleur ne connaissait qu'un espace.
 *
 * ── AJOUT ET NON CHANGEMENT DE CARDINALITÉ ──────────────────────────────────────────────────────
 *
 * `acces_controleur.espace_id` reste l'espace PRINCIPAL — la porte physique. Ses sept lecteurs dans
 * `App\Acces` (décision, anti-passback, comptage, ouverture manuelle, synchro, validation de
 * topologie, instantané terminal) ont tous besoin d'un espace UNIQUE : un passage a lieu à un seul
 * endroit, et une jauge ne se décrémente pas sur un ensemble.
 *
 * Cette table n'élargit donc QUE la décision d'accès. La jauge, la portée de l'anti-passback et
 * l'espace inscrit sur le passage restent ceux du principal, même quand le titre est accepté au
 * titre d'un espace desservi.
 *
 * ── `ON DELETE CASCADE` DES DEUX CÔTÉS, ET C'EST JUSTE ICI ──────────────────────────────────────
 *
 * Contrairement aux déclarations de zone d'un produit, une ligne d'ici ne porte aucune décision par
 * elle-même : elle relie deux objets qui existent par ailleurs. Supprimer l'un rend la ligne
 * dénuée de sens, et la garder ne protégerait rien.
 *
 * DDL relevé sur le mapping (D32), écrit à la main.
 */
final class Version20260829080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Zones supplémentaires desservies par un lecteur.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE access_controller_served_space (
                controleur_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                espace_acces_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                INDEX IDX_controller_served_controller (controleur_id),
                INDEX IDX_controller_served_space (espace_acces_id),
                PRIMARY KEY(controleur_id, espace_acces_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql('ALTER TABLE access_controller_served_space ADD CONSTRAINT FK_controller_served_controller FOREIGN KEY (controleur_id) REFERENCES acces_controleur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE access_controller_served_space ADD CONSTRAINT FK_controller_served_space FOREIGN KEY (espace_acces_id) REFERENCES acces_espace_acces (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // Redescendre ramène chaque lecteur à sa seule zone principale : un tourniquet mixte
        // redevient refusant pour les titres de l'activité voisine. Perte de réglage, pas de donnée.
        $this->addSql('DROP TABLE access_controller_served_space');
    }
}
