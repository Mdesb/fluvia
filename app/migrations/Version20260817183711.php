<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Stock (plan-stock.md) — correction de longueur de colonne : `stk_lot.origine`
 * (`OrigineLotStock`) et `stk_mouvement.type` (`TypeMouvementStock`) portent des valeurs jusqu'à 25
 * caractères (`regularisation_inventaire`) ; VARCHAR(24) initial était trop court (constaté via test
 * d'intégration API), porté à VARCHAR(32) par cohérence avec les autres colonnes enum du module.
 */
final class Version20260817183711 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module Stock : stk_lot.origine et stk_mouvement.type VARCHAR(24) -> VARCHAR(32) (regularisation_inventaire = 25 caractères).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE stk_lot CHANGE origine origine VARCHAR(32) NOT NULL');
        $this->addSql('ALTER TABLE stk_mouvement CHANGE type type VARCHAR(32) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE stk_lot CHANGE origine origine VARCHAR(24) NOT NULL');
        $this->addSql('ALTER TABLE stk_mouvement CHANGE type type VARCHAR(24) NOT NULL');
    }
}
