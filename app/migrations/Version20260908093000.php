<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `facturation_reglement` : l'écriture d'encaissement produite par le règlement, et son code de
 * rapprochement.
 *
 * ⚠ CE N'EST PAS DE LA TRAÇABILITÉ DE CONFORT — SANS CE LIEN, LE LETTRAGE EST IMPOSSIBLE.
 * `LettrageHandler::lettrerGroupe()` exige Σdébit = Σcrédit sur les lignes qu'on lui passe. Une
 * facture réglée en deux fois produit deux écritures d'encaissement : au moment de solder, il faut
 * rassembler la ligne 411 débitrice de la facture ET les lignes 411 créditrices de chaque règlement.
 * Sans moyen de les retrouver, la somme ne tombe pas et le lettrage lève. Le chemin fournisseur porte
 * déjà exactement ce champ (`SupplierPayment::$ledgerEntry`), pour la même raison, découverte de la
 * même manière.
 *
 * ⚠ NULLABLE, ET DÉFINITIVEMENT POUR LES LIGNES EXISTANTES. Les règlements enregistrés avant ce lot
 * n'ont produit aucune écriture, et ne peuvent pas en produire après coup : leur facture est déjà
 * `payee`, et `ReglementFactureHandler` refuse tout règlement sur une facture qui n'est pas en attente
 * de paiement ou partiellement réglée. Elles resteront donc à `NULL`, sans que ce soit un défaut à
 * rattraper — c'est l'état d'avant, et il est lisible comme tel.
 *
 * Recopiée du DDL réellement produit par Doctrine (`SHOW CREATE TABLE` sur une base créée depuis le
 * mapping). Les noms `FK_C043BCE28C8E9F2A` et `IDX_C043BCE28C8E9F2A` sont ceux qu'il calcule.
 */
final class Version20260908093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'facturation_reglement : écriture d\'encaissement liée et code de rapprochement.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE facturation_reglement ADD reconciliation_code VARCHAR(36) DEFAULT NULL, ADD ecriture_encaissement_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE facturation_reglement ADD CONSTRAINT FK_C043BCE28C8E9F2A FOREIGN KEY (ecriture_encaissement_id) REFERENCES compta_ecriture_comptable (id)');
        $this->addSql('CREATE INDEX IDX_C043BCE28C8E9F2A ON facturation_reglement (ecriture_encaissement_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE facturation_reglement DROP FOREIGN KEY FK_C043BCE28C8E9F2A');
        $this->addSql('DROP INDEX IDX_C043BCE28C8E9F2A ON facturation_reglement');
        $this->addSql('ALTER TABLE facturation_reglement DROP reconciliation_code, DROP ecriture_encaissement_id');
    }
}
