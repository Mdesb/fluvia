<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute `reservation_ressource.verticale` (#100, option B).
 *
 * La ressource porte sa verticale metier (id de module : « padel », « piscine »…) pour que le
 * vocabulaire s'affiche par ressource dans un etablissement mixte. Nullable : non renseignee, le
 * front retombe sur la verticale de l'etablissement puis sur le defaut FR.
 */
final class Version20260914150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute reservation_ressource.verticale (#100) : la ressource porte sa verticale metier.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation_ressource ADD verticale VARCHAR(40) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation_ressource DROP verticale');
    }
}
