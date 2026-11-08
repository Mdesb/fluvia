<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LOGO DE L'ENTITÉ QUI FACTURE (#345) — écrite à la main, horodatée en UTC.
 *
 * `compta_profil_exploitant.logo_document_id` : référence du document DMS imprimé en tête de la
 * facture PDF. BINARY(16), comme toutes les colonnes UUID du dépôt. Sans clé étrangère : le DMS
 * versionne ses documents, et `Vitrine::$logo` le référence de la même façon, sans contrainte.
 *
 * Sans perte : une colonne nullable s'ajoute ; sans logo, la facture garde l'en-tête textuel.
 */
final class Version20261105130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Profil exploitant : logo imprimé en tête de la facture PDF.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compta_profil_exploitant ADD logo_document_id BINARY(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compta_profil_exploitant DROP logo_document_id');
    }
}
