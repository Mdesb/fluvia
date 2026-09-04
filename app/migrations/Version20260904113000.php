<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L'essai gratuit de quatorze jours, et la confirmation d'adresse qui le garde (ED-5).
 *
 * ⚠ **L'UNICITÉ SUR L'EMPREINTE N'EST PAS DE LA PRUDENCE.** La confirmation retrouve l'abonnement
 * par `findOneBy(['emailConfirmationTokenHash' => ...])`. Sur une colonne non unique, deux lignes de
 * même empreinte rendraient « l'une des deux », en silence, et ouvriraient l'établissement de
 * quelqu'un d'autre. MariaDB autorise autant de `NULL` qu'on veut dans un index unique : les
 * abonnements sans jeton — tous ceux d'avant, et tout le tunnel payant — ne se gênent pas.
 *
 * ⚠ **LE `DEFAULT NULL` PORTE UN SENS MÉTIER, ET IL EST DÉCLARÉ AU MAPPING.** `trialEndsAt` nul
 * signifie « cet abonnement n'est pas né d'un essai », ce que lit la facturation pour décider si sa
 * garde s'applique. Ce n'est pas une colonne vide en attente d'être remplie.
 *
 * ⚠ **SÛRE PENDANT LE DÉPLOIEMENT** : `deploy-preprod.sh` applique les migrations AVANT de redémarrer
 * FPM. Entre les deux, le schéma est neuf et le code est ancien. Trois colonnes nullables traversent
 * cette fenêtre sans rien casser — l'ancien code les ignore.
 */
final class Version20260904113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute l\'essai gratuit et la confirmation d\'adresse sur subscription_subscription (ED-5).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription_subscription ADD trial_ends_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE subscription_subscription ADD email_confirmation_token_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE subscription_subscription ADD email_confirmed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE UNIQUE INDEX uniq_subscription_email_confirmation ON subscription_subscription (email_confirmation_token_hash)');
    }

    public function down(Schema $schema): void
    {
        // @drop-voulu : les trois colonnes ajoutées par ce up(), et rien d'autre.
        //   Redescendre efface la trace des essais en cours — leur terme et la confirmation d'adresse.
        //   Ce n'est pas anodin : les abonnements concernés redeviendraient, pour la facturation, des
        //   abonnements payants ordinaires, donc facturables. Ne redescendre que si aucun essai n'a
        //   encore été ouvert, ce que dit `SELECT COUNT(*) ... WHERE trial_ends_at IS NOT NULL`.
        $this->addSql('DROP INDEX uniq_subscription_email_confirmation ON subscription_subscription');
        $this->addSql('ALTER TABLE subscription_subscription DROP trial_ends_at');
        $this->addSql('ALTER TABLE subscription_subscription DROP email_confirmation_token_hash');
        $this->addSql('ALTER TABLE subscription_subscription DROP email_confirmed_at');
    }
}
