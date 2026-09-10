<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `recouvrement_incident_impaye` : par quel moyen l'impayé a été réglé, sous quelle référence, et
 * quelle facture il vient solder.
 *
 * ⚠ CES TROIS COLONNES N'EXISTAIENT PAS, ET C'EST POURQUOI L'ÉCRAN NE POUVAIT RIEN DIRE.
 * `POST /recouvrement/incidents/{id}/resoudre` était déclarée `input: false` : aucun corps n'était
 * accepté, `canal_resolution` était posé en dur à `app_1_clic`, et la colonne « Par quel canal » du
 * tableau des régularisés affichait éternellement la même valeur. On ne savait ni comment, ni quand,
 * ni contre quelle pièce l'argent était rentré.
 *
 * ⚠ UN CODE ET UNE RÉFÉRENCE NUE, PAS DES RELATIONS — ET C'EST D2, PAS UN RACCOURCI.
 * `App\Recouvrement` ne doit connaître ni `App\Compta` ni `App\Facturation` : la communication passe
 * par événements, jamais par appel direct de module à module. Le moyen est donc stocké **en clair**
 * (la convention que `ReferentielReglementInterface` énonce déjà pour le paiement), et la facture est
 * une colonne `uuid` nue — la convention du dépôt pour franchir une frontière. C'est un abonné de
 * `Facturation` qui résout la pièce, sur l'événement de résolution.
 *
 * ⚠ TOUTES NULLABLES, ET DÉFINITIVEMENT POUR L'EXISTANT. Les incidents résolus avant ce lot n'ont
 * rien de tout ça et ne peuvent pas l'acquérir après coup : `resoudre()` refuse un incident déjà
 * résolu. Leur vide est l'état d'avant, lisible comme tel — pas un défaut à rattraper.
 *
 * ⚠ ET `facture_origine_ref` RESTERA VIDE POUR LES DOSSIERS OUVERTS AVANT LA FACTURATION DES
 * ÉCHÉANCES — l'unique incident de la préproduction est dans ce cas. Les régler enregistrera le
 * règlement et écrira l'encaissement, mais ne créera aucun `ReglementFacture`, faute de pièce.
 * L'écran doit le DIRE, pas le masquer.
 *
 * Recopiée du DDL réellement produit par Doctrine (`SHOW CREATE TABLE` sur une base créée depuis le
 * mapping).
 */
final class Version20260908110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'recouvrement_incident_impaye : moyen, référence et pièce d\'origine de la résolution.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recouvrement_incident_impaye
            ADD moyen_resolution VARCHAR(32) DEFAULT NULL,
            ADD reference_resolution VARCHAR(64) DEFAULT NULL,
            ADD facture_origine_ref BINARY(16) DEFAULT NULL,
            ADD resolu_par_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE recouvrement_incident_impaye ADD CONSTRAINT FK_A6BD1F55E3DAC49 FOREIGN KEY (resolu_par_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('CREATE INDEX IDX_A6BD1F55E3DAC49 ON recouvrement_incident_impaye (resolu_par_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recouvrement_incident_impaye DROP FOREIGN KEY FK_A6BD1F55E3DAC49');
        $this->addSql('DROP INDEX IDX_A6BD1F55E3DAC49 ON recouvrement_incident_impaye');
        $this->addSql('ALTER TABLE recouvrement_incident_impaye
            DROP moyen_resolution,
            DROP reference_resolution,
            DROP facture_origine_ref,
            DROP resolu_par_id');
    }
}
