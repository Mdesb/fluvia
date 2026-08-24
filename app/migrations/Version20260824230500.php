<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CQ-3 + CQ-6 : la réservation retient **quelle carte** elle a débitée (`credit_droit_ref`).
 *
 * Sans ce champ, aucune restitution n'est possible : le décompte a lieu à la réservation, donc une
 * annulation doit rendre l'unité — et rien d'autre ne dit sur quelle carte la rendre, un porteur
 * pouvant en avoir plusieurs. Retrouver « celle qui a servi » par déduction serait une devinette.
 *
 * ⚠ Migration écrite à la main (D32), horodatée en **heure locale** (23:05) et non en UTC.
 * Un seul `ALTER TABLE`, une colonne ajoutée, nullable — aucune reprise de données à faire :
 * `null` est la valeur juste pour toute réservation antérieure, aucune n'ayant jamais débité de
 * carte. Aucun index, aucune contrainte, aucun `DROP`.
 *
 * Référence libre et non clé étrangère, comme `billet_support_ref`/`produit_ref`/`reservation_ref`
 * côté Accès : `Reservation` (M5) ne possède pas `DroitAcces`, et une contrainte inter-modules
 * figerait l'ordre de suppression des deux domaines.
 */
final class Version20260824230500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CQ-3 + CQ-6 : credit_droit_ref sur Reservation (carte débitée à la réservation).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation_reservation ADD credit_droit_ref BINARY(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation_reservation DROP credit_droit_ref');
    }
}
