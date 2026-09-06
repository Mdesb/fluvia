<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * UNE ÉCHÉANCE À VENIR PEUT ÊTRE RÉDUITE — parrainage, geste commercial, offre au renouvellement.
 *
 * Quatre colonnes sur `sport_echeance_sepa`, et rien d'autre. Écrite à la main : `migrations:diff`
 * ratisserait la dérive des huit autres sessions ouvertes sur ce dépôt.
 *
 * ── POURQUOI `montant_initial_centimes` PLUTÔT QU'UNE TABLE DE RÉDUCTIONS ──────────────────────
 *
 * `montant_centimes` reste LE montant à prélever : la réduction le diminue en place et l'original
 * part dans `montant_initial_centimes`. L'inverse — garder la base et soustraire chez les lecteurs —
 * obligerait à modifier chaque endroit qui lit une échéance (`SportEcheanceSepaSource`, le préavis,
 * la génération de remise, l'écran, la comptabilité), et il suffirait d'en oublier UN pour prélever
 * le plein tarif après avoir annoncé le réduit.
 *
 * ── LA TRACE EN TROIS CHAMPS PLATS, COMME L'ANNULATION JUSTE À CÔTÉ ────────────────────────────
 *
 * `cancellation_reason` / `cancelled_at` existent déjà sur cette table pour la même raison : une
 * intervention manuelle sur un prélèvement doit dire qui, quand et pourquoi. On ajoute l'auteur, que
 * l'annulation n'a pas : un rabais est une décision, elle a quelqu'un derrière.
 *
 * `ON DELETE SET NULL` sur l'auteur — supprimer un compte ne doit pas effacer une échéance ni
 * bloquer sa suppression. Le motif et la date, eux, survivent : c'est ce qu'on relit six mois plus
 * tard devant un relevé.
 */
final class Version20260906100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'sport_echeance_sepa : réduction d\'une échéance à venir (montant initial, motif, date, auteur).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE sport_echeance_sepa
                ADD montant_initial_centimes INT DEFAULT NULL,
                ADD reduction_motif VARCHAR(200) DEFAULT NULL,
                ADD reduction_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                ADD reduction_par_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)'
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE sport_echeance_sepa
                ADD CONSTRAINT fk_sport_echeance_reduction_par
                FOREIGN KEY (reduction_par_id) REFERENCES sec_utilisateur (id) ON DELETE SET NULL
        SQL);

        $this->addSql('CREATE INDEX idx_sport_echeance_reduction_par ON sport_echeance_sepa (reduction_par_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sport_echeance_sepa DROP FOREIGN KEY fk_sport_echeance_reduction_par');
        $this->addSql('DROP INDEX idx_sport_echeance_reduction_par ON sport_echeance_sepa');
        $this->addSql(<<<'SQL'
            ALTER TABLE sport_echeance_sepa
                DROP montant_initial_centimes,
                DROP reduction_motif,
                DROP reduction_at,
                DROP reduction_par_id
        SQL);
    }
}
