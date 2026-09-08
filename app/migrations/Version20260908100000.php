<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `billing_installment_invoice` : le registre qui empêche d'émettre deux fois la facture d'une même
 * échéance.
 *
 * ⚠ C'EST `uniq_installment_invoice_origin` QUI GARANTIT, PAS LA LECTURE QUI LA PRÉCÈDE.
 * `InstallmentInvoicer` cherche d'abord une ligne existante, mais entre cette lecture et l'écriture un
 * second appel peut passer : l'ordonnanceur qui repasse, une relance manuelle après un doute, deux
 * exploitants qui cliquent. Sans contrainte en base, les deux émettraient — et un document scellé ne
 * s'annule pas, il s'avoire. Le perdant rattrape la violation et rend la facture du gagnant, patron
 * éprouvé par `subscription_invoice` sur la facturation SaaS.
 *
 * ⚠ `origin_reference` FAIT 64 CARACTÈRES, COMME SES DEUX HOMOLOGUES.
 * `sepa_ligne_remise.reference_origine` et `recouvrement_incident_impaye.reference_echeance_origine`
 * portent la même valeur avec la même largeur. Trois colonnes qui se comparent doivent avoir la même
 * taille : une troncature silencieuse ferait diverger ce qui devrait être égal, et le symptôme serait
 * une facture émise deux fois pour une échéance dont la référence est longue.
 *
 * ⚠ `invoice_id` EST NULLABLE, ET C'EST L'ORDRE DES OPÉRATIONS QUI L'EXIGE : la réservation est écrite
 * AVANT l'émission, donc elle existe un instant sans facture. Une colonne non nulle imposerait
 * d'émettre d'abord — c'est-à-dire de rouvrir la fenêtre qu'on ferme.
 *
 * Recopiée du DDL réellement produit par Doctrine (`SHOW CREATE TABLE` sur une base créée depuis le
 * mapping) : `FK_E3AA07E0FF631228` et `IDX_E3AA07E0FF631228` sont les noms qu'il calcule.
 */
final class Version20260908100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'billing_installment_invoice : une échéance ne peut donner qu\'une facture.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE billing_installment_invoice (
            id BINARY(16) NOT NULL,
            origin_reference VARCHAR(64) NOT NULL,
            invoice_id BINARY(16) DEFAULT NULL,
            issued_at DATETIME NOT NULL,
            total_cents INT NOT NULL DEFAULT 0,
            etablissement_id BINARY(16) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_installment_invoice_origin (origin_reference),
            KEY IDX_E3AA07E0FF631228 (etablissement_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->addSql('ALTER TABLE billing_installment_invoice ADD CONSTRAINT FK_E3AA07E0FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE billing_installment_invoice');
    }
}
