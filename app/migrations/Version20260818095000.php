<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Module Facturation — correctif revue de cohérence (défaut 1, BLOQUANT) : `facture_corrigee_id`
 * (table `facturation_facture`, créée par `Version20260818080740`, déjà appliquée en préprod — non
 * modifiable) ne portait qu'un simple INDEX, pas de contrainte d'unicité. Un second appel de
 * `POST /factures/{id}/avoir` pouvait donc créer un second avoir sur la même facture corrigée (double
 * crédit 411 / double extourne). Remplace l'INDEX par une UNIQUE INDEX — garde-fou base en complément
 * de la vérification applicative (`AvoirFactureHandler::genererAvoir`, `findOneBy(['factureCorrigee'
 * => ...])` + récupération gracieuse sur `UniqueConstraintViolationException`).
 *
 * `NULL` autorisé plusieurs fois par MySQL/MariaDB sur un INDEX UNIQUE : n'affecte aucune facture
 * normale (seuls les avoirs renseignent `factureCorrigee`). Champ isolé, ne touche aucune autre table.
 */
final class Version20260818095000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Facturation : UNIQUE INDEX sur facturation_facture.facture_corrigee_id (idempotence des avoirs, RG-FACT-05/CA-6).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE facturation_facture ADD UNIQUE INDEX uniq_facturation_facture_corrigee (facture_corrigee_id)');
        $this->addSql('ALTER TABLE facturation_facture DROP INDEX IDX_B52288087A0F6D9A');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE facturation_facture ADD INDEX IDX_B52288087A0F6D9A (facture_corrigee_id)');
        $this->addSql('ALTER TABLE facturation_facture DROP INDEX uniq_facturation_facture_corrigee');
    }
}
