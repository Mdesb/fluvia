<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * THE ISSUER FROZEN AT ISSUANCE (#345) — written by hand, timestamped in UTC.
 *
 * `facturation_facture.emetteur_fige`: who issued the invoice (name, SIREN/SIRET, VAT number,
 * address, logo document + version, cash office), captured once by `ScellementFactureHandler`.
 * A reprint then shows the original issuer even after the operator profile has changed.
 *
 * Lossless: a nullable column is added. Invoices issued before have no snapshot and keep reading the
 * live profile — their original issuer cannot be reconstructed, so none is invented.
 */
final class Version20261105131000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Facture : émetteur figé à l’émission (réimpression fidèle).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE facturation_facture ADD emetteur_fige JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE facturation_facture DROP emetteur_fige');
    }
}
