<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « UN BILLET PAR UNITÉ », RÉGLÉ PAR POINT DE VENTE (CIAP Casa Xanxo).
 *
 * Vendre 3 entrées sortait un seul billet « 3 × Entrée » portant le QR du premier support, alors que
 * la validation émet déjà un support (et un code) par unité. Avec l'option, chaque unité sort sur son
 * propre billet, avec son propre code.
 *
 * Horodatée APRÈS le lot terminal de paiement (Version20261023130000 réécrit `tpe` sur la même
 * table) : la séquence se joue dans l'ordre où elle a été écrite.
 *
 * Sans perte : une colonne s'ajoute, à faux par défaut. Les points de vente existants gardent le
 * billet récapitulatif ; rien ne change tant que personne n'active l'option.
 */
final class Version20261025090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Point de vente : option « un billet par unité ».';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE caisse_point_de_vente ADD un_billet_par_unite TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE caisse_point_de_vente DROP un_billet_par_unite');
    }
}
