<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `bou_suivi_commande` : ce qui a été initié chez le prestataire de paiement — audit du 06/09, constat 1.
 *
 * Le retour de paiement acceptait un statut lu dans le corps de la requête. Il se confronte désormais à
 * la référence et au montant mémorisés à l'initiation : une référence inconnue ou un montant différent
 * ne confirment rien. Nullable : les commandes antérieures n'ont rien mémorisé, et un retour sur elles
 * répond 409 (« repassez par payer »), jamais 200.
 *
 * Écrite d'après `doctrine:schema:update --dump-sql` sur le mapping neuf.
 */
final class Version20260906150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'bou_suivi_commande : référence et montant mémorisés à l\'initiation du paiement.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bou_suivi_commande ADD payment_reference VARCHAR(64) DEFAULT NULL, ADD payment_amount_cents INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bou_suivi_commande DROP payment_reference, DROP payment_amount_cents');
    }
}
