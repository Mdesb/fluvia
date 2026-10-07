<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tarif « toute l'année » : la saison d'une case de grille devient facultative (06/10/2026).
 *
 * L'écran des tarifs proposait « Toute l'année » par défaut et envoyait une case sans saison, que le
 * serveur refusait. Décision de Maxime : une case sans saison vaut toute l'année, et une case d'une
 * saison précise l'emporte sur elle pendant cette saison (`ResolveurPrix`).
 *
 * La clé étrangère `FK_40653BC9F965414C` et l'index unique `uniq_grille_triplet` restent : MariaDB
 * accepte NULL dans une colonne référençante et dans un index unique. L'unicité des cases sans
 * saison est vérifiée par l'application (`UniqueEntity` sur `GrilleTarifaire`), la contrainte SQL
 * laissant passer deux NULL.
 */
final class Version20261006105004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Grille tarifaire : saison facultative (null = toute l\'année)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE off_grille_tarifaire MODIFY saison_id BINARY(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Lu tout de suite, avant toute écriture : on ne peut pas inventer une saison à une case
        // « toute l'année ». Refuser vaut mieux que supprimer des prix.
        $sansSaison = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM off_grille_tarifaire WHERE saison_id IS NULL');
        $this->abortIf(
            $sansSaison > 0,
            sprintf('%d case(s) de grille sans saison (toute l\'année) : leur attribuer une saison ou les supprimer avant de revenir en arrière.', $sansSaison),
        );

        $this->addSql('ALTER TABLE off_grille_tarifaire MODIFY saison_id BINARY(16) NOT NULL');
    }
}
