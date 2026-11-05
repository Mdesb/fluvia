<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Titre de groupe : un droit d'accès compte N personnes par passage (#337).
 *
 * Un club ou une école se présente avec UN titre ; la porte doit laisser passer N personnes et la
 * fréquentation doit en compter N. `headcount` porte ce N sur le droit, recopié dans la liste
 * locale servie aux bornes (une borne déconnectée doit savoir combien de rotations accorder).
 *
 * Sans perte : la colonne s'ajoute à 1, valeur qui reproduit exactement le comportement antérieur
 * (un titre, une personne). Rien ne change tant que personne ne déclare de titre de groupe.
 *
 * Écrite à la main (jamais un diff brut), horodatée en UTC.
 */
final class Version20261105114442 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Accès : nombre de personnes par titre (titre de groupe).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE acces_droit_acces ADD headcount INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE acces_droit_acces DROP headcount');
    }
}
