<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le taux de TVA applicable aux abonnements, en paramètre d'établissement.
 *
 * **Nullable sans valeur par défaut, à dessein.** Un exploitant français porte couramment quatre taux
 * actifs ; en poser un d'office reviendrait à choisir à sa place, une fois sur quatre au hasard.
 * `null` veut dire « non décidé » — pas « exonéré », qui se dit par un taux à zéro.
 *
 * **Ce que cette colonne débloque** : tant que le taux devait être choisi à chaque émission, la
 * facturation mensuelle ne pouvait pas être automatisée — une tâche périodique qui exige un arbitrage
 * humain n'en est pas une (constat du 25/08 sur `personnel:traiter-echeances-sortie`).
 *
 * `ON DELETE RESTRICT` : on ne supprime pas un taux encore désigné par un paramétrage. Un `SET NULL`
 * ferait basculer silencieusement l'établissement en « non décidé », et la facturation suivante
 * échouerait sans que personne ne fasse le lien avec la suppression.
 *
 * Écrite à la main, horodatée en heure locale (D32).
 */
final class Version20260825163000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Taux de TVA des abonnements en paramètre d'établissement — vide par défaut (D45-bis).";
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE facturation_parametre '
            . 'ADD taux_tva_abonnement_id BINARY(16) DEFAULT NULL'
        );

        $this->addSql(
            'ALTER TABLE facturation_parametre '
            . 'ADD CONSTRAINT fk_param_facturation_taux_tva_abonnement '
            . 'FOREIGN KEY (taux_tva_abonnement_id) REFERENCES compta_taux_tva (id) ON DELETE RESTRICT'
        );

        $this->addSql(
            'CREATE INDEX idx_param_facturation_taux_tva_abonnement '
            . 'ON facturation_parametre (taux_tva_abonnement_id)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE facturation_parametre '
            . 'DROP FOREIGN KEY fk_param_facturation_taux_tva_abonnement'
        );

        $this->addSql(
            'DROP INDEX idx_param_facturation_taux_tva_abonnement '
            . 'ON facturation_parametre'
        );

        $this->addSql(
            'ALTER TABLE facturation_parametre DROP taux_tva_abonnement_id'
        );
    }
}
