<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le pays de l etablissement — l ancre du catalogue de TVA, des mentions et du profil de facturation.
 *
 * T8 annoncait « aucun champ pays ». Mesure du 02/09 : LegalVatRate porte DEJA sa colonne country,
 * avec ses dates de validite et sa source, et VatRateCatalogProvider sert deja les taux par pays. Ce
 * qui manquait n etait pas le catalogue — c etait ce qui DESIGNE le pays d un etablissement.
 *
 * Sans lui, le fournisseur retombait sur une constante PAYS_PAR_DEFAUT = FR : un etablissement
 * espagnol se serait vu proposer les taux francais, et rien ne l aurait signale.
 *
 * ⚠ LE DEFAUT FR EXPLICITE CE QUI EST DEJA VRAI (D66-ter). Le produit n est commercialise qu en
 * France a ce jour, et le seul jeu de taux legaux amorce est le francais — quatre entrees, toutes
 * FR. Ce n est pas une supposition sur l avenir, c est la description du present.
 *
 * ⚠ A NE PAS CONFONDRE AVEC , qui repond a une autre question : un etablissement
 * francais aux Antilles est en FR et en America/Guadeloupe.
 */
final class Version20260902050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le pays de l etablissement (ISO 3166-1 alpha-2), defaut FR.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE org_etablissement ADD pays VARCHAR(2) DEFAULT 'FR' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE org_etablissement DROP pays');
    }
}
