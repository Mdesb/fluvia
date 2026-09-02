<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L'échéance SEPA peut désormais être ANNULÉE, et l'annulation porte son motif.
 *
 * ── CE QUE CE MOT MANQUANT COÛTAIT ─────────────────────────────────────────────────────────────
 *
 * L'échéancier connaissait « à venir », « prélevée », « rejetée » et « gelée ». Une échéance
 * abandonnée — adhérent résilié, échéancier refait, essai nettoyé — n'avait donc aucun état où
 * aller : elle restait « à venir » indéfiniment, en se présentant comme due.
 *
 * Elle ne cassait rien : la collecte l'écarte faute de préavis. Mais elle s'accumulait. Mesure du
 * 02/09 en préproduction : 38 échéances « à venir » dont la plus ancienne remontait à septembre
 * 2025 — onze mois d'échéances qui s'affichaient comme à venir.
 *
 * ⚠ ET `Gelee` NE POUVAIT PAS SERVIR. C'est le seul état qui ressemble, mais il veut dire « en
 * pause » (RG-SPORT-05) : il est posé quand un adhérent demande une suspension, et une reprise le
 * lève. L'employer aurait affiché « en pause » sur des échéances abandonnées, et une reprise
 * d'abonnement les aurait réveillées.
 *
 * ── POURQUOI LE MOTIF EST UNE COLONNE, ET PAS UN COMMENTAIRE ────────────────────────────────────
 *
 * Une échéance annulée est une somme que le club n'encaissera jamais. La seule question qu'on
 * posera six mois plus tard est « pourquoi ? », et un état sans motif y répond « on ne sait pas ».
 *
 * ── CE QUE CETTE MIGRATION NE FAIT PAS ──────────────────────────────────────────────────────────
 *
 * Elle n'annule aucune échéance (D66-ter : une migration ne fabrique pas de donnée métier). Elle
 * ouvre l'état ; les annulations passent par `POST /sport/echeances/{id}/annuler`, qui exige un
 * motif, contrôle le cloisonnement et refuse une échéance déjà prélevée ou rejetée.
 *
 * Les colonnes sont NULL : les échéances existantes n'ont pas été annulées, et une valeur par
 * défaut leur ferait porter une date d'annulation qu'aucun geste n'a produite.
 */
final class Version20260902210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Echeance SEPA : etat « annulee » possible, avec motif et date d annulation.';
    }

    public function up(Schema $schema): void
    {
        // ⚠ `statut` est un VARCHAR(10) porteur d'une enumeration PHP : « annulee » fait 7
        // caracteres et tient sans elargir la colonne. Aucune contrainte SQL ne liste les valeurs,
        // c'est `enumType` qui les tient cote Doctrine — il n'y a donc rien a modifier ici pour
        // l'etat lui-meme.
        $this->addSql('ALTER TABLE sport_echeance_sepa ADD cancellation_reason VARCHAR(200) DEFAULT NULL');
        $this->addSql('ALTER TABLE sport_echeance_sepa ADD cancelled_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // ⚠ REDESCENDRE PERD LE POURQUOI, PAS SEULEMENT LE COMMENT. Les echeances passees a
        // « annulee » gardent cet etat — la colonne `statut` n'est pas touchee ici — mais leur motif
        // disparait. On se retrouve avec des echeances annulees dont plus personne ne sait la
        // raison, ce qui est pire que l'etat d'avant.
        $this->addSql('ALTER TABLE sport_echeance_sepa DROP cancellation_reason');
        $this->addSql('ALTER TABLE sport_echeance_sepa DROP cancelled_at');
    }
}
