<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La devise de facturation de l'établissement, en ISO 4217.
 *
 * ⚠ ELLE NE SE DÉDUIT PAS DU PAYS, ET C'EST POURQUOI ELLE A SON PROPRE CHAMP.
 *
 * Une table pays → devise paraîtrait économique. Elle serait à maintenir, fausse pour les pays où
 * plusieurs devises ont cours, et muette sur le cas d'un exploitant français qui facture en francs
 * suisses une clientèle frontalière. Deux questions distinctes, deux champs.
 *
 * ⚠ ET ELLE NE REND PAS LE PRODUIT MULTIDEVISE.
 *
 * Les montants restent calculés sans conversion : ce champ dit dans quelle unité l'établissement
 * **compte**, il ne convertit rien. Poser `CHF` sur un établissement dont les tarifs sont saisis en
 * euros produirait des factures fausses — c'est un réglage de mise en service, pas un bouton
 * d'exploitation. Le dire ici évite qu'on le découvre par une facture erronée.
 *
 * Le défaut `EUR` explicite ce qui était déjà implicite dans tout le dépôt (D66-ter).
 */
final class Version20260902060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la devise de facturation de l etablissement (ISO 4217), defaut EUR.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE org_etablissement ADD devise VARCHAR(3) DEFAULT 'EUR' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE org_etablissement DROP devise');
    }
}
