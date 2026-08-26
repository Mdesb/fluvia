<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Un établissement déclare son fuseau horaire.
 *
 * **Pourquoi.** La clôture journalière NF525 arrête une journée et la **scelle**. Planifiée à 3 h de
 * Paris, elle tombe à **21 h la veille aux Antilles** : elle arrêterait une journée en cours, avec des
 * ventes encore à venir dessus. Ces ventes basculeraient dans la journée suivante, et le refus « journée
 * sautée » ne les rattraperait pas — puisque la journée aurait bien été close.
 *
 * Sur un établissement à l'ouest de Paris, l'automatisme produirait donc **exactement le défaut que la
 * clôture existe pour empêcher**, et le produirait scellé.
 *
 * **Défaut `Europe/Paris`** : c'est le cas de tous les établissements existants. Une valeur fausse pour
 * personne aujourd'hui vaut mieux qu'une colonne nulle que chaque appelant interprète à sa façon.
 *
 * DDL relevé par `SHOW CREATE TABLE` sur la table que Doctrine construit depuis le mapping (D32) —
 * pas par `migrations:diff`, qui ratisserait la dérive des huit autres sessions.
 */
final class Version20260826180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Un établissement déclare son fuseau horaire (clôture journalière NF525).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE org_etablissement
             ADD fuseau_horaire VARCHAR(64) DEFAULT 'Europe/Paris' NOT NULL"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE org_etablissement DROP fuseau_horaire');
    }
}
