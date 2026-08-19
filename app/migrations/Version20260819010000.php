<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Clôture de caisse (Z) à rôle gradué (`App\Caisse`, plan-caisse-cloture-role.md §5) — migration
 * retaillée à la main (contexte multi-agents, base partagée : `doctrine:migrations:diff` incluait de
 * nombreuses tables d'autres modules en cours de développement parallèle, non prêtes) pour ne porter
 * QUE le schéma/les données de ce lot : colonne `caisse_point_de_vente.tolerance_ecart_caisse`
 * (RG-CAISSEZ-08), table `caisse_alerte_ecart` (RG-CAISSEZ-05/06/07), permissions
 * `caisse.{voir_z,voir_ecart}` (RG-CAISSEZ-03/06). Suppose les migrations socle (L0) et L2
 * (`caisse_point_de_vente`/`caisse_cloture_z`/`caisse_session`, `sec_permission`) déjà jouées.
 */
final class Version20260819010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Caisse — clôture Z à rôle gradué : caisse_point_de_vente.tolerance_ecart_caisse, '
            . 'table caisse_alerte_ecart, permissions caisse.{voir_z,voir_ecart}.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE caisse_point_de_vente ADD tolerance_ecart_caisse NUMERIC(10, 2) DEFAULT '0.00' NOT NULL");

        // Nom de l'index unique = auto-généré Doctrine (précédent : caisse_cloture_z.session_id →
        // UNIQ_24069427613FECDF, migration Version20260814175518) — évite un écart `doctrine:schema:validate`.
        $this->addSql('CREATE TABLE caisse_alerte_ecart (id BINARY(16) NOT NULL, ecart_montant NUMERIC(10, 2) NOT NULL, tolerance_appliquee NUMERIC(10, 2) NOT NULL, horodatage DATETIME NOT NULL, cloture_id BINARY(16) NOT NULL, session_id BINARY(16) NOT NULL, etablissement_id BINARY(16) NOT NULL, auteur_cloture_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_B8C825C53FE4D754 (cloture_id), INDEX idx_alerte_session (session_id), INDEX idx_alerte_etablissement (etablissement_id), INDEX idx_alerte_auteur (auteur_cloture_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE caisse_alerte_ecart ADD CONSTRAINT FK_ALERTE_ECART_CLOTURE FOREIGN KEY (cloture_id) REFERENCES caisse_cloture_z (id)');
        $this->addSql('ALTER TABLE caisse_alerte_ecart ADD CONSTRAINT FK_ALERTE_ECART_SESSION FOREIGN KEY (session_id) REFERENCES caisse_session (id)');
        $this->addSql('ALTER TABLE caisse_alerte_ecart ADD CONSTRAINT FK_ALERTE_ECART_ETABLISSEMENT FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
        $this->addSql('ALTER TABLE caisse_alerte_ecart ADD CONSTRAINT FK_ALERTE_ECART_AUTEUR FOREIGN KEY (auteur_cloture_id) REFERENCES sec_utilisateur (id)');

        // Permissions (idempotent, même patron que Version20260818120000 Autorisation / Version20260817173400 Stock).
        foreach (['voir_z', 'voir_ecart'] as $action) {
            $this->addSql(
                'INSERT IGNORE INTO sec_permission (id, module, action) VALUES (?, ?, ?)',
                [Uuid::v4()->toBinary(), 'caisse', $action],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM sec_permission WHERE module = 'caisse' AND action IN ('voir_z', 'voir_ecart')");

        $this->addSql('ALTER TABLE caisse_alerte_ecart DROP FOREIGN KEY FK_ALERTE_ECART_CLOTURE');
        $this->addSql('ALTER TABLE caisse_alerte_ecart DROP FOREIGN KEY FK_ALERTE_ECART_SESSION');
        $this->addSql('ALTER TABLE caisse_alerte_ecart DROP FOREIGN KEY FK_ALERTE_ECART_ETABLISSEMENT');
        $this->addSql('ALTER TABLE caisse_alerte_ecart DROP FOREIGN KEY FK_ALERTE_ECART_AUTEUR');

        $this->addSql('DROP TABLE caisse_alerte_ecart');

        $this->addSql('ALTER TABLE caisse_point_de_vente DROP tolerance_ecart_caisse');
    }
}
