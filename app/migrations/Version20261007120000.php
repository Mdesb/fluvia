<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lever une absence (07/10/2026) : le motif sur la réservation, et la permission `reservation.lever_absence`.
 *
 * Le statut `terminee_sans_constat` (21 caractères) tient dans `reservation_reservation.statut`
 * (VARCHAR(24), sans contrainte) : aucune colonne de statut à changer.
 *
 * La permission est donnée aux rôles qui portent `reservation.exonerer` — on ne lève une absence qu'une
 * fois sa facturation exonérée, c'est le même métier. Rôles LUS en base plutôt que nommés : relevé en
 * préprod le 07/10, seul « Agent d'accueil réservation » la porte explicitement ; les rôles à joker
 * (`reservation.*`, `*.*`) l'ont par le joker. Par migration et non seulement par fixture : c'est le
 * seul chemin qui atteint la base d'un client (voir `Version20261004010316`). Idempotente.
 */
final class Version20261007120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lever une absence : motif sur la réservation, permission reservation.lever_absence aux rôles qui exonèrent';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation_reservation ADD absence_lift_reason VARCHAR(255) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            INSERT INTO sec_permission (id, module, action)
            SELECT UNHEX(REPLACE(UUID(), '-', '')), :module, :action FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM sec_permission WHERE module = :module AND action = :action)
            SQL, ['module' => 'reservation', 'action' => 'lever_absence']);
        $this->addSql(<<<'SQL'
            INSERT INTO sec_role_permission (role_id, permission_id)
            SELECT rp.role_id, levee.id
              FROM sec_role_permission rp
              JOIN sec_permission exo ON exo.id = rp.permission_id AND exo.module = :module AND exo.action = :exonerer
              JOIN sec_permission levee ON levee.module = :module AND levee.action = :action
             WHERE NOT EXISTS (SELECT 1 FROM sec_role_permission x WHERE x.role_id = rp.role_id AND x.permission_id = levee.id)
            SQL, ['module' => 'reservation', 'exonerer' => 'exonerer', 'action' => 'lever_absence']);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DELETE rp FROM sec_role_permission rp
              JOIN sec_permission p ON p.id = rp.permission_id
             WHERE p.module = :module AND p.action = :action
            SQL, ['module' => 'reservation', 'action' => 'lever_absence']);
        $this->addSql('DELETE FROM sec_permission WHERE module = :module AND action = :action', ['module' => 'reservation', 'action' => 'lever_absence']);
        // Le code d'avant ne connaît pas `terminee_sans_constat` et ne saurait plus lire ces réservations.
        $this->addSql('UPDATE reservation_reservation SET statut = :absence WHERE statut = :levee', ['absence' => 'no_show_facture', 'levee' => 'terminee_sans_constat']);
        $this->addSql('ALTER TABLE reservation_reservation DROP absence_lift_reason');
    }
}
