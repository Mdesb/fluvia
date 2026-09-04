<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LA CONFIRMATION D'UNE RÉSERVATION — R15 (a).
 *
 * ── LA DÉCISION ─────────────────────────────────────────────────────────────────────────────────
 *
 * Maxime : « on met un système où il faut une confirmation de la réservation, par exemple 24 heures
 * avant le début de la session, et lors de la confirmation il faut le paiement ; pour toutes les
 * réservations de moins de 24 heures, le paiement est demandé dès le départ ».
 *
 * Et, décisif sur la forme : « ces décisions sont des décisions **métier**, il faut laisser le choix
 * à l'exploitant ». Le délai et le comportement à l'expiration sont donc paramétrés, pas figés.
 *
 * ── ⚠ CETTE MIGRATION NE CHANGE LE COMPORTEMENT DE RIEN ─────────────────────────────────────────
 *
 * Les quatre colonnes sont NULLABLES, et `null` veut dire « aucune confirmation requise » — le
 * comportement d'aujourd'hui. Aucune `RegleAnnulation` ne déclare de délai, donc aucune réservation
 * n'entrera dans l'état « à confirmer » tant qu'un exploitant ne l'aura pas activé, avec la portée
 * qu'il choisit déjà pour l'annulation.
 *
 * On ne fait pas basculer dix modules sur une décision prise pour le padel.
 *
 * ── LES QUATRE COLONNES ─────────────────────────────────────────────────────────────────────────
 *
 *     reservation_regle_annulation.confirmation_delay_minutes   combien de temps avant le début
 *     reservation_regle_annulation.confirmation_expiry          release · keep · release_and_charge
 *     reservation_reservation.confirmation_due_at               l'échéance, FIGÉE à la réservation
 *     reservation_reservation.confirmed_at                      quand elle a été honorée
 *
 * ⚠ L'ÉCHÉANCE EST FIGÉE, PAS RECALCULÉE À LA LECTURE. Le délai de la règle peut changer après
 * coup ; recalculer déplacerait l'échéance de réservations déjà prises, sous les pieds de gens à
 * qui on a annoncé une date.
 *
 * ⚠ DEUX DATES ET PAS UN BOOLÉEN. « Confirmée à 14h02 » répond à une question que
 * « confirmée = vrai » ne répond pas : devant un créneau libéré, l'exploitant veut savoir si
 * quelqu'un avait confirmé juste avant l'échéance.
 *
 * ⚠ NOMS ANGLAIS (D5) : quatre colonnes AJOUTÉES, donc la règle du 19/08 s'applique — même
 * traitement que `storage_location` et `deadline_alerted_at`.
 *
 * ⚠ D66-ter : aucune donnée métier fabriquée. Les réservations existantes restent à `NULL`, ce qui
 * est la vérité — personne ne leur a jamais demandé de confirmation.
 *
 * ⚠ SÛRE PENDANT LE DÉPLOIEMENT : `deploy-preprod.sh` applique les migrations AVANT de redémarrer
 * FPM. Quatre colonnes nullables traversent cette fenêtre sans rien casser.
 */
final class Version20260904140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Confirmation des réservations (R15 a) : délai et comportement paramétrés, échéance figée.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation_regle_annulation ADD confirmation_delay_minutes INT DEFAULT NULL');
        $this->addSql('ALTER TABLE reservation_regle_annulation ADD confirmation_expiry VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE reservation_reservation ADD confirmation_due_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE reservation_reservation ADD confirmed_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // @drop-voulu : les quatre colonnes ajoutées par ce up(), et rien d'autre.
        //   Deux portent une saisie d'exploitant qui se ressaisit ; les deux autres portent un état
        //   de réservation qui n'existe que si la première est active. Aucune autre donnée n'en
        //   dépend, et le statut `a_confirmer` redevient inatteignable sans elles.
        $this->addSql('ALTER TABLE reservation_regle_annulation DROP confirmation_delay_minutes');
        $this->addSql('ALTER TABLE reservation_regle_annulation DROP confirmation_expiry');
        $this->addSql('ALTER TABLE reservation_reservation DROP confirmation_due_at');
        $this->addSql('ALTER TABLE reservation_reservation DROP confirmed_at');
    }
}
