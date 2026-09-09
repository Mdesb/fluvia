<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `facturation_parametre.taux_tva_defaut_id` : le taux appliqué par défaut à tout ce qui est
 * facturable et ne porte pas son propre taux (demande de Maxime, 08/09).
 *
 * ⚠ LE CHIFFRE QUI A JUSTIFIÉ CE CHAMP. La source de vérité reste le taux porté par la chose vendue
 * (`off_produit.taux_tva`). Mesuré en préproduction le 08/09 :
 *
 *     off_produit                      20 produits, 2 portent un taux
 *     dont formule_id IS NOT NULL       4 abonnements, 0 porte un taux
 *
 * Sans repli, la facturation des échéances aurait refusé 100 % des cas — du code juste et un produit
 * inerte. L'ordre devient : le taux de la chose facturée, puis ce défaut, puis un REFUS qui nomme ce
 * qu'il n'a pas su résoudre. On ne devine jamais un taux : un taux faux part dans une facture scellée
 * qui ne se corrige plus, elle s'avoire.
 *
 * ⚠ À NE PAS CONFONDRE AVEC `taux_tva_abonnement_id`, sur la même table. Celui-là sert à facturer
 * l'EXPLOITANT pour Fluvia (`SubscriptionInvoicer`) ; celui-ci sert à l'exploitant pour facturer SES
 * adhérents. Deux impôts sans rapport derrière deux colonnes qui portent presque le même nom.
 *
 * ⚠ AUCUNE VALEUR N'EST POSÉE ICI, ET C'EST DÉLIBÉRÉ. Contrairement aux comptes de trésorerie, un taux
 * de TVA ne se devine pas : 20 % n'est pas évident pour du sport ou de la piscine, où des taux réduits
 * s'appliquent. La colonne naît vide et se règle dans Paramètres › Facturation.
 *
 * Recopiée du DDL réellement produit par Doctrine (`SHOW CREATE TABLE` sur une base créée depuis le
 * mapping) : `FK_8760EDEFDAB72E3B` et `IDX_8760EDEFDAB72E3B` sont les noms qu'il calcule.
 */
final class Version20260908094500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'facturation_parametre : taux de TVA par défaut de l\'établissement, pour tout ce qui est facturable.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE facturation_parametre ADD taux_tva_defaut_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE facturation_parametre ADD CONSTRAINT FK_8760EDEFDAB72E3B FOREIGN KEY (taux_tva_defaut_id) REFERENCES compta_taux_tva (id)');
        $this->addSql('CREATE INDEX IDX_8760EDEFDAB72E3B ON facturation_parametre (taux_tva_defaut_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE facturation_parametre DROP FOREIGN KEY FK_8760EDEFDAB72E3B');
        $this->addSql('DROP INDEX IDX_8760EDEFDAB72E3B ON facturation_parametre');
        $this->addSql('ALTER TABLE facturation_parametre DROP taux_tva_defaut_id');
    }
}
