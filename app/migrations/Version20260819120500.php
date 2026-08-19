<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Durcissement revue sécurité module API Terminal (double-enrôlement non révocable) : contrainte
 * d'unicité `(itbox_ref, etablissement_id)` sur `acces_terminal`. Sans elle, rien n'empêchait deux
 * `Terminal` actifs sur le même matériel (`itboxRef`) au sein d'un même établissement — deux
 * `JetonTerminal` valides simultanément pour le même matériel, révoquer l'un ne coupant pas l'autre.
 * Doublée d'un contrôle applicatif (409) dans `App\Acces\State\EnrolerTerminalProcessor`. Réversible.
 */
final class Version20260819120500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Durcissement acces-terminal : contrainte unique (itbox_ref, etablissement_id) sur acces_terminal (anti double-enrôlement).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_terminal_itbox_etablissement ON acces_terminal (itbox_ref, etablissement_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_terminal_itbox_etablissement ON acces_terminal');
    }
}
