<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LA LANGUE — une par établissement, et une préférence facultative par utilisateur.
 *
 * Décision de Maxime du 08/10 : l'Espagne se construit en entier, et la langue vient en premier.
 * Rien ne disait jusqu'ici dans quelle langue travaille un établissement.
 *
 * `org_etablissement.locale` est NON NUL, défaut `fr` : c'est ce que parlent tous les établissements
 * existants (D66-ter — la migration nomme ce qui était implicite, elle n'invente rien).
 *
 * `sec_utilisateur.locale` est NULLABLE, et `NULL` est la valeur courante : la personne suit la langue
 * de l'établissement sur lequel elle travaille. Une valeur ne s'écrit que si quelqu'un la choisit.
 *
 * Écrite à la main ; SQL vérifié contre `doctrine:schema:update --dump-sql` sur la pile de test.
 */
final class Version20261008141500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Langue de l etablissement (defaut fr) et langue preferee de l utilisateur (facultative).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE org_etablissement ADD locale VARCHAR(5) DEFAULT 'fr' NOT NULL");
        $this->addSql('ALTER TABLE sec_utilisateur ADD locale VARCHAR(5) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE org_etablissement DROP locale');
        $this->addSql('ALTER TABLE sec_utilisateur DROP locale');
    }
}
