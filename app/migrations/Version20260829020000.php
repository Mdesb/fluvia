<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les espaces qu'un droit d'accès ouvre — la question qu'aucune étape ne posait.
 *
 * `ValidationPassageHandler` prononçait onze motifs de refus : support bloqué, appairage, droit
 * dévalidé, fédération, sens, heures d'ouverture, marges, anti-passback, crédit, jauge, signature.
 * **Aucun ne demandait si ce droit ouvre CETTE zone.** L'espace était bien résolu depuis
 * l'équipement, mais il ne servait qu'à la jauge et à l'enregistrement du passage.
 *
 * Conséquence : un billet de piscine ouvrait la porte de la salle de sport du même établissement.
 * Sur un site multi-activités, c'est le cœur du contrôle d'accès qui manquait.
 *
 * ⚠ TABLE VIDE À LA CRÉATION, ET C'EST VOLONTAIRE. `DroitAcces::ouvre()` traite l'absence d'espace
 * comme « ouvre tout ». Les droits déjà projetés n'auront donc aucune ligne ici et continueront de
 * passer partout — exactement comme avant cette migration.
 *
 * L'inverse aurait été catastrophique : « aucun espace = aucune ouverture » refuserait tous les
 * porteurs à la seconde où la migration passe, sur un mécanisme dont ils ignorent le changement. Le
 * sens sûr de l'erreur est d'ordinaire celui qui restreint ; pas quand restreindre ferme une porte
 * devant quelqu'un qui a payé. La restriction n'existe que si quelqu'un l'a demandée.
 *
 * DDL relevé sur le mapping (D32), écrit à la main, horodaté en heure locale.
 */
final class Version20260829020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Droit d’accès : les espaces qu’il ouvre (vide = tous).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE acces_droit_espace_autorise (
                droit_acces_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                espace_acces_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                INDEX IDX_droit_espace_droit (droit_acces_id),
                INDEX IDX_droit_espace_espace (espace_acces_id),
                PRIMARY KEY(droit_acces_id, espace_acces_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        // `ON DELETE CASCADE` des deux côtés : une ligne de cette table n'est pas une donnée, c'est
        // un lien. Retirer un espace ou un droit doit emporter le lien, jamais retenir l'un pour
        // l'autre — un `RESTRICT` ici empêcherait de supprimer un espace au motif qu'un vieux droit
        // le mentionne.
        $this->addSql('ALTER TABLE acces_droit_espace_autorise ADD CONSTRAINT FK_droit_espace_droit FOREIGN KEY (droit_acces_id) REFERENCES acces_droit_acces (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE acces_droit_espace_autorise ADD CONSTRAINT FK_droit_espace_espace FOREIGN KEY (espace_acces_id) REFERENCES acces_espace_acces (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // Redescendre supprime les restrictions de zone : tous les droits redeviennent universels.
        // Ce n'est pas une perte de données métier — c'est le retour au comportement d'avant.
        $this->addSql('DROP TABLE acces_droit_espace_autorise');
    }
}
