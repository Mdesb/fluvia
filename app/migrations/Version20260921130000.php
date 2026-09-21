<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Colonne `support_identifiant` sur `sport_statut_acces_fitness` : le code SIGNÉ du support QR (le
 * billet d'accès) émis à la souscription. Dénormalisé depuis `acces_support.identifiant` pour
 * afficher/réimprimer le billet depuis la fiche abonnement sans traverser la chaîne
 * Droit→Appairage→Support. Nullable : les abonnements antérieurs à ce lot n'ont pas de support
 * (leur droit d'accès n'a jamais été rattaché) ; ils restent lisibles, la colonne y vaut NULL.
 */
final class Version20260921130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Colonne support_identifiant (code du billet QR) sur sport_statut_acces_fitness.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sport_statut_acces_fitness ADD support_identifiant VARCHAR(128) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sport_statut_acces_fitness DROP support_identifiant');
    }
}
