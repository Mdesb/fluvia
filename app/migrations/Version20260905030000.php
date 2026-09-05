<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ARRHES ET ACOMPTE — deux régimes, un choix par prestation.
 *
 * ── L'ARBITRAGE ─────────────────────────────────────────────────────────────────────────────────
 *
 * J'avais proposé « les arrhes » en décrivant, en réalité, le comportement d'un ACOMPTE : déduire
 * le versement du montant facturé après une absence. Maxime a relevé que les deux ne sont pas la
 * même chose, et a tranché : **les deux, au choix de la prestation**.
 *
 *   ACOMPTE — avance sur le prix, engagement ferme. Le client absent reste devoir le solde ; le
 *             versement s'impute. Une `FacturationNoShow` est émise POUR LE SOLDE.
 *
 *   ARRHES  — faculté de dédit (art. 1590). Le client absent PERD son versement, et on ne lui
 *             réclame rien de plus. AUCUNE `FacturationNoShow` n'est émise.
 *
 * ⚠ **C'EST CE QUI EMPÊCHE DE FACTURER DEUX FOIS LE MÊME MANQUEMENT.** `RegleAnnulation` calcule
 * déjà une indemnité de dédit. Sur une prestation à arrhes, laisser les deux mécanismes s'appliquer
 * ferait payer l'absence deux fois au client — une fois en perdant ses arrhes, une fois par la
 * facturation. Rien ne l'aurait signalé avant une réclamation.
 *
 * ── ⚠ LE MONTANT RETENU EST UN FAIT, PAS UNE DÉCLARATION ────────────────────────────────────────
 *
 * `versement_retenu_montant` porte ce qui a **réellement** été encaissé, et c'est LUI qu'on déduit —
 * jamais le montant déclaré sur la prestation. Déduire un montant déclaré mais jamais encaissé
 * ferait perdre de l'argent à chaque absence, en silence : deux défauts qui s'annulent, jusqu'au
 * jour où on compte.
 *
 * Corollaire : un montant déclaré et jamais encaissé n'engage PAS le régime des arrhes. On retombe
 * alors sur la facturation ordinaire — sinon une prestation mal configurée offrirait l'absence à
 * tous ses clients.
 *
 * ── ⚠ DEUX CHEMINS D'ENCAISSEMENT, PAS UN ──────────────────────────────────────────────────────
 *
 * Mesuré : `ReserverProcessor` crée la vente à la réservation, et `ConfirmerReservationProcessor`
 * la crée plus tard quand rien n'a été encaissé. Ne traiter que le premier laisserait une
 * réservation confirmée après coup encaisser le prix plein, versement ignoré. Les deux appellent
 * donc le même calcul, porté par `Activite::versementAEncaisser()`.
 *
 * ⚠ **`montant_du` N'EST PAS TOUCHÉ** et reste le prix entier. Il a des lecteurs hors de ce module
 * — le padel le divise par quatre pour partager entre joueurs. Le redéfinir en « solde » aurait
 * changé le partage sans que rien ne le dise. Le solde se calcule : `montant_du − versement retenu`.
 *
 * ⚠ **CE QUI N'EST PAS FAIT** : sur des arrhes, l'établissement qui annule doit au client le DOUBLE
 * de ce qu'il a reçu. Aucun code ne sait faire ce remboursement. Le régime n'est donc appliqué que
 * du côté qui arrange le vendeur, et ça doit être dit plutôt que découvert.
 *
 * Défauts : `none` et `0.00` — rien ne change pour les établissements existants.
 */
final class Version20260905030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Arrhes ou acompte au choix de la prestation, et le montant réellement retenu sur la réservation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE reservation_activite
             ADD nature_versement VARCHAR(16) DEFAULT 'none' NOT NULL,
             ADD versement_montant NUMERIC(10, 2) DEFAULT '0.00' NOT NULL"
        );
        $this->addSql(
            "ALTER TABLE reservation_reservation
             ADD versement_retenu_montant NUMERIC(10, 2) DEFAULT '0.00' NOT NULL"
        );
    }

    public function down(Schema $schema): void
    {
        // ⚠ LA DESCENTE PERD LA TRACE DE CE QUI A ÉTÉ RETENU. Les réservations concernées seraient
        // alors facturées comme si rien n'avait été versé — on réclamerait au client une somme
        // qu'il a déjà payée. Ne descendre qu'après avoir vérifié que la colonne est partout à zéro.
        $this->addSql('ALTER TABLE reservation_reservation DROP versement_retenu_montant');
        $this->addSql('ALTER TABLE reservation_activite DROP nature_versement, DROP versement_montant');
    }
}
