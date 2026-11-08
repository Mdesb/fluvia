<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PAIEMENT EN LIGNE RÉEL — PAYFIP ET PSP CB (#339). Écrite à la main, horodatée en UTC.
 *
 * `compta_bordereau_payfip` : ce qu'il faut pour RAPPROCHER une opération PayFiP de la remise
 * quotidienne du régisseur (guide DGFiP Régie Web service V18.0, §4.3) — la référence de dette
 * envoyée, le montant et le mode initiés, puis le résultat, le numéro d'autorisation et la date
 * rendus par PayFiP. Index sur l'IdOp : le retour et la notification retrouvent l'opération par lui.
 *
 * `bou_suivi_commande` : le jeton de règlement (le retour de l'acheteur et la notification du
 * prestataire arrivent ensemble ; un seul encaisse), la date de dernière interrogation du
 * prestataire (frein de la route publique de notification), et l'index qui permet à la
 * notification de retrouver la commande par sa référence de transaction.
 *
 * Sans perte : des colonnes s'ajoutent, toutes nulles par défaut ; les bordereaux et commandes
 * existants restent tels quels. `down()` retire exactement ce que `up()` a posé.
 */
final class Version20261105120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Paiement en ligne réel (#339) : rapprochement PayFiP, jeton de règlement et index de notification.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compta_bordereau_payfip ADD debt_reference VARCHAR(30) DEFAULT NULL, ADD amount_cents INT DEFAULT NULL, ADD entry_mode VARCHAR(1) DEFAULT NULL, ADD transaction_result VARCHAR(1) DEFAULT NULL, ADD authorization_number VARCHAR(16) DEFAULT NULL, ADD transacted_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_bordereau_payfip_reference ON compta_bordereau_payfip (reference_transaction)');
        $this->addSql('ALTER TABLE bou_suivi_commande ADD payment_settled_at DATETIME DEFAULT NULL, ADD payment_checked_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_suivi_commande_payment_reference ON bou_suivi_commande (payment_reference)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_suivi_commande_payment_reference ON bou_suivi_commande');
        $this->addSql('ALTER TABLE bou_suivi_commande DROP payment_settled_at, DROP payment_checked_at');
        $this->addSql('DROP INDEX idx_bordereau_payfip_reference ON compta_bordereau_payfip');
        $this->addSql('ALTER TABLE compta_bordereau_payfip DROP debt_reference, DROP amount_cents, DROP entry_mode, DROP transaction_result, DROP authorization_number, DROP transacted_at');
    }
}
