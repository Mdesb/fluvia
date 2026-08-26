<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * UI-5 : moyen de paiement préféré du client (`App\Crm\Entity\Client::$preferredPaymentMethodCode`),
 * renseigné par l'exploitant depuis le back-office. Référence **souple** par code au référentiel
 * `App\Compta\Entity\MoyenPaiement.code` — volontairement pas de FK cross-module (`App\Crm` ne couple
 * pas `App\Compta`, D2/D8). Colonne nullable, aucune reprise de données nécessaire.
 */
final class Version20260826090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "UI-5 : ajoute crm_client.preferred_payment_method_code (référence souple, sans FK, vers Compta\\MoyenPaiement.code).";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE crm_client ADD preferred_payment_method_code VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE crm_client DROP COLUMN preferred_payment_method_code');
    }
}
